# Task Plan: User Flow / Kernel / Framework Audit & Polish

## Goal
Audit and fix the OwnPay framework: user flow/UX bugs, optimization review, Kernel structural quality (incl. hardcoded HTML question), overall framework polish — all fixes verified by tests.

## Current Phase
Phase 5

## Phases

### Phase 1: Reconnaissance
- [x] Read Kernel.php fully
- [x] Read ARCHITECTURE.md
- [x] Read public/index.php, Router, controllers, repositories
- [x] Map user flows: login, checkout, invoice, payment link
- [x] Check stray root files (confirmed curl artifacts, untracked)
- **Status:** complete

### Phase 2: Baseline verification
- [x] phpunit: OK 525 tests / 1693 assertions / 1 skipped
- [x] phpstan: clean (level from phpstan.neon)
- **Status:** complete

### Phase 3: Bug hunt in user flows
- [x] Checkout flow — found stuck callback_processing lease bug + error leak
- [x] Login/landing — smoke 200 OK
- [x] Invoice/payment-link flow — 500 reproduced (CSP header bug)
- [x] Root cause: CSP header 16KB > mod_fcgid 8KB limit (see findings.md)
- **Status:** complete

### Phase 4: Kernel & framework structural review
- [x] Kernel SRP: extract ErrorPageRenderer (HTML fallbacks are by-design, keep self-contained)
- [x] Maintenance $reason unescaped — fix with htmlspecialchars
- [x] Maintenance whitelist prefix matching — tighten /admin, /login
- [x] Performance: SettingsRepository lacks memoization — add cache
- **Status:** complete

### Phase 5: Fixes, polish & delivery
- [x] Fix 1: SecurityHeadersMiddleware CSP scoping + 7500B failsafe — VERIFIED 500->200
- [x] Fix 2: guarded callback_processing lease release (both checkout controllers) + label/branch
- [x] Fix 3: generic client error messages (2 leak sites removed)
- [x] Fix 4: Kernel — ErrorPageRenderer extraction, escaped maintenance reason, shared whitelist
- [x] Fix 5: SettingsRepository key+group memoization w/ invalidation + flushCache()
- [x] Fix 6: stray artifacts deleted + gitignored; test credential echo removed
- [x] Fix 7: CheckoutPresentationTrait (loadBrand + statusLabel) consolidates both controllers
- [x] Fix 8 (found during verify): MaintenanceMiddleware ignored Kernel whitelist —
      blocked /login + /checkout during maintenance. Now single source of truth
      (PASSTHROUGH_PREFIXES const). Verified end-to-end with live maintenance lock.
- [x] phpunit 525 OK / phpstan clean / twig lint 79 files clean
- [x] Apache smoke: all 8 key routes correct (200/404/302 as expected)
- [x] ARCHITECTURE.md updated (Kernel error pages, maintenance whitelist, CSP sourcing,
      callback lease, settings memoization)
- **Status:** complete

## Constraints
- CLAUDE.md: no placeholders/TODOs, production-ready, KISS, don't overcomplicate.
- Hardcoded HTML in Kernel error fallbacks is BY DESIGN (works when Twig/DB are down) — keep self-contained.
- Working tree has many uncommitted gateway changes from prior session — do not revert or touch unrelated files.
- phpstan level 9 + phpunit must stay green.

## Decisions Made
| Decision | Rationale |
|----------|-----------|

## Errors Encountered
| Error | Resolution |
|-------|------------|
| init-session.ps1 path wrong (.agent vs .claude) | Used .claude\skills\... path |
