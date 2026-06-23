# Findings: Rate Limiting in OwnPay

## Current State of Rate Limiting
1. **Middleware implementation**:
   - `RateLimiterMiddleware` intercepts requests, builds key: `rl:{ip}:{path}` (up to 5 segments).
   - Resolves thresholds from static config `config.app`.
   - Categories:
     - `login`: 5 / 300s (protecting `/login`, `/2fa`, `/forgot-password`, and mobile device pairing `/api/mobile/v1/devices`).
     - `api`: 60 / 60s
     - `global`: 120 / 60s
   - Primary Storage: Redis `incr` and `expire` using `OwnPay\Cache\RedisCache`.
   - Secondary Storage: `op_rate_limits` table in MySQL using atomic `INSERT ... ON DUPLICATE KEY UPDATE` to avoid race conditions.
   - Throttling responses are always JSON 429.

2. **System Settings**:
   - `SettingsRepository` manages persistent runtime settings in the `op_system_settings` table.
   - Group name `general` is used for global configurations.

3. **Current Reset flow**:
   - `DeveloperController` has a `resetLimit` endpoint (`POST /admin/developer/rate-limits/reset`) that allows deleting specific keys from the database. It is protected by `PermissionMiddleware` (checks `api_keys.view` / `api_keys.manage`).

## Requirements for the Customizations
1. **Dynamic Custom Rules Configuration**:
   - Admin can add arbitrary rules consisting of:
     - `path`: wildcard pattern (e.g., `/api/*`, `/checkout/*`, `/login`, `*`).
     - `method`: GET, POST, or ALL.
     - `limit`: threshold integer.
     - `window`: window duration in seconds.
   - These rules are stored as a JSON-encoded array `rate_limit_rules` under the `general` settings group.
   
2. **IP Whitelisting**:
   - Admin can set whitelisted IPs/subnets (comma/newline/space separated list of IPs/CIDR subnets).
   - Stored under the setting `rate_limit_whitelist_ips`.
   - `RateLimiterMiddleware` parses this list using bitmask CIDR matching and skips rate limiting for matching IPs.

3. **Branded HTML Throttling UI**:
   - Middleware checks if the client expects a JSON response via `$request->expectsJson()`.
   - If not expecting JSON (e.g., standard browser HTML request), it returns:
     - A Twig template `error/429.twig` if Twig is available.
     - A fallback HTML page via `ErrorPageRenderer::rateLimitPage` if Twig is not available.
   - The UI includes branded dark theme, glassmorphism, Retry-After header, and an interactive real-time JavaScript countdown timer.

4. **Emergency Reset Mechanism**:
   - **CLI Utility (`cli/rate-limit-reset.php`)**:
     - Boots the framework environment using Reflection to invoke `Kernel::boot()`.
     - Resolves `RedisCache` and `Database` from the container.
     - Resets specific IP rate limits immediately: `php cli/rate-limit-reset.php --ip=1.2.3.4`.
     - Resets all rate limits immediately: `php cli/rate-limit-reset.php --all`.
     - Generates a short-lived (5 min) signed URL for browser-based emergency reset:
       `php cli/rate-limit-reset.php --generate-url --ip=1.2.3.4 [--expires=300]`.
   - **Web Bypass Endpoint (`/rate-limit/emergency-reset`)**:
     - Open route mapped under the `web` middleware group (no rate-limit middleware, no session auth required).
     - Signed URL verification using HMAC-SHA256 signature calculated from the IP and expires timestamp using `APP_KEY`.
     - Upon valid signature: clears the rate limits for that IP (`rl:{ip}:%`), logs the action to `op_audit_logs`, and redirects to the admin login page with a success message.
     - Strict Super Admin access protection: settings modifications and manual reset actions on the admin panel are strictly restricted to Super Admins (`$this->session->isSuperadmin()`).

