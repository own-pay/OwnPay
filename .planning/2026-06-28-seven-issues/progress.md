# Progress log - 7 issues

## 2026-06-28

- Clarified scope with user (AskUserQuestion): runtime first (#1+#7); templates global-only (#6);
  device = Samsung S23 Ultra / Android 16 / One UI 8.5; permission+notification+inbox all OK.
- Read mobile capture (SmsReceiver.kt, AndroidManifest) → receiver correct, fires.
- Read backend: ConfigController::filterRules, mobile DeviceController, SmsTemplateRepository,
  DevicePairingService, PairedDeviceRepository, TenantScope, SmsTemplateAdminController, BrandContext.
- CONFIRMED: #1 = empty filter-rules whitelist (brand-scoping mismatch); #7 = no heartbeat from app
  (180s online window). #3/#6 = same scoping seam. 2/4/5 = admin form fixes (forms not yet read).
- Wrote findings.md + task_plan.md.
- DB read-only verified the EXACT data: merchants 1=Own Pay, 2=All Brands(platform); active device 4 at
  merchant_id=2; ONLY template (id2) orphaned at merchant_id=0, sender 'bkash'. → device@2 query
  (NULL or =2) never matches template@0 → empty whitelist → drop. #1 root cause proven.
- DONE #7 (mobile): DeviceHeartbeat (60s + immediate + on resume), DI + main wiring, 3 tests. analyze
  clean, 136 tests pass.
- DONE #1 (server code): SmsTemplateRepository::listActiveForDevice (NULL + own + platform subquery);
  ConfigController::filterRules now uses it. php -l clean on both.
- BLOCKED #1 (data): orphan migration `UPDATE op_sms_templates SET merchant_id=<platformId> WHERE
  merchant_id=0` denied by auto-mode (read-only authorized only). Need user OK or they run it.
- NOTE: device cached the empty whitelist (24h refetch) → user must RE-PAIR to pick up corrected rules.
- DONE #1 data: migration ran (template id2 → merchant_id=2); verified whitelist query returns 'bkash'.
- DONE #1 mobile robustness: NetworkFilterRulesRepository no longer serves/caches an EMPTY whitelist;
  +2 tests. analyze clean, 138 tests, release APK rebuilt (arm64 19.0MB).
- Read admin batch: SmsTemplateAdminController, sms-center/index.twig, SmartSmsAnalyzer, GatewayController,
  AdminPageTrait (has requireGlobalView/isGlobalBrandView), admin DeviceController, sms-center.js.
  KEY: #5 is backend-only (JS already wires suggested_regexes.sender_regex; analyzer never emits it).
  #4 = GatewayController::buildGatewayRecord:573 sms_verification toggle. #6 mirror GatewayController's
  getPlatformId/requireGlobalView pattern. #3 = admin DeviceController index scopes WHERE merchant_id=brandId
  → platform device (mid 2) invisible under a brand; fix = brand view shows own + platform devices.
- NOW implementing admin batch (#5 analyzer, #4 gateway, #6 templates+twig, #2 gateway-optional, #3 device list).
- DONE admin batch: #2 (gateway optional), #4 (sms_verification forced on + checkbox removed), #5
  (analyzer sender_regex), #6 (global templates + requireGlobalView guards + twig hides mgmt in brand
  view), #3 (brand device list includes platform devices).
- VERIFIED: 6 PHP files php -l clean; 4 twigs Twig-parse clean (scratchpad twiglint.php); analyzer emits
  sender_regex for sample bKash SMS; DB query confirms #3 (brand-1+platform → device 4) and #6 (template
  id2 visible at platform). ALL 7 ISSUES COMPLETE.
- Pending = on-device user verification (install rebuilt arm64 APK; capture + online status).

## 2026-06-29 - LIVE on-device debugging (adb, real S23 Ultra R5CWB1Z12VR / Android 16)

- New user report: "click refresh icon → goes to pair-device page."
- Ran `flutter run` on device (note: it UNINSTALLED the vc-2001 release to install debug vc-1 → wiped
  secure storage → had to re-pair → device 6). Future device debug runs: use `--build-number 2002+`.
- VERIFIED LIVE: #1 `[OWNPAY] effectiveRules: senders=[bkash]` (whitelist correct). #7 device 6
  last_heartbeat advanced past paired_at (online) - AFTER fixing a real #7 bug:
  - **#7 bug**: app POSTed `/devices/heartbeat` but the route is `/devices/heartbeats` (plural,
    config/routes/api.php:44) → 404 → never online. Fixed the mobile URL.
- **NEW BUG /pair redirect - ROOT-CAUSED + FIXED**: log showed `token-refresh OK` → `REAUTH`. Instrumented
  JwtAuthMiddleware (server) → the token is VALID (iss/aud/mid/device all pass; `/notifications` returns
  200). So the 401 that triggered reauth was a TRANSIENT post-refresh retry blip (startup burst). The
  AuthInterceptor escalated a 401-after-successful-refresh to AuthFailure → reauthRequired=true →
  permanently stuck on /pair (flag only clears on re-pair). FIX: AuthInterceptor marks a post-refresh
  retry 401 with extra['authRecovered']=true; ApiClient._mapError maps that to a retryable ServerFailure
  (NOT AuthFailure). So re-auth now fires ONLY when the refresh ITSELF fails (truly dead session).
  +1 regression test (test/network/api_client_test.dart). analyze clean, 139 tests pass.
- Reinstalling fixed build on device for user verification. Temp diagnostics removed; server jwt_debug
  instrumentation removed.
