# Findings & Decisions

## Requirements
Address the 5 security vulnerabilities identified in `audit_report.md`:
1. **Simulated Gateway Payment Bypass (Apple Pay & Google Pay)**: Reject mock tokens/payments if the credentials specify `mode === 'live'`.
2. **Plugin Sandbox Escape (PluginLoader)**: Prevent variable/dynamic function calls and dynamic class instantiations. Block callback wrappers.
3. **Stored XSS via SVG Uploads (FilesystemService)**: Reject SVG files containing script tags, inline event listeners, javascript: URIs, or external entities.
4. **Uncached DB Queries (PermissionMiddleware)**: Cache the login slug check using `storage/cache/login_slug.cache`.
5. **GET-based Logout CSRF (web.php)**: Remove the GET `/logout` route so that logout is POST-only with CSRF validation.

## Research Findings
- **ApplePay/GooglePay Adapters**: Currently verify mock IDs starting with `APAY_MOCK_` / `GPAY_MOCK_` without verifying the mode. Adding `mode === 'live'` check in `verify()` and `initiate()` solves this.
- **Plugin Sandbox**: Scanner matches tokens against `PluginSandbox::isDangerousFunction()`. We can:
  - Add callback helpers (`call_user_func`, `array_map`, etc.) and Reflection/DB driver strings to `isDangerousFunction()`.
  - Ban variable/dynamic calls (`T_VARIABLE` followed by `(` or `)` followed by `(` where preceding expression contains a variable).
  - Ban dynamic class instantiations (`T_NEW` followed by `T_VARIABLE`).
- **SVG Uploads**: Check in `storeUpload` if `$ext === 'svg'` and reject if it contains `<script`, `on[a-z]+=`, `javascript:`, or `<!ENTITY`/`<!DOCTYPE`.
- **PermissionMiddleware**: Currently calls `SettingsRepository::get` every request. Cache is available in `storage/cache/login_slug.cache`.
- **Logout CSRF**: Form logout links already use POST. Removing GET `/logout` route in `config/routes/web.php` is safe.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Ban dynamic calls and instantiations in scanner | No plugins in the codebase use dynamic variables or dynamic classes, so blocking them avoids sandbox escape vectors. |
| Use regex-based string rejection for SVGs | Avoids complex XML parser overhead or parser-specific XXE bugs. |
| Align login slug resolution cache in PermissionMiddleware and TwoFactorMiddleware | Unifies caching pattern and eliminates database query load on every request. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [ApplePayGateway.php](file:///C:/laragon/www/ownpay/modules/gateways/apple-pay/ApplePayGateway.php)
- [GooglePayGateway.php](file:///C:/laragon/www/ownpay/modules/gateways/google-pay/GooglePayGateway.php)
- [PluginLoader.php](file:///C:/laragon/www/ownpay/src/Plugin/PluginLoader.php)
- [PluginSandbox.php](file:///C:/laragon/www/ownpay/src/Plugin/PluginSandbox.php)
- [FilesystemService.php](file:///C:/laragon/www/ownpay/src/Service/System/FilesystemService.php)
- [PermissionMiddleware.php](file:///C:/laragon/www/ownpay/src/Middleware/PermissionMiddleware.php)
- [TwoFactorMiddleware.php](file:///C:/laragon/www/ownpay/src/Middleware/TwoFactorMiddleware.php)
- [web.php](file:///C:/laragon/www/ownpay/config/routes/web.php)
