# Findings & Decisions

## Requirements
- Diagnose and fix the 404 Not Found error returned when visiting `https://custom.ownpay.org/checkout/intent/{token}`.
- Provide a clear, actionable guide for the user to apply to their live cPanel server.

## Research Findings
- **RewriteBase Issue:** When the subdomain is mapped to the root directory `ownpay/` instead of `ownpay/public/` in cPanel, the root `.htaccess` redirects request paths to `public/`. Inside `public/.htaccess`, `RewriteBase /` in combination with `RewriteRule ^(.*)$ index.php [QSA,L]` causes Apache to prepend the `/` path to the rewritten destination, searching for `ownpay/index.php` instead of `ownpay/public/index.php`. Since `ownpay/index.php` does not exist, Apache returns a 404.
- **Host Header/Routing:** If the reverse proxy or web server doesn't forward the Host header correctly, `DomainMiddleware` fails to match the hostname, returning a 404.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Comment out `RewriteBase /` in `public/.htaccess` | Allows relative rewrites to resolve relative to `public/` (i.e. `public/index.php`) when request was redirected to `public/` from the root folder. |
| Add warning logs to `DomainMiddleware` on 404 | Enables quick debugging in production by outputting what Host header PHP is receiving. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Custom domain checkout returns 404 | Proposed commenting out `RewriteBase /` and adding diagnostic logs to pinpoint any Host mismatch. |

## Resources
- [public/.htaccess](file:///c:/laragon/www/ownpay/public/.htaccess)
- [DomainMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/DomainMiddleware.php)
