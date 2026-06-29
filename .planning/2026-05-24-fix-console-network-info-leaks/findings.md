# Findings & Decisions - Fix Console & Network Info Leaks

## Requirements

- Fix browser console errors/warnings caused by missing `nonce` attributes on external scripts blocked by CSP.
- Clean up duplicate and redundant response headers (e.g. duplicated security headers in Apache & PHP).
- Prevent information leaks of the technology stack (e.g. unsetting `X-Powered-By` in `.htaccess`).

## Research Findings

- **CSP Console Blocks**: The application implements strict CSP with a dynamic nonce policy (`script-src 'self' 'nonce-...'`). However, almost every external script tag (e.g. `admin.js`, `checkout.js`, `op-fetch.js`, and page-specific scripts) does not have the `nonce` attribute. Consequently, modern browsers block these scripts and print red violation errors to the console on almost every page.
- **Duplicate Headers**: `public/.htaccess` sets global security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`) for all requests (including PHP), while `SecurityHeadersMiddleware.php` also sets them, leading to duplicates in the network tab. We can restrict `.htaccess` to only apply to static files.
- **Header Leaks**: `X-Powered-By` should be unset in `.htaccess` as well to protect against server/engine version disclosure.

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Add `nonce="{{ csp_nonce }}"` to all `<script>` tags | Ensures modern browser engines do not block our own external JS scripts under strict CSP. |
| Use `$nonce` inside `CashmaalGateway` and `Theme` PHP code | Dynamically resolves the CSP nonce for inline/echoed script tags. |
| Limit `.htaccess` headers to static files | Avoids duplicate security headers on PHP pages. |
| Add `Header unset X-Powered-By` to `.htaccess` | Fully removes the PHP runtime engine header. |

## Issues Encountered

| Issue | Resolution |
|-------|------------|

## Resources

- [base.twig](file:///c:/laragon/www/ownpay/templates/admin/layout/base.twig)
- [checkout.twig](file:///c:/laragon/www/ownpay/templates/checkout/checkout.twig)
- [Theme.php](file:///c:/laragon/www/ownpay/modules/themes/own-pay/Theme.php)
- [CashmaalGateway.php](file:///c:/laragon/www/ownpay/modules/gateways/cashmaal/CashmaalGateway.php)
- [.htaccess](file:///c:/laragon/www/ownpay/public/.htaccess)
