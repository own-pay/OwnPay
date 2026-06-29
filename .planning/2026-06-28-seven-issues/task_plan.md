# Task plan - 7 user-reported issues (2026-06-28)

Goal: fix 7 issues spanning the mobile app (runtime) and the admin panel (PHP). User priority:
RUNTIME FIRST (#1 + #7), then admin (#2–#6). Issue-6 model = templates global at All-Brands only.

## Root-cause summary (see findings.md)

- #1 SMS not read  → server filter-rules returns EMPTY sender whitelist (brand-scoping mismatch). Mobile
  capture itself is correct. Tied to #3/#6.
- #7 device "Idle" → mobile app never calls `/devices/heartbeat`; server online-window = 180s.
- #3/#6 → All-Brands (platform merchant) vs brand scoping; templates not global; per-brand create exists.
- #2 → SMS template hard-requires a gateway. #4 → manual gateway create asks for SMS verification.
  #5 → Heuristics doesn't populate sender_regex.

## Phases

- [complete] P0 Investigate & confirm root causes (DONE for 1,3,6,7; TODO 2,4,5 forms).
- [complete] P1 #7 heartbeat (mobile): DeviceHeartbeat (60s + immediate + on resume) + DI + main wiring
  - 3 tests. analyze clean, 138 tests, release APK built.
- [complete] P2 #1 capture (server + mobile robustness): SmsTemplateRepository::listActiveForDevice
  (NULL+own+platform); ConfigController::filterRules uses it; migrated orphan template 0→platform(2)
  (verified: device@2 whitelist now returns 'bkash'); mobile repo no longer serves/caches an EMPTY
  whitelist (auto-recovers on next drain instead of 24h dark) + 2 tests. NOTE: admin-visibility of the
  migrated template needs the #6 resolver fix (getWriteMerchantId) - deferred to P2b/#6.
- [complete] P3 #2 template gateway optional: create-modal + edit.twig select no longer `required`
  (- None -); saveAnalysis requires sender_pattern (not gateway), no gateway fallback.
- [complete] P4 #5 Heuristics sender_regex: SmartSmsAnalyzer SENDER_ACCOUNT_PATTERNS → sender_account +
  suggested_regexes['sender_regex']; JS already wired. Verified on a sample bKash SMS.
- [complete] P5 #4 manual gateway SMS-verification always-on: GatewayController::buildGatewayRecord
  forces sms_verification=1; checkbox removed from create-manual + edit-manual twigs (→ "Always on").
- [complete] P6 #6 global templates: templateOwnerId=platform; index lists platform templates + passes
  can_manage_templates=isGlobalView; create/edit/delete/saveAnalysis guarded with requireGlobalView;
  twig hides New/Edit/Delete/sandbox/modal + --solo layout in brand view. #3 brand device view includes
  platform devices (PairedDeviceRepository::listWithLiveStatusForBrand + service + admin controller).
- [complete] P7 Verify: 6 PHP files `php -l` clean; 4 twigs Twig-parse clean; analyzer + #3 query +
  template-visibility verified vs live DB. Mobile (138 tests, release APK) already current.

## STATUS: ALL 7 ISSUES COMPLETE (2026-06-28). On-device confirmation (capture, heartbeat-online) is

## device-boundary - user installs the rebuilt arm64 APK + tests. Admin changes are live (PHP/Twig)

## Decisions

- D1: #1 is config/scoping, not a capture bug - fix server filter-rules + template ownership, not the
  Android receiver.
- D2: heartbeat = dedicated periodic post (every 60s, < 180s window) + on resume + cold-start.

## OPEN (blocking P2 specifics)

- Q: exact merchant_id of the user's device + bKash template (ask user OR read local DB if same server).
