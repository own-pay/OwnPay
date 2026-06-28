# Task Plan: Multi-Brand Device Connection & Premium Pairing UX

## Goal
Make companion-device SMS verification multi-brand aware (all-brands device verifies every brand; brand device verifies only its brand) and give the admin pairing flow a real-time "device connected" moment plus live, refreshable device status with revoked-freeze — backend + admin panel only, no Flutter, no schema change.

## Spec (approved contract)
docs/superpowers/specs/2026-06-27-multibrand-device-connection-design.md

## Current Phase
All phases complete (manual browser smoke is user-side)

## Phases

### Phase 1: Discovery & Spec
- [x] Map existing pairing / SMS-verification / admin-modal code
- [x] Resolve the 4 design decisions with the user (scope, implicit-by-view, revoked-freeze, resolve-on-match)
- [x] Write + self-review the design spec; user approved
- [x] Record prior art (findPendingMatchGlobal orphaned by the 2026-06-19 tenancy refactor)
- **Status:** complete

### Phase 2: Multi-brand SMS verification (backend)
- [x] Inject BrandContext into `SmsVerificationJob` (auto-wired; getPlatformId)
- [x] Branch `run()`: platform-scoped rows match globally — `forAllTenants()->findByProviderTrxId()` (trx_id), then `findPendingMatchGlobal()` (amount+gateway, only when received_at present)
- [x] On match, resolve brand = transaction.merchant_id; new `SmsParsedRepository::rebindToBrand()` (updateScoped forbids merchant_id change by design); complete + ledger under resolved brand inside existing db transaction
- [x] Brand-scoped rows: behavior unchanged (resolvedBrand == smsMerchantId, no rebind)
- [x] Tests (tests/Integration/AllBrandsDeviceSmsVerificationTest.php): global trx_id match, single global amount match, ambiguous->refused, brand-scoped isolation. 4/4 green
- [x] Verified: full suite 595 green; phpstan L9 clean on src
- **Status:** complete

### Phase 3: Admin endpoints & repositories
- [x] `generateOtp()` returns `generated_at` (DB NOW(6), comparable to paired_at)
- [x] New `GET /admin/devices/pairing-status?since=` -> newest active device for write-brand since timestamp (since validated)
- [x] New `GET /admin/devices/statuses` -> per-device live status (active-brand scoping; 0/null=all) with derived `online`
- [x] Repo: `findNewestActiveSince()`, `listWithLiveStatus()` (online via DB clock), `ONLINE_THRESHOLD_SECONDS=180`; service delegators
- [x] Registered routes in config/routes/web.php (admin group)
- [x] Tests (tests/Integration/DeviceLiveStatusTest.php): statuses online/idle/revoked-frozen, pairing detect, generated_at. 3/3 green
- [x] Verified: full suite 598 green; phpstan L9 clean
- **Status:** complete

### Phase 4: Admin UI (pairing modal + live status)
- [x] Pairing modal Phase 3 "Device connected" success state (template) + waiting indicator
- [x] devices.js: capture generated_at; poll pairing-status every 3s during Phase 2; stop on connect/expiry; Phase 3 + finish-reload
- [x] Device list: live status dot (Online/Idle/Revoked), Refresh button, ~20s auto-refresh (paused when tab hidden); revoked frozen
- [x] CSS for success/pulse/waiting/refresh (devices.css, ?v=022 bump)
- [x] index() lists ALL devices + online (revoked show frozen)
- [x] XSS-safe: no innerHTML (DOM build + DOMParser for trusted QR) — passed security hook
- [x] Verified: Twig template compiles (real env), JS `node --check` OK, full suite 598 green, phpstan L9 clean
- [~] Live authenticated browser flow (modal flip + dots polling) — needs logged-in app at ownpay.test (DomainMiddleware gates /admin to APP_DOMAIN); flagged for user/Chrome-MCP verification
- **Status:** complete

### Phase 5: Testing & Verification
- [x] `php vendor/bin/phpunit` green (598 tests, exit 0)
- [x] `phpstan` clean at project level (L9)
- [x] Twig template compiles; devices.js `node --check` OK
- [~] Manual smoke (generate OTP -> pair -> modal flips; revoke -> frozen): user-side, needs logged-in app at ownpay.test
- **Status:** complete

### Phase 6: Documentation & Delivery
- [x] Updated ARCHITECTURE.md section 5.2 (all-brands resolve-on-match, money-safe global matching, real-time pairing + revoked-freeze). AGENTS.md reviewed — it's a rules-index, no change needed.
- [x] Final review vs spec: all 5 goals met, non-goals respected, D1-D4 honored.
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Backend + admin only; Flutter untouched | Multi-brand scope is decided server-side; the app pairs identically regardless |
| Brand scope implicit by pairing view | Reuses BrandContext getWriteMerchantId; no new column/toggle |
| Resolve-on-match in cron (Approach A) | Smallest coherent change; stays on existing cron matching path; money-safe |
| Reuse findPendingMatchGlobal, gate to platform-scoped only | It already enforces the single-match ambiguity guard; gating to all-brands devices is the precision the 2026-06-19 refactor dropped |
| Revoked = frozen (display) | SMS rejection already enforced by JwtAuthMiddleware H-04; this adds the status-freeze guarantee |
| Polling, not websockets | Matches existing poll-based architecture + strict checkout CSP |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
|       | 1       |            |

## Notes
- Re-read this plan before each phase; update status pending -> in_progress -> complete.
- After every edit to this file, re-run attest-plan.ps1 to re-lock the hash.
- No placeholders/stubs in delivered code (root CLAUDE.md section 5.2).
