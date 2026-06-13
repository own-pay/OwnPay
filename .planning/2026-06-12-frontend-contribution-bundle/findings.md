# Findings & Decisions: Frontend Contribution Sandbox Setup

## Requirements
- Setup a standalone UI/UX sandbox in `docs/frontend_contribution/`.
- Copy all OwnPay Twig template layouts (`templates/`) and frontend assets (`public/assets/` & `public/favicon.ico`).
- Provide dual development environments:
  1. A PHP-based mock server router (`preview.php`).
  2. A Node.js/Vite-based Twig compiler hot-reloading server (`vite.config.js`).
- Bundle a comprehensive mock dataset (`mock_data.json`) covering all pages.
- Draft a thorough guides document (`README.md`) detailing setup, best practices, rules, and breaking vs. non-breaking constraints.

## Research Findings
- **Custom Twig Extensions:** Located in `src/View/TwigExtensions.php`. The sandbox mocks all functions (`csrf_token`, `csrf_field`, `asset`, `env`, `hook`, `hook_filter`, `setting`, `flash_messages`, `__` translation) and filters (`money`, `datetime`, `truncate`, `slug`, `time_ago`, `format_bytes`) in both PHP and JavaScript environments.
- **Language Strings:** Extracted from `/storage/languages/en.json` and bundled in the sandbox so that translations map dynamically to actual system text.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Inline Vite Plugin | Written custom Twig router compilation middleware inside `vite.config.js` to avoid complex external NPM dependency issues. |
| Automatic Route Mapping | Dynamic candidate mapping (`$cleanUri.twig` or `$cleanUri/index.twig`) allows both servers to resolve URLs without hardcoding 50+ routes. |
| Strict Separation | Kept local dependencies (`vendor/`, `node_modules/`) out of the final folder to keep the package lightweight and clear. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Static asset serving path mismatch | Served files directly via `preview.php` using a MIME type mapping to prevent PHP Built-in Server document root 404s. |

## Resources
- [Vite Config](file:///c:/laragon/www/ownpay/docs/frontend_contribution/vite.config.js)
- [PHP Mock Server Router](file:///c:/laragon/www/ownpay/docs/frontend_contribution/preview.php)
- [Comprehensive Mock Data File](file:///c:/laragon/www/ownpay/docs/frontend_contribution/mock_data.json)
- [Contribution Guide & README](file:///c:/laragon/www/ownpay/docs/frontend_contribution/README.md)

