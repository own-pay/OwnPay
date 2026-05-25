# Findings & Decisions - Brand Switching Fix

## Requirements
- Fix the brand selector in the admin layout so that superadmins can switch contextually between active brands or Global View.
- Prevent regression where the brand does not switch or context gets lost/reverted on page load redirect.
- Maintain compliance with strict Content Security Policy (CSP) security headers.

## Research Findings
1. **CSP Violation**: 
   - The brand switcher dropdown in `templates/admin/layout/navbar.twig` uses an inline `onchange` handler: `onchange="document.getElementById('brand-switcher-form').submit()"`.
   - In production mode (`APP_DEBUG=false`), the server sends a strict `Content-Security-Policy` header.
   - Because inline scripts are restricted (`script-src 'self' 'nonce-...'`), the browser blocks the execution of the inline `onchange` handler, preventing the form from submitting entirely.
   - To fix this, the inline `onchange` handler must be removed from the HTML and registered as a clean event listener inside `public/assets/js/admin.js`.

2. **Session ID Regeneration**:
   - `BrandController::switchBrand` currently calls `session_regenerate_id(true)` and `session_write_close()`.
   - Regenerating the session ID is not necessary for brand switching because the user's authentication level remains unchanged (they are already logged in as superadmin).
   - `session_regenerate_id(true)` deletes the old session file immediately. If the browser issues the redirected request before saving the new cookie, or if the server FastCGI thread processes the redirected GET request using the old session ID, the session gets lost, causing the user to be logged out or redirected back to the login page.
   - Removing `session_regenerate_id(true)` from the switch brand logic completely resolves this race condition and guarantees session persistence.

3. **Browser Asset Caching**:
   - Client browsers cache static assets such as `admin.js`.
   - Since `admin.js` is loaded without a version query parameter (unlike `admin.css?v=012`), the browser may fail to reload the updated `admin.js` and therefore not bind the new change listener to `#brand-switcher-select`.
   - Appending `?v=012` to `admin.js` in `templates/admin/layout/base.twig` resolves this issue.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Remove inline `onchange` from navbar dropdown | Ensures compliance with strict Content Security Policy (CSP) headers. |
| Register dropdown listener in `admin.js` | Employs CSP-compliant event delegation/handling. |
| Remove `session_regenerate_id` from `switchBrand` | Prevents session loss and race conditions on 302 redirect. |
| Add cache-buster query parameter to `admin.js` | Forces the browser to load the new JS version instead of using a cached copy. |

## Resources
- [BrandController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/BrandController.php)
- [navbar.twig](file:///c:/laragon/www/ownpay/templates/admin/layout/navbar.twig)
- [admin.js](file:///c:/laragon/www/ownpay/public/assets/js/admin.js)
- [base.twig](file:///c:/laragon/www/ownpay/templates/admin/layout/base.twig)
