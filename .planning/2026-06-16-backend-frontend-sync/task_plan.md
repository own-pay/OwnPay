# Task Plan: Backend/Frontend Sync — Find & Fix All Mismatches

> ⏸️ **PAUSED 2026-06-17** for a higher-priority task. **To resume, read `RESUME.md`** in this folder — it has the full done/pending list, env/login notes, and the exact next steps (deeper write-op/checkout sweep + the B3 chart-bars diagnostic). Done+verified so far: sidebar fix, B1 (translation), B2 (global aggregation), B3 labels, B4 (perms label), B5 (checkout raw labels), Invoice PDF, dead-code removal. PHPStan clean, PHPUnit ~535 pass.

## Goal
Find and completely fix all bugs/issues where backend and frontend are out of sync across the ownpay codebase:
- Backend features missing GUI/admin UI.
- Incomplete code: missing routes (api.php/web.php), missing controller methods, missing templates.
- Backend/frontend mismatches. Rule from user: **when mismatched, update the frontend to match the backend.**
Skip nothing. No placeholders/TODOs. Production-ready. Verify with phpstan/lint/tests.

## Constraints
- Windows + PowerShell syntax. PHP 8.3, strict_types everywhere.
- Match existing architecture/patterns (Twig SPA, op- CSS classes, BaseController render helpers).
- No new heavy dependencies. Server-side validation, CSRF, permissions enforced.

## Current Phase
Phase 3 partial done (confirmed fixes shipped + verified). Awaiting user input on scope for runtime/field-level issues.

## Audit outcome (Phase 1 complete)
Wiring is SOUND: 225 route handlers all resolve; 0 missing templates; all frontend URL calls resolve; no orphan service domains. Genuinely incomplete feature found & fixed: **Invoice PDF**. Dead incomplete code removed: **fragment endpoint**. Harmless dead duplicates left in place (reported to user). The `/store` routes are LIVE (used by edit.twig create-mode forms) — verified, not removed.

## Shipped & verified
- Invoice PDF/print: route GET /admin/invoices/{id}/pdf + fixed InvoiceController::pdf() (was passing content to path-based Response::download) + "Print" button in invoices/index.twig.
- Removed dead fragment endpoint (route + DashboardController::fragment + ALLOWED_FRAGMENTS + PermissionMiddleware map entry) — referenced non-existent templates.
- Verified: PHPStan (changed files) OK; twig-cs-fixer OK; PHPUnit 525 pass / 1 skip.

## Phases

### Phase 1: Discovery — build definitive issue list
- [x] Build & run audit_wiring.php: routes -> controller@method existence; render() -> template existence. (CLEAN)
- [x] Scan frontend (templates + public/assets/js) for endpoint calls vs registered routes. (CLEAN)
- [x] Scan sidebar/nav links vs routes; find backend features with no UI entry point. (only Invoice PDF)
- [x] Inverse audit + service-domain inventory. (no orphan domains)
- [x] Aggregate all issues into findings.md with severity + fix decision.
- **Status:** complete

### Phase 2: Triage & decisions
- [ ] Classify each issue: broken-wiring (crash) | missing-template | missing-route | missing-UI | dead-code | mismatch.
- [ ] Decide fix per item (frontend follows backend). Flag any needing user input.
- **Status:** not_started

### Phase 3: Implementation
- [ ] Fix broken wiring (missing methods/templates) — highest priority (runtime crashes).
- [ ] Add missing routes/controllers for incomplete features.
- [ ] Build missing admin UI for backend-only features.
- [ ] Reconcile frontend calls to match backend contracts.
- **Status:** not_started

### Phase 4: Verification
- [ ] Re-run audit_wiring.php — zero broken wiring.
- [ ] `composer analyse` (phpstan) — no new errors.
- [ ] `composer lint:twig` + npm lint (js/css/json) — clean.
- [ ] `composer test` (phpunit) — green.
- **Status:** not_started

### Phase 6: Deep field-level audit (user-requested)
- [ ] audit_templates.php: Twig AST → context vars used per template; token_get_all → controller-provided keys per template; diff vs globals → candidate missing vars.
- [ ] Manually verify EACH candidate (controller+template) before changing. Fix real template/data mismatches (frontend follows backend).
- [x] Form-field vs handler check: all 6 candidates verified non-bugs (JSON/JS senders or dead methods).
- [x] Re-run verification (phpstan/twig/phpunit).
- **Status:** complete — NO field-level mismatches found. Codebase well-aligned.

### Phase 7: Dead-code removal (user-approved) — COMPLETE
- [x] Removed Page\LoginController (file), DashboardController::activities (+ orphaned auditRepo dep/import), ThemeController::customize, DeveloperController::saveLimits/generateKey, FaqController (file) + /admin/faq routes, CurrencyController::updateRates (+ route). All verified unused (no routes/callers/tests/service bindings).
- [x] Removed /admin/faq PermissionMiddleware entry. composer dump-autoload.
- [x] Verified: php -l clean; PHPStan full project OK; twig 88 OK; PHPUnit 525 pass/1 skip.
- **Status:** complete

### Phase 8: Sidebar fix (new request) — COMPLETE
- [x] admin.js: retargeted group toggle to `.op-nav-group`/`.op-nav-item-link` (was `.op-nav-has-sub`/`.op-nav-link`). Primary bug.
- [x] sidebar.twig: removed brand-view hiding (consistent full menu in global + brand); dropped broken /admin/faq item.
- [x] en.json: added menu.payment_intents, menu.refunds, menu.2fa_setup, menu.brand_settings, menu.faq, menu.section_* (7).
- [x] Verified: ESLint(js) OK, twig-cs-fixer(all) OK, json valid+all label keys present. Browser harness: 6 groups, click toggles op-nav-expanded (before:false→true→false), 0 console errors. Both contexts render identical 34 links, no /admin/faq.
- **Status:** complete

### Phase 9: Brand-scoped sidebar (user follow-up) — in_progress
- [x] Re-introduce brand filtering CORRECTLY via single `{% set is_global %}` flag (kept working JS toggle).
- [x] Taxonomy grounded in controller scoping: hide only platform-level in brand view = Brands, Balance Verification, Audit Integrity, Themes, Addons, System Update. Keep per-brand items (Staff, Roles, Fee Rules, Domains, Settings, Gateways, Plugins, all operations) — verified brand-aware in controllers.
- [x] Render verify: GLOBAL=32 links, BRAND=26, hidden=6 (the platform set), no inconsistency.
- [x] User signed in. Live walkthrough done: all global pages load OK; B1 (translation) fixed+verified live; create forms render OK.
- [x] B2 (global aggregation) chosen by user + IMPLEMENTED across 8 operational areas + verified live (All Brands shows aggregated data) + PHPUnit 525 pass + full PHPStan OK.
- [ ] Deeper sweep continuing: settings-tab saves, toggles, checkout pages.
- **Status:** B1+B2 done+verified; deeper interaction sweep in progress.

### Phase 5: Delivery
- [ ] Remove one-off audit script. Update findings/progress. Summarize fixes to user.
- **Status:** not_started

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Build a reflection-based audit script | Route handlers are strings; only runtime/reflection proves wiring. Avoids guessing. |
| Frontend follows backend on mismatch | Explicit user instruction. |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
