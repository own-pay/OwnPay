# Progress Log

## Session: 2026-06-19

### Current Status

- **Phase:** 1 - Setup & audit tooling
- **Started:** 2026-06-19

### Context restoration (done)

- Read prior plans: `2026-06-16-backend-frontend-sync` (+RESUME/findings),
  `2026-06-16-fix-all-missing-admin-gui`, `2026-06-17-ui-ux-mapping`,
  `2026-06-17-neoxa-ui-ux-data-sync`, `2026-06-18-restructure-admin-panel-v2`.
- KEY: neoxa-sync claims to have fixed the Neoxa mismatches but only evidenced SmokeTest(8);
  admin restructure v2 landed afterward. → Doing a FRESH audit of current code.

### Actions Taken

- Created isolated plan `2026-06-19-fix-fe-be-mismatches` (task_plan/findings/progress).

### Actions Taken (audit run)

- Built + ran audit.php (wiring + static template-vars), render_audit.php (full-boot render of
  every GET /admin page, GLOBAL + BRAND views), sanity.php (harness soundness proof),
  endpoint_audit.php (FE URL → route), form_field_audit.php (BE-read vs FE-sent).
- All scripts live in .planning/2026-06-19-fix-fe-be-mismatches/ (delete on completion).

### Test Results

| Test | Expected | Actual | Status |
|------|----------|--------|--------|
| audit.php wiring (228 handlers) | resolve | all resolve | CLEAN |
| render_audit GLOBAL (66 pages) | render | 66 OK / 0 fail | CLEAN |
| render_audit BRAND id=1 (66 pages) | render | 66 OK / 0 fail | CLEAN |
| endpoint_audit (154 FE URLs) | resolve | 0 unmatched | CLEAN |
| form_field_audit (7 flags) | verify | all FE-sent (FP) | CLEAN |
| PHPUnit | ~547 pass | 550 pass, 0 fail | PASS |
| PHPStan L9 | clean | No errors | PASS |

### Conclusion (admin/web phase)

Admin/web clean at every detectable level. User chose "continue full sweep".

### Full-sweep phase (2026-06-19)

- public_render.php: 12 public/checkout GET pages render, 0 fail (txn/invoice tables empty → those
  index pages already covered empty-state in admin run; intent+pay-link rendered with real data).
- Incomplete-code marker grep (TODO/FIXME/stub/not-implemented): 0 real hits in src/.
- OpenAPI spec (docs/v2/api/openapi.yaml) ↔ api.php routes: EXACT 1:1 parity (36 paths). No missing API.
- api_render.php (GET API invoke): found **BUG #1** - SmsParserService DI crash (Api\Mobile\SmsController
  queue+receive). Root cause: untyped constructor + not registered in services.php.
- **FIXED BUG #1**: registered SmsParserService factory in services.php. Verified:
  container_audit 228/0, api_render 18/0, PHPStan L9 clean, PHPUnit 552 pass.
- container_audit.php (build EVERY controller, all verbs): 228/0 after fix.
- find_untyped.php: SmsParserService was the ONLY real unautowirable+unregistered container bug
  (other unautowirable ctors are registered-with-scalars or manual DTOs).
- SmsController receive() handler reviewed vs spec: reads messages[]/sender/encrypted_payload - matches. OK.

### Status (admin/web phase)

Automatable backend sweep COMPLETE. 1 critical bug found + fixed. User then chose "Keep digging backend".

### Deep backend dig (user chose) - 2026-06-19

- headless_audit.php: resolved (constructed) cron runner + all 9 jobs, queue, notifications,
  webhook dispatcher/service/inbound processor, plugin loader/installer/registry → ALL resolve.
  Cron/webhook/plugin/queue wiring is sound. (2 "fails" were class names I guessed wrong, not bugs.)
- Verified documented FE/BE WRITE paths: require_address (PaymentLinkService INSERT/UPDATE),
  brand color/initials/description ($fillable), txn ip_address (fillable + metadata fallback). All complete.
- write_handler_audit.php (execute admin write handlers vs THROWAWAY ownpay_test DB, danger-list
  skips file/network handlers): found **BUG #2** - global-view create → FK 500 (5 handlers).
- **FIXED BUG #2**: AdminPageTrait::requireActiveBrand guard on 5 write paths. VERIFIED:
  write audit GLOBAL 59/0 (was 54/5), BRAND 59/0, PHPStan L9 clean, PHPUnit 552 pass.

### Final status

Exhaustive backend sweep COMPLETE. 2 bugs found + fixed (SMS DI crash [critical], global-view-create
500 [medium]). All guardrails green. Remaining surfaces (visual/CSS/JS/business-logic) need browser.
TODO on handoff: remove seeded rows from ownpay_test (merchant id=1, role id=1); delete audit scripts.

### Errors

| Error | Resolution |
|-------|------------|
| audit.php boot PDOException (plugin route branch hits DB) | load .env + invoke core route closures directly |
| render_audit ArgumentCountError (IIFE) | pass $pdo to closure |
| render harness brand view == global (session_status guard) | session_start() so $_SESSION honored |
