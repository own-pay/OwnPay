# Findings & Decisions

## Requirements
- Dynamically resolve the custom login slug/prefix in the administration login forms and failed authentication templates.
- Ensure all redirects (e.g., in 2FA check, session checks, logouts, etc.) target the dynamic login slug instead of the hardcoded `/login` path.
- Prevent 404 errors when a user configures a custom login slug (like `/root`) and interacts with the login page, submits credentials, fails credentials validation, or is redirected due to session issues.

## Research Findings
- The application supports a dynamic login slug via `SettingsRepository` key `landing.admin_login_slug`.
- A file cache `/storage/cache/login_slug.cache` is used to store this slug to avoid database queries on public/webhook paths.
- `AuthController` renders `page/login.twig` using a fallback of `/login` for `login_url` if the key is not in `config.app`.
- In `AuthController->login` (failed credentials path), `login_url` is completely omitted in the parameters to `renderAdminPage`, causing the form to fall back to the hardcoded `action="{{ login_url ?? '/login' }}"` (meaning it tries to post to `/login` instead of the dynamic slug).
- Multiple redirects in `AuthController` (`twoFactorForm`, `twoFactorVerify`) and `DashboardController` (`myAccount`, `updateAccount`) redirect to `/login` instead of the dynamic login slug, causing a 404 if the slug is customized.
- Tests in `tests/Security/SecurityRemediationTest.php` reflect on `resolveLoginSlug` in `PermissionMiddleware` and `TwoFactorMiddleware`. This method resolves the slug from the cache first, and then the settings database.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Add `resolveLoginSlug` to `AuthController` and `DashboardController` | Allows both controllers to resolve the dynamic login slug from the cache/database settings just like `PermissionMiddleware` and `TwoFactorMiddleware`. |
| Pass `login_url` to `page/login.twig` in `loginForm` and `login` (failed credentials path) | Ensures the form action always points to the correct dynamic login path, preventing 404 errors on first attempt or subsequent retries. |
| Replace all hardcoded redirects to `/login` with dynamic paths | Avoids 404 errors when a session expires, password changes, or 2FA checks fail. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| None so far | - |

## Resources
- [AuthController](file:///C:/laragon/www/ownpay/src/Controller/Admin/AuthController.php)
- [DashboardController](file:///C:/laragon/www/ownpay/src/Controller/Admin/DashboardController.php)
