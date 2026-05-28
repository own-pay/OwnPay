# Findings & Decisions

## Requirements
- Eliminate all CSP inline style violations on the settings page (`/admin/settings/...`).
- Eliminate all CSP inline script violations (specifically `onclick`, `onkeyup`, `onchange` on interactive elements).
- Ensure manual sync, add currency, search, toggle status, and copy-to-clipboard actions work flawlessly without console errors or network errors.

## Research Findings
- The CSP policy in OwnPay does not allow `'unsafe-inline'` for `style-src` or `script-src`.
- Any HTML element using `style="..."` attribute triggers style-src CSP violation block.
- Any HTML element using event attribute like `onclick="..."` triggers script-src CSP violation block.
- Flash alert closing via inline `onclick` on base layout (`base.twig`) was triggering errors.
- Dynamic backdrop creation in `admin.js` using inline styles was triggering errors.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Move inline CSS styles to `admin.css` | Consolidating classes resolves CSP inline style blocks cleanly. |
| Use dynamic event listeners instead of onclick/onchange | Standardizing on unobtrusive JS event handlers allows strict script-src CSP compliance. |
| Add click listener for flash alert closing via JS delegation | Fixes the inline onclick block on base layout alert dismissals. |
| Define sidebar backdrop styles statically | Removing inline script styling from JS creation avoids CSSOM/inline-style policy errors. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Header style violations | Replaced inline style settings with classes. |
| Tooltip styling script block error | Converted tooltip configuration to static CSS class with dynamic class assignment. |

## Resources
- [admin.css](file:///c:/laragon/www/ownpay/public/assets/css/admin.css)
- [admin.js](file:///c:/laragon/www/ownpay/public/assets/js/admin.js)
- [index.twig](file:///c:/laragon/www/ownpay/templates/admin/settings/index.twig)
