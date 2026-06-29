# Task Plan — Mobile UI overhaul + sync fix

**Plan ID:** 2026-06-29-mobile-ui-sync-overhaul
**Started:** 2026-06-29
**Device:** real Samsung S23 Ultra R5CWB1Z12VR / Android 16 (adb attached; `flutter run` task bkk56bjlo).

## Goal
Deliver 5 user-requested changes to the OwnPay Console mobile app, production-ready, zero stubs,
privacy/security invariants intact:
1. Fix "3 sms failed to sync".
2. Make captured SMS body readable in-app (owner-only, decrypt-on-demand).
3. Settings: show whitelisted senders with per-sender disable toggle + "sync from admin panel".
4. Bottom mobile nav: Home, Activity, Refresh, Settings.
5. Redesign Pair + Permission screens to match the provided mockups (dark/teal).

## Constraints (non-negotiable — mobile CLAUDE.md §2)
- Privacy gate FAIL-CLOSED; never log SMS bodies/tokens/AES keys/decrypted payloads.
- Queue stays ciphertext-only; secrets only in Keystore.
- Money never a double. ISO-8601 w/ tz. No stubs/TODO. `flutter analyze` clean + tests pass = done.
- Local sender-disable may only REMOVE senders from the gate (stricter), never add.

## Phases
| # | Phase | Status |
|---|-------|--------|
| 0 | Explore screens + pipeline; diagnose "3 sms failed" (server /sms + live logs) | complete |
| 1 | Raise 2 blockers (SMS-body approach, theme scope); lock decisions | complete |
| 2 | Fix sync — Bug A (envelope) + Bug B (dataRepo tenant scope) + middleware 401-masking — ALL FIXED, device-verified | complete |
| 3 | SMS body readable — decrypt-on-demand in audit (owner-only) | complete |
| 4 | Settings — sender whitelist toggles + force-refresh ("sync from admin panel") | complete |
| 5 | Bottom nav shell (StatefulShellRoute: Home/Activity/Settings + Refresh action) | complete |
| 6 | Redesign Pair + Permission screens (+ dark theme) + rebuild Home (mockup #1) | complete |
| 7 | Verify: analyze CLEAN, 149 tests PASS, on-device UI confirmed; docs updated | complete |
| 8 | LIVE Bug B confirm → ROOT-CAUSED (dataRepo scope + middleware mask) → FIXED → device-verified (4 rows Confirmed) → diags removed | complete |
| 9 | User Qs: API coverage audit (9/12) + APP_NAME→stable JwtService::ISSUER decoupling (43 PHP tests pass) | complete |

## Decisions (LOCKED 2026-06-29)
- D1 (SMS body): **Tap-to-reveal** — audit row → detail sheet; decrypt-on-demand from queue envelope
  using the per-device AES key; plaintext in MEMORY ONLY (never persisted, never logged). Update SECURITY.md.
- D2 (theme/scope): **Full rebuild** — app-wide cohesive DARK theme (force dark) matching the mockups
  + rebuild Home dashboard to mockup #1 (Active Host Node card, monitoring block, stat tiles, recent
  payments) + redesign Pair + Permission + add bottom nav.
- D3 (Refresh nav item): ACTION (sync now + refresh current tab), not a route.
- D4 (local sender disable): on-device subtractive override; gate drops disabled senders (never adds).
- D5 (#1 Bug A): server `SmsController::receive` batch → `apiSuccess(['results' => $results])` (contract-align).
- D6 (#1 stuck rows): manual "Retry now" resets failed rows so a user-initiated retry re-posts (→ duplicate→approved).

## Mockup palette (from screenshots)
- bg ~ #0B0F1A (near-black navy); surface/card ~ #141A28; outline ~ #232B3A.
- primary/accent teal-mint ~ #14E0BD (buttons, pills, overlines); text hi #F2F5FA, muted #8A93A6.
- success/synced = teal-green; pending = amber #E0A33E; failed = red/pink #F0556B.
- overlines: teal, UPPERCASE, letter-spaced, small. Gateway badges = dark pill + bold slug.

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| (none yet) | | |

## Notes
- StatefulShellRoute keeps Pair/Disclosure OUTSIDE the shell (no bottom bar on those).
- `flutter run` device build must use `--build-number 2002+` to avoid version-downgrade wipe.
