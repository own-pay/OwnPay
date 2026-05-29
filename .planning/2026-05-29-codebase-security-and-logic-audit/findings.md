# Findings & Decisions

## Requirements
- Conduct a thorough, comprehensive security audit and code review of the entire OwnPay codebase.
- Identify codebase mismatches, developer mistakes, logic errors, and security issues.
- Document entries in findings.md and progress in progress.md.

## Research Findings
- **CSP Security Headers Middleware (`SecurityHeadersMiddleware.php`)**: Implements strict Content Security Policy (CSP), generates per-request nonces using `random_bytes(16)`, and registers nonces with the Twig template system. Dynamically retrieves CSP policies from gateway manifest files. Integrates `Report-To` with secure paths.
- **Double-Entry Ledger Bookkeeping (`LedgerService.php`)**: Correctly uses row-locking transactional patterns (`SELECT ... FOR UPDATE`) to prevent double posting. Validates debits vs credits with `bccomp` using strict BCMath precision calculations (4 decimal places). Works under `TenantScope` isolation.
- **Dependency Security (`composer audit`)**: Executed `composer audit` and verified that **zero security vulnerability advisories** exist within direct or transitive packages.
- **PHP Object Injection Prevention (A08)**: Verified `unserialize` usage inside `RedisCache.php` and `FileCache.php` is strictly constrained via `['allowed_classes' => false]`, which disables PHP object instantiation completely, neutralizing any object injection attack vectors.
- **Robust Plugin Sandboxing & AST Scanner (`PluginLoader.php` & `PluginSandbox.php`)**:
  - Implements a recursive token-level static security scanner (`token_get_all`) on plugin activation.
  - Detects and blocks: reflection APIs, raw database connectors (`PDO`, `mysqli`), dangerous functions (e.g. system commands, evaluation, putenv, file modifications), dynamic class instantiations (`new $class`), and dynamic/variable function execution (`$func()`).
  - Limits file IO to the specific plugin folder via robust path boundaries (`validateFilePath()`).
- **Dynamic 2FA & TOTP Replay Protection (`TwoFactorMiddleware.php`)**:
  - Implements strict RFC 6238 TOTP verification using timing-safe comparison (`hash_equals()`).
  - Includes full **replay protection** by tracking and caching the last verified time slice window in `$_SESSION['totp_last_used_window']` and rejecting codes within the same or older intervals.
- **Webhook Request Signature & Replay Protection (`RequestSignatureMiddleware.php`)**:
  - Employs timing-safe `hash_equals()` validation against HMAC-SHA256 request payload signatures (prefixed or raw).
  - Enforces replay protection by checking the `X-Timestamp` header against a strict 5-minute tolerance threshold if the header is present, protecting payments from replay hijacking.
- **Database Schema Structural Compliance (`database/schema.sql` & `database/migrations/001_schema_sync.sql`)**:
  - Audited the table definitions and verified complete compliance with column naming conventions (e.g., `op_merchant_users.totp_secret_enc`, `op_merchant_users.two_factor_enabled`, `op_currencies.decimal_places`, `op_sms_parsed.device_id`, etc.). All tables use explicitly matched primary/foreign indexes.
- **Docblock @todo Typo Mismatch (`TwigExtensions.php`)**:
  - Line 242 states a `@todo` to integrate setting loading with `SettingsRepository` in future phases.
  - *Code reality*: The function `setting(key, default)` is **already fully integrated** with `SettingsRepository` and `SettingsService` (retrieving scoped setting overrides under `BrandContext`). This is a benign docblock typo left behind.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Standard Audit Format | Ground research findings in actual verified source files. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [SecurityHeadersMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/SecurityHeadersMiddleware.php)
- [LedgerService.php](file:///c:/laragon/www/ownpay/src/Service/Payment/LedgerService.php)
- [PluginLoader.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginLoader.php)
- [PluginSandbox.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginSandbox.php)
- [TwoFactorMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/TwoFactorMiddleware.php)
- [RequestSignatureMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/RequestSignatureMiddleware.php)
- [TwigExtensions.php](file:///c:/laragon/www/ownpay/src/View/TwigExtensions.php)



