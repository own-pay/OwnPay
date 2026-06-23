# Findings & Decisions

## Requirements
- Diagnose and resolve the 403 Forbidden error occurring during the admin login POST submission.
- Ensure no guesswork or hallucinations. Focus on actual codebase logic and systematic troubleshooting.

## Research Findings
- The POST `/login` route uses the `web-auth` middleware stack: `SessionMiddleware`, `CsrfMiddleware`, and `RateLimiterMiddleware`.
- **403 Forbidden Error Origin**:
  - Initially, we investigated potential CSRF token verification issues.
  - Using browser automation, we simulated the exact user login action using the admin credentials (`admin` / `admin123`).
  - The browser successfully completed the login POST request and was redirected to `/admin`.
  - However, `/admin` returned a different `403 Forbidden` response: `You do not have permission to access this resource.`
  - This error originates from [PermissionMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/PermissionMiddleware.php#L116), indicating the user doesn't have the necessary RBAC permissions (specifically, `dashboard.view` for `/admin`).
- **Root Cause (Empty Permissions Tables due to comment headers in SQL)**:
  - We inspected the database and discovered that `op_permissions`, `op_role_permissions`, `op_currencies`, `op_system_settings`, and `op_sms_templates` tables were completely empty (`0` rows).
  - The database seeders ([seed_dummy_data.php](file:///c:/laragon/www/ownpay/storage/seed_dummy_data.php) and [seeder.php](file:///c:/laragon/www/ownpay/dev/seed/seeder.php)) load SQL files (such as `roles.sql`, `currencies.sql`, `system_settings.sql`, and `sms_templates.sql`) and parse them using `explode(';', $sql)`.
  - To prevent executing non-insert statements, they perform a safety check: `str_starts_with(strtoupper($stmt), 'INSERT')`.
  - However, all these SQL files start with a large `--` comment header.
  - Since the comments are at the start of the split statement, `strtoupper($stmt)` starts with `--`, NOT `INSERT`. Consequently, the safety check fails, and the statements are silently skipped, leaving the database tables completely empty.
- **Resolution**:
  - We modified both [seed_dummy_data.php](file:///c:/laragon/www/ownpay/storage/seed_dummy_data.php#L93-L114) and [seeder.php](file:///c:/laragon/www/ownpay/dev/seed/seeder.php#L110-L160) to strip single-line comments (starting with `--` or `#`) using regex replacements (`preg_replace('/--.*$/m', '', $sql)`) before performing the `str_starts_with` validation check.
  - While running the CLI seeder, we encountered a `1062 Duplicate entry` constraint violation on the `op_exchange_rates.uk_pair` index. This was because the newly executed `currencies.sql` seeder already inserts default exchange rates, causing the manual query in `seeder.php` to conflict.
  - We resolved this by changing the manual query in [seeder.php](file:///c:/laragon/www/ownpay/dev/seed/seeder.php#L132) to use `INSERT IGNORE INTO`, aligning it with the pattern already present in [seed_dummy_data.php](file:///c:/laragon/www/ownpay/storage/seed_dummy_data.php#L123).
  - **Installer 500 Error Fix**: During a fresh installation (uninstalled state, empty/unreachable database), visiting `/` or `/install` threw a `500 Internal Server Error` due to a failed PDO database connection on early boot. This was because `Router::loadRoutes()` was trying to instantiate `PluginRegistry` to load plugin routes, which recursively requested `PluginRepository` -> `Database` -> `PDO`. We resolved this by modifying [Router.php](file:///c:/laragon/www/ownpay/src/Http/Router.php#L216) to verify if `storage/.installed` exists before resolving `PluginRegistry`.
  - We executed the database seeder to fully populate the permissions (52 permissions, 342 role-permission mappings) and settings.
  - We re-executed the browser login flow and confirmed that `admin` is now redirected successfully to the administrative dashboard without any 403 Forbidden errors.
  - We resolved 3 minor PHPStan type safety notices in [CsrfMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/CsrfMiddleware.php#L113-L125) that arose from our diagnostic logging code.
  - We verified that both PHPUnit tests and PHPStan static analysis pass with zero errors.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Clean SQL comments in seeder | Single-line comment headers in seed SQL files caused the `str_starts_with(..., 'INSERT')` logic to skip insertion, leaving the permissions table empty and triggering 403 Insufficient Permissions. |
| Cast session and post values to string | Avoids mixed type validation errors in PHPStan strict level 9 analysis. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Missing `storage/temp` directory | Corrected cookie jar path to `storage/logs/test_cookies.txt` in the HTTP simulation script. |
| PHPStan mixed operation errors | Refactored CsrfMiddleware logging variables with explicit string type checks. |
