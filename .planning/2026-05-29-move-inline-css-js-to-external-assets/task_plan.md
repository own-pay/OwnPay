# Task Plan: Move Inline CSS/JS to External Assets & Fix Linting Errors

## Goal
Eliminate all inline CSS `<style>` blocks and inline `<script>` tags from administrator base layout and checkout/payment templates. Move all assets to public static files under `public/assets/` to ensure zero inline styles or scripts, while maintaining a fully functional admin panel and checkout flow. Validate and resolve all ESLint and Stylelint warnings/errors.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Identify all remaining files containing inline CSS/JS.
- [x] Verify email/error templates exclusion scope (emails must keep inline styles for client compatibility; error templates must be self-contained).
- [x] Document findings in findings.md.
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Design data attribute-based config passing for checkout.twig.
- [x] Map out CSS/JS files to be created/modified.
- **Status:** complete

### Phase 3: Implementation
- [x] Refactor templates/admin/layout/base.twig (move FOUC script to public/assets/js/theme-init.js and read OP_CSRF via meta tag in public/assets/js/admin.js).
- [x] Refactor templates/checkout/layout.twig (move styles to public/assets/css/payment-link.css).
- [x] Refactor templates/checkout/payment-link-amount.twig (remove inline styles and inline JS onfocus/onblur event handlers, mapping them to classes in payment-link.css).
- [x] Refactor templates/checkout/checkout.twig & templates/checkout/partials/_gateway-grid.twig (move dynamic style generation to public/assets/js/checkout.js; pass config, manual gateways, and custom CSS/JS via data-* attributes on a hidden div).
- [x] Refactor templates/checkout/partials/_pending.twig (move dynamic status-based style generation to public/assets/css/checkout-status.css and move auto-refresh setTimeout to public/assets/js/checkout-status.js).
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify pages render and interact cleanly with zero CSP or JS console errors using the browser tool.
- [x] Run PHPUnit test suite to ensure no regressions.
- [x] Run PHPStan analysis to maintain Level 9 compliance.
- [x] Run ESLint and Stylelint to verify zero assets linting errors.
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs.
- [x] Update walkthrough artifact.
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Data attributes for configurations | Using `data-*` attributes on a wrapper DIV in `checkout.twig` allows us to securely transfer server-side JSON configs to external JS files without any inline script tags. |
| Nonced stylesheet injection | Using dynamically created `<style>` element in JS with `nonce` read from a meta tag allows clean per-gateway dynamic style generation without violating strict CSP policies. |
| theme-init.js in head | Placing a blocking external script in `<head>` prevents theme FOUC just like inline scripts, but complies with strict zero-inline CSP requirements. |
| Optional catch binding | Used `catch {` instead of `catch (err)` when `err` is not needed, resolving the `no-unused-vars` lint warning cleanly. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| Duplicate backdrop-filter in setup-wizard.css | Removed duplicate declaration at line 3. |
| Unused variables in settings.js & setup-wizard.js | Resolved unused variables by using optional catch binding and deleting the dead state-tracking variable. |
