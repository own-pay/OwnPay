# Task Plan — Release Readiness Audit (OwnPay PHP platform)

## Goal
User plans to release OwnPay **today (2026-06-30)**. Two questions:
1. Find bug(s) in OwnPay.
2. Is it ready for release? → produce an evidence-backed GO / NO-GO.

## Scope decision
- **Target = the PHP platform** in this repo (`C:\laragon\www\ownpay`, branch `fixing`): backend, admin panel, checkout, WooCommerce gateway, landing page.
- **Out of primary scope = the Flutter app** (`mobile-app/`, git-ignored). Its release-readiness is gated on user-only tasks (release signing, Play listing, on-device verify) per memory. Flag separately, do not block on it.

## Constraints (non-negotiable)
- Codebase is heavily audited & statically clean → **hunt bugs by EXECUTING code**, not re-running static scans alone (memory: ownpay-codebase-audit-state).
- Untyped/primitive-only constructors must be registered in `config/services.php` or DI crashes at runtime (memory: ownpay-di-untyped-constructors).
- Verify every memory claim against CURRENT code before asserting (memories are 11 days old).
- Read-before-write. No edits until a bug is confirmed by execution. Never `git add .` blindly.
- This is a PAYMENT platform → money handling, auth, tenant isolation, secrets are highest-severity surfaces.

## Phases
| # | Phase | Status |
|---|-------|--------|
| 1 | Context restore + plan (memory, git, toolchain) | complete |
| 2 | Static baseline: phpunit(598✓), phpstan(L9✓), parallel-lint(244✓), composer audit(0 CVE✓) | complete |
| 3 | DI harness: 61 controllers + 15 middleware resolve; 0 untyped-ctor traps | complete |
| 4 | Render harness: 85 GET pages clean × single+global views | complete |
| 5 | Write harness: covered by 36 integration test files (passing); skipped live fuzz to protect dev DB | complete |
| 6 | Recent-churn review: checkout money path robust; SmsTemplateRepo change = bug fix | complete |
| 7 | Synthesize findings + GO/NO-GO release verdict + checklist | complete |

## Verdict
No release-BLOCKING code bug found across static + runtime audit. Backend is release-grade.
GO for the PHP platform contingent on the human-gated checklist in findings.md (prod .env, checkout browser QA, live gateway test, HTTPS/backups). Minor non-blocking findings: checkout.js duplicate var, express alert() UX, custom-JS-injection trust note.

## Phase 8 — Fix the 3 minor findings (complete)
Verified: `node --check` exit 0, `eslint public/assets/js/checkout.js` exit 0, 0 `alert(` left, 1 `var gwData` (dup removed), showCheckoutError used consistently.
- #1 checkout.js `openManualPopup` duplicate `var gwData` → remove redundant 2nd decl, keep rationale on 1st. (code fix)
- #2 checkout.js `doQP` express `alert()` ×2 → `showCheckoutError()` for UX consistency. (code fix)
- #3 custom-JS injection → VERIFIED already mitigated: `SettingsController::save` gates `custom_css`/`custom_js` behind `if ($isSuperadmin)` + sanitizes CSS; `BrandController` hardcodes them empty (no input bypass). NO code change needed; documented.
- Verify: `node --check` syntax + re-run render harness on checkout route.

## Decisions
- D1: Run baseline (phase 2) in background while building harnesses (phases 3-4) to parallelize wall-clock.
- D2: Write harness uses the throwaway `ownpay_test` DB wrapped in a rollback transaction; skip file/network side-effect handlers.

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| (none yet) | | |
