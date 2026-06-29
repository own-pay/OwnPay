# Progress Log

## Session 2026-06-16

### Recon done

- Read web.php (340 lines), api.php (67 lines), Router.php, Kernel.php, sidebar.twig, DashboardController fragment/reports/activities/myAccount, composer.json.
- Confirmed `templates/admin/fragments/` missing while DashboardController::fragment() references it.
- Glob inventories captured: 65 controllers, ~90 templates.
- PHP 8.3.28 / Composer 2.8.11 confirmed.

### Audits run (3 one-off PHP scripts, since deleted)

- audit_wiring.php: 225 handlers OK, 0 missing classes/methods/templates. Only dynamic render = dead fragment endpoint.
- audit_calls.php: all frontend URL calls resolve (apparent misses = normalizer artifacts, verified). Unrouted methods = Invoice PDF (real) + dead dups + delegated edit() methods.
- audit_inverse.php: admin routes w/ no frontend trigger = dead dups + /store aliases (later found LIVE via edit.twig ternary actions).

### Fixes shipped

- Invoice PDF/print feature wired + bug fixed; Print button added.
- Dead fragment endpoint removed (route + method + const + perm map entry).

### Verification

- php -l clean; PHPStan (changed files) OK; twig-cs-fixer OK; PHPUnit 525 pass, 1 skip.

### Decision

- Wiring is sound; user expects "many" issues but static/wiring audit surfaces few. Asked user → chose Deep field-level audit + Remove dead code.

### Phase 6 - Deep field-level audit (DONE, CLEAN)

- audit_templates.php (Twig AST used-vars vs controller keys+globals): only partials w/ inherited context flagged. Zero real missing-var bugs across all admin pages.
- audit_forms.php (controller post reads vs senders): 6 candidates, all verified non-bugs (JSON/JS senders) or dead methods.
- All custom Twig filters (format_bytes, datetime, money, truncate, slug, time_ago) registered. No unregistered filter/function bugs.

### Phase 7 - Dead-code removal (DONE)

- Deleted files: src/Controller/Page/LoginController.php, src/Controller/Admin/FaqController.php.
- Removed methods: DashboardController::activities (+auditRepo dep/import), ThemeController::customize, DeveloperController::saveLimits+generateKey, CurrencyController::updateRates.
- Removed routes: /admin/faq, /admin/faq/save, /admin/developer/save-limits, /admin/developer/generate-key, /admin/currencies/update-rates. Removed /admin/faq perm entry. composer dump-autoload.

### Final verification (full working tree, incl. pre-existing WIP)

- php -l all changed: clean. PHPStan full project: No errors. twig-cs-fixer: 88 files OK. PHPUnit: 525 pass / 1 skip.

### Notes

- Working tree had large pre-existing uncommitted WIP (122 files) before this session - my footprint is small/precise.
- Out-of-scope: leftover debug fwrite in tests/Integration/FinancialLeakageAuditTest.php:329-330 (test-only). Flagged, not changed.
- Audit scoped to admin/* templates per user emphasis; checkout/page/email parsed clean but not field-audited.
- All audit scripts deleted (audit_wiring/calls/inverse/templates/forms).

### Follow-up (user: "do those")

- 1. Removed leftover debug block in tests/Integration/FinancialLeakageAuditTest.php:329-340 (fwrite STDERR + DB-lock dump). Test green (5/21), no more [DEBUG] output.
- 1. Extended field audit to checkout/public/email/error (audit_templates_all.php, since deleted): CLEAN - no missing-var mismatches. checkout.twig/checkout-status.twig vars provided via dynamic $data renderers; intent vars guarded by `is defined`/`??`. Twig strict_variables=true confirmed.
- Found 4 ORPHANED templates (email/password_reset, email/payment_received, error/maintenance, checkout/partials/manual-gateway) - unreferenced; reported to user (not deleted; dynamic theme/module refs possible).

### Sidebar fix (Phase 8, systematic-debugging)

- Root cause: sidebar.twig rewritten in WIP (HEAD was static). admin.js still toggled old `.op-nav-has-sub`/`.op-nav-link`; new sidebar uses `.op-nav-group`/`.op-nav-item-link` → group expand/collapse dead (only active_page group auto-expanded → items appeared/vanished as page/brand changed). Plus broken /admin/faq link + missing i18n keys (raw labels) + brand-view gating hiding global items.
- Fixes: admin.js retargeted to `.op-nav-group` + button; sidebar.twig de-gated (consistent full menu, dropped broken faq); en.json +7 label keys.
- Verified in real browser (php -S static harness via preview): toggle works, 0 console errors, both contexts identical 34 links. ESLint/twig/json all clean. Harness + verify script + temp launch.json config removed.
