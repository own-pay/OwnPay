# Seven-issues investigation - findings (2026-06-28)

Device under test: Samsung S23 Ultra, Android 16, One UI 8.5. SMS permission granted, monitoring
notification visible, bKash SMS reaches the normal inbox, tested SIM1 + eSIM. So: not a permission /
OEM-autostart / SMS-arrival problem.

## Issue 1 - app does not "read" the bKash SMS  → ROOT CAUSE: empty server filter-rules whitelist

- Mobile capture is CORRECT: `SmsReceiver.kt` (manifest, exported=true, permission=BROADCAST_SMS,
  action SMS_RECEIVED, priority 999) parses + forwards to `SmsBridge`. So the receiver fires.
- The on-device privacy gate is FAIL-CLOSED: if `allowed_senders` is empty → every SMS dropped.
- `allowed_senders` comes from `GET /api/mobile/v1/config/filter-rules`
  (`ConfigController::filterRules`, src/Controller/Api/Mobile/ConfigController.php:58):
  `smsTemplates->forTenant($deviceMid)->listActive()` → `SmsTemplateRepository::listActiveForTenant`
  (line 220): `WHERE status='active' AND (merchant_id IS NULL OR merchant_id = :deviceMid)`.
- So the device only sees templates whose `merchant_id` == its OWN scope, or NULL globals.
- BUT the admin never creates NULL globals, and `SmsTemplateAdminController` (create/saveAnalysis)
  resolves its merchant id with a BUGGY pattern: `$mid = 0; if (getActiveBrandId() !== null) $mid = …`
  - NOT the system-standard `BrandContext::getWriteMerchantId()`. So a template's `merchant_id` can be
  0 (orphan), a specific brand id, or the platform id - and rarely matches the device's scope.
- "All Brands" is a REAL reserved merchant row (`op_merchants.is_platform=1`, id = P via
  `getWriteMerchantId()`/`getPlatformId()`), NOT 0/NULL.
- NET: device whitelist comes back empty → fail-closed → bKash dropped. Same seam as #3/#6.

## Issue 7 - admin shows device "Idle" after a few minutes  → ROOT CAUSE: app sends no heartbeat

- `PairedDeviceRepository::ONLINE_THRESHOLD_SECONDS = 180`; `listWithLiveStatus` computes
  `online = (status='active' AND last_heartbeat >= NOW()-180s)`.
- Server HAS `POST /api/mobile/v1/devices/heartbeat` → `DevicePairingService::heartbeat` →
  `updateHeartbeat` (sets last_heartbeat=NOW()).
- Mobile app NEVER calls it (grep "heartbeat" in mobile-app/lib = 0 matches). `last_heartbeat` is set
  only once, at pairing. After 180s it is stale → admin shows Idle; refresh re-reads same stale value.

## Issue 3 - device paired from "All Brands" not shown under a brand

- Same brand-scoping model. A device paired at All-Brands has merchant_id = P (platform). Brand views
  list `WHERE merchant_id = brandId` (PairedDeviceRepository::listWithLiveStatus tenant branch), so the
  platform device never appears under a brand. (Need admin DeviceController for the exact display fix.)

## Issue 6 - templates should be global (All-Brands only), apply to all brands; remove per-brand create

- Confirmed desired model. Current: templates are brand/platform-scoped via the buggy resolver; per-brand
  create pages exist. Fix dovetails with #1: All-Brands template = the global set; device filter-rules
  must include it for EVERY device.

## Issue 2 - SMS template should not require a gateway

- `op_sms_templates.gateway_slug` + `SmsTemplateAdminController::saveAnalysis` hard-requires it
  (`if ($gatewaySlug==='') flashError('Gateway slug is required')`, line 437). Plain create() also
  carries gateway_slug. Fix: make gateway optional; drop the requirement + the form field coupling.

## Issue 4 - manual gateway create should not ASK for SMS verification (always on)

- TODO: read GatewayController (manual gateway create) - remove the toggle, force-enable.

## Issue 5 - Heuristics (⚡) does not read "Payment Sender Account Regex" (sender_regex)

- `analyze()` → `SmartSmsAnalyzer::analyze($rawSms,$sender)`; `saveAnalysis()` DOES read+persist
  `sender_regex`. So the gap is the analyzer not emitting sender_regex, or the twig JS not mapping it
  into the form field. TODO: read SmartSmsAnalyzer + admin/sms-center/index.twig.

## Proposed fix model (covers 1,3,6 together)

1. Standardize template ownership to `BrandContext::getWriteMerchantId()` (platform id for All-Brands).
2. `ConfigController::filterRules`: include global/platform templates for EVERY device -
   `merchant_id IS NULL OR merchant_id = :platformId OR merchant_id = :deviceMid`.
3. Remove per-brand template create UI; All-Brands is the single template management surface.
4. #7: mobile app posts `/devices/heartbeat` every ~60s while active + on resume/cold-start.

## OPEN - need confirmation before coding #1

Where was the bKash template created (All Brands vs a specific brand) and where was the device paired
(All Brands vs a specific brand)? OR verify directly against the DB if the real-device test pointed at
THIS local Laragon server (read-only query on op_paired_devices + op_sms_templates).
