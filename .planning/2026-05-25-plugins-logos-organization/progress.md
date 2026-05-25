# Progress: Plugins Organization and Logo Loading

## Initial Setup
- Plan files initialized.
- Root causes analyzed.

## Verification Run Checklists
- [x] Running phpunit tests -> OK (402 tests passed)
- [x] Running phpstan analysis -> OK (No errors)
- [x] Running linters (Twig, CSS, JS) -> OK (Passed all ES/Style/Twig linting rules)

## Complete
- All logo path resolution and layout reorganizations implemented and verified successfully.

## Phase 6: Gateway Dashboard Refinement
- [x] Implement and verify dynamic logo resolution and robust fallback UI for the gateway dashboard

## Phase 7: Gateways Reorganization
- [x] Add tab controls, search, and status dropdown UI in templates
- [x] Implement unified card grid layout for both manual and API gateways
- [x] Write client-side JS filtering in `public/assets/js/pages/gateways.js`
- [x] Run PHPUnit, PHPStan, and linters to verify changes

## Phase 8: Payment Link and Invoice Link Fix
- [x] Resolve dynamic base URL using DomainUrlService in InvoiceController and PaymentLinkController
- [x] Add global opCopyText helper with HTTP fallback in admin.js
- [x] Update templates to copy resolved urls and refactor page scripts (developer.js, domains.js) to use helper
- [x] Run PHPUnit, PHPStan, and linters to verify changes

## Phase 9: Documentation Synchronization
- [x] Update ARCHITECTURE.md to add rules 13 and 14 for clipboard copying and plugin logo resolution
- [x] Update developer-workflows.md rule to document gotchas 3.6 and 3.7
- [x] Update docs/v2/plugins/developer-guide.md to add section 10 details for plugin logos
- [x] Run PHPUnit, PHPStan, and linters to verify changes
