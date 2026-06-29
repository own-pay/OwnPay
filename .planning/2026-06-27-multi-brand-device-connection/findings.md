# Findings & Decisions

## Requirements (from user)

- Mobile app connectable from all brands; an all-brands device verifies SMS for every brand.
- A device connected from a specific brand verifies only that brand (never other brands).
- A brand is verified by BOTH the all-brands device AND its own brand device.
- Pair-new-device admin screen auto-updates to a "device connected" message (premium, real-time).
- Device connection status shown real-time with a refresh option.
- Revoked device: status report freezes (is not updated there).

## Research Findings (verified in code)

### Pairing

- `src/Service/Device/DevicePairingService.php` - generatePairingOtp / pairDevice / validateRequest / revoke / listDevices.
- `src/Controller/Admin/DeviceController.php` - index, generateOtp (`POST /admin/devices/generate-otp`, QR via chillerlan), revoke, bulkRevoke, checkStatus (`GET /admin/devices/check-status` returns {paired:bool}), saveSettings.
- `src/Controller/Api/Mobile/DeviceController.php` - pair/heartbeat/revoke/refresh/status; `op_paired_devices` bound to ONE merchant_id.
- generateOtp uses `BrandContext::getWriteMerchantId()` -> platform id in All-Brands view, active brand id otherwise. So all-brands pairing already stores device under platform id; brand pairing under that brand. (Implicit-by-view scope already works at pairing time.)

### SMS verification (the gap)

- `src/Controller/Api/Mobile/SmsController.php::receive` reads merchant_id from JWT, calls `SmsParserService::processBatch($deviceId, $mid, ...)`; rows stored in op_sms_parsed with that merchant_id.
- `src/Cron/SmsVerificationJob.php::run` groups pending op_sms_parsed by merchant_id, matches `forTenant($mid)->findByProviderTrxId()` then `findPendingMatch($mid, amount, gateway, receivedAt)`. STRICTLY per-tenant.
- GAP: an all-brands device's SMS is stored under platform merchant_id -> matched against platform's (empty) transactions -> verifies nothing.

### Already-built groundwork (currently UNUSED)

- `TransactionRepository::findByProviderTrxId(string)` (line ~94) does an UNSCOPED lookup when tenantId is null (i.e. via forAllTenants()).
- `TransactionRepository::findPendingMatchGlobal(amount, gateway, receivedAt)` (line ~861) EXISTS and ALREADY enforces the money-safety guard: with a received_at window, returns a row ONLY when exactly one pending tx matches across all brands (count !== 1 -> null). Defined but called NOWHERE today.
- `BrandContext::getPlatformId()` (line ~150) resolves platform-owner id via is_platform=1 (lazy, never hard-coded).

### Prior art (why findPendingMatchGlobal is orphaned)

- Plan `.planning/2026-05-28-fix-cross-tenant-sms-matching` ADDED findPendingMatchGlobal (tight time window + ambiguity abort) and claims it refactored SmsVerificationJob::run to use it.
- Current SmsVerificationJob does NOT call it - the job was later rescoped to strict per-tenant matching, most likely during `.planning/2026-06-19-brand-all-brand-tenancy`.
- Conclusion: our task RE-CONNECTS global matching, but gated to platform-scoped (all-brands) devices ONLY - the precision the earlier broad version lacked.

### Admin modal / status UI

- `templates/admin/devices/index.twig` - pairing modal has Phase 1 (generate) + Phase 2 (code/QR/timer); NO connected state.
- `public/assets/js/pages/devices.js` - fetchNewCode + countdown + bulk-select; NO polling; never calls check-status.
- Device list table renders status badge + last_heartbeat + paired_at; no live refresh.
- `JwtAuthMiddleware` (H-04) already denies revoked devices at the API (ARCHITECTURE section 5.2).
- Routes: config/routes/web.php lines ~229-235 (admin\\DeviceController endpoints).

### Phase 2 test harness (verified)

- `tests/Integration/SovereignArchitectureTest.php` is the template: extends `Tests\Integration\IntegrationTestCase`, `static::$dbAvailable` guard, builds container from config/services.php, overrides `Database::getInstance()`, gets `SmsVerificationJob` from container, seeds via raw SQL into op_transactions/op_sms_parsed/op_merchants (explicit IDs 99991/99992), runs `$this->job->run()` -> ['matched','failed','total'], asserts DB state. Manual DELETE cleanup in setUp/tearDown (suggests NO auto-rollback - job's internal db->transaction commits).
- **Contract to PRESERVE:** `testGlobalSharedDeviceSmsRoutingAndContextAlignment` puts a parsed SMS under Brand 1 (99991, a real brand) with a matching pending tx in Brand 2 (99992) and asserts matched=0, failed=1, tx stays pending, sms merchant_id unchanged, NO ledger. i.e. brand-scoped devices must stay isolated. My platform-scoped branch must NOT alter this (99991/99992 != platform id).
- `AdminApiSecurityTest::testSmsVerificationJobDoesNotCrossMerchantBoundaries` - same isolation contract; must stay green.
- "all-brands device" identity = `op_paired_devices.merchant_id == BrandContext::getPlatformId()` (pairing from All-Brands view writes platformId via getWriteMerchantId). NULL-merchant devices exist only as a legacy repo-fallback concept (testGlobalDeviceRegistrationAndFallbackLookup inserts one manually); the pairing flow never produces NULL. Gate the new global matching on `== platformId`.
- op_sms_parsed.merchant_id for an all-brands device's SMS = platformId (SmsController reads merchant_id from JWT set at pairing). So `run()`'s DISTINCT merchant_id list will contain platformId; branch there.

### Phase 3 facts (verified)

- op_paired_devices: `paired_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` (auto-set at pairing), `last_heartbeat DATETIME(6) NULL`, `status ENUM('active','revoked','inactive')`, `device_id` UNIQUE.
- PairedDeviceRepository: `listActive()` = status='active' ONLY; `listAllForMerchant()` = all statuses. The live-status endpoint must use ALL statuses (so revoked shows frozen). Both are tenant-scoped via TenantScope.
- DateHelper: now(), nowMicro() ('Y-m-d H:i:s.u'), iso(). For generated_at I capture the DB clock (NOW(6)) so it is comparable to DB-set paired_at (avoid PHP/DB skew).
- Controller test pattern (BrandNotificationSettingsTest): build container from services.php, override Database, `$c->get(Controller)`, set `$_SESSION` (auth_user_id, auth_merchant_id, active_brand_id, brand_view_mode='single', is_superadmin), call `controller->method(new Request($query,$post))`, assert `$resp->getStatusCode()` + `json_decode($resp->getBody(), true)`.
- Request(query, post, server, ...); `query('since')`. Response::json(data,status); getStatusCode(); getBody() (JSON string).
- BrandContext::resolveFromRequest resolves from req attr -> `$_SESSION['active_brand_id']`/auth_merchant_id (validates existence) -> first brand. getWriteMerchantId = platformId in global view else active brand. Brand 1 exists in the test DB (used by BrandNotificationSettingsTest).

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Approach A: resolve-on-match in cron | Smallest change, stays on cron path, money-safe |
| Reuse findPendingMatchGlobal | Already has the ambiguity guard; just gate to platform-scoped rows |
| No schema change | Reuse op_paired_devices + op_sms_parsed columns; rebind merchant_id on match |
| Polling for real-time | Matches poll-based architecture; strict checkout CSP |

## Issues Encountered

| Issue | Resolution |
|-------|------------|
| Write tool blocked on scaffolded plan files | Read each file before overwriting (harness requirement) |

## Resources

- Spec: docs/superpowers/specs/2026-06-27-multibrand-device-connection-design.md
- ARCHITECTURE.md section 5.2 (Companion Device Pairing & JWT), section 4.11 (All-Brands platform scope)
- mobile-app/HANDOFF.md (Flutter state - out of scope here)
