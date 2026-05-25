# Task Plan: Reorganize Admin Plugins UI and Fix Logo Loading

## Goal
Resolve plugin logo loading issues in both the admin panel and public checkout screens, while reorganizing the admin panel's plugin management system into a well-structured, categorized, and professional interface.

## Current Phase
Phase 8: Payment Link and Invoice Link Fix

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent and logo rendering issues
- [x] Verify database column conventions and file copies
- [x] Document code paths and findings in `findings.md`
- **Status:** complete

### Phase 3: Implementation (Plugins Dashboard)
- [x] Make `PluginManager::resolveIconPath()` public
- [x] Update `PluginController::index()` to enrich plugins with dynamic `logo_path` and invoke `resolveIconPath`
- [x] Update `templates/admin/plugins/index.twig` to render images for logos, add search/status filters, and restructure tabs by type
- [x] Update `public/assets/js/pages/plugins.js` to implement search and tab+status filtering
- [x] Append premium plugin styling (such as `.op-plugin-logo-img`) to `public/assets/css/admin.css`
- [x] Fix color-escaping CSS bug in `templates/checkout/partials/_gateway-grid.twig`
- **Status:** complete

### Phase 4: Testing & Verification (Plugins Dashboard)
- [x] Run PHPUnit tests to verify backend changes do not break existing tests
- [x] Run PHPStan analysis to confirm zero type errors
- [x] Run Twig/CSS/JS linters to ensure code standards compliance
- **Status:** complete

### Phase 5: Delivery
- [x] Finalize walkthrough report and deliver to user
- **Status:** complete

### Phase 6: Gateway Dashboard Refinement
- [x] Update `GatewayController::index()` to resolve `logo` dynamically for all API gateways via `PluginManager::resolveIconPath()`
- [x] Update `templates/admin/gateways/index.twig` to render logo image with `onerror` fallback to name initials
- [x] Run PHPUnit, PHPStan, and linters to verify gateways changes
- **Status:** complete

### Phase 7: Gateways Reorganization
- [x] Design and implement filter controls (search bar, status dropdown, type tabs) in gateways view
- [x] Restructure `templates/admin/gateways/index.twig` to render all gateways in a unified card grid
- [x] Create `public/assets/js/pages/gateways.js` to implement combined client-side filters
- [x] Run PHPUnit, PHPStan, and linters to verify gateways changes
- **Status:** complete

### Phase 8: Payment Link and Invoice Link Fix
- [x] Resolve dynamic base URL using DomainUrlService in InvoiceController and PaymentLinkController
- [x] Add global opCopyText helper with HTTP fallback in admin.js
- [x] Update templates to copy resolved urls using data-copy attribute (CSP-compliant) and refactor page scripts (developer.js, domains.js) to use helper
- [x] Fix race condition double-triggering on `.op-copy-btn` class and add fallback-on-rejection to `opCopyText`
- [x] Implement robust execCommand-first synchronous copying with async fallback to resolve async promise token loss and add event delegation
- [x] Run PHPUnit, PHPStan, and linters to verify changes
- **Status:** complete

### Phase 9: Documentation Synchronization
- [x] Update ARCHITECTURE.md to add rules 13 and 14 for clipboard copying and plugin logo resolution
- [x] Update developer-workflows.md rule to document gotchas 3.6 and 3.7
- [x] Update docs/v2/plugins/developer-guide.md to add section 10 details for plugin logos
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Make `resolveIconPath` public in `PluginManager` | Allows `PluginController` to dynamically copy and resolve public logo paths for all discovered/installed plugins. |
| Use client-side multi-filter JS (Type + Status + Search) | Provides an instant, snappy user experience without reloading the page or adding backend complex logic. |
| Hardcode `#` prefix in `_gateway-grid.twig` inline color style | Avoids Twig `|e('css')` escaping `#` to `\23 ` which corrupts background colors. |
| Resolve logos dynamically in `GatewayController` | Ensures API gateways in the gateways dashboard display actual logos even if inactive or not yet configured. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| ESLint: Curly brace expectations | Wrapped statusSelect style displays inside single line ifs with braces. |
| Stylelint: Missing empty line before rule | Added an empty line before `.op-plugin-fallback-icon` rule in `admin.css`. |
