---
trigger: always_on
---

# Security & Cryptography Rules

## 1. Authentication & Password Security
- All user passwords MUST be hashed using the **Argon2id** algorithm (`PASSWORD_ARGON2ID`).
- Enforcement of Role-Based Access Control (RBAC) via `OwnPay\Middleware\PermissionMiddleware` is required for all administrative routes.

## 2. Dynamic 2FA & TOTP Replay Protection
- Two-Factor Authentication (2FA) uses standard TOTP secrets stored in the database as encrypted strings (`totp_secret_enc`).
- **Replay Protection**: The `TwoFactorMiddleware` verification logic MUST track the last verified time window in the session (`$_SESSION['totp_last_used_window']`). The system must reject any TOTP code submitted within the same window (±1 interval) to prevent token replay attacks.

## 3. JWT Claims, Verification & Secret Resolution
- All mobile API routes use JWT verification via the `mobile` middleware group.
- **Strict Claims Checking**: JWT tokens MUST contain both issuer (`iss`) and audience (`aud`) claims. `JwtAuthMiddleware` MUST reject any token lacking either claim.
- **Secret Resolution Hierarchy**: JWT secrets are resolved hierarchically: device-specific stored secret -> global environment secret (`JWT_SECRET`) -> testing fallback.
- **No Per-Call Secrets**: `JwtService::encode()` and `decode()` must NOT accept an optional `$secret` parameter. They must exclusively use the class-level injected secret.
- **Active Revocation Verification**: JWT verification MUST query the database state (`op_paired_devices`) dynamically to verify the device's status. Access MUST be denied immediately if the token's associated device is deactivated or revoked.
- **Stateless Token Rotation & JTI Blacklisting**: Replay attacks on refresh tokens are prevented by including a unique `jti` in each refresh token and recording used/invalidated JTIs in the `op_cache` table (using `key_name` / `expires_at` columns).

## 4. Webhook IP & Signature Verification
- Webhook endpoints MUST implement IP allowlisting and request signature verification (`RequestSignatureMiddleware`).
- Webhook signature checks MUST use timing-safe comparison operations (`hash_equals`) to protect against timing attacks.

## 5. Plugin Sandbox Security Scanner
- The `PluginSandbox` security scanner MUST audit all third-party plugins before activation.
- **Blocklist**: The scanner must reject plugins using forbidden system calls or code execution utilities: `exec`, `shell_exec`, `passthru`, `system`, `popen`, `proc_open`, `eval`, `backticks`.
- **Allowlist**: Standard runtime helpers required for integrations are explicitly permitted: `fwrite`, `ini_set`, `header`, `setcookie`.

## 6. CSRF Mitigation Guidelines
- CSRF validation is enforced on all non-API POST/PUT/DELETE requests via `CsrfMiddleware`.
- **Token Fetching**: Always fetch the token using the unified helper `\OwnPay\Security\SecurityHelpers::csrfToken()`. Manual session retrieval under index `$_SESSION['csrf_token']` is prohibited.
- **Canonical Session Key**: The system uses `_csrf_token` (with leading underscore) exclusively. No legacy `csrf_token` (without underscore) is allowed.
- Dynamic payment or checkout views MUST inject the CSRF token into form templates to prevent expired form submissions.

## 7. Key Configuration Enforcements
- The checkout subsystem (`PaymentIntentCheckoutController`) MUST require either `HMAC_KEY` or `APP_KEY` configuration. It must throw a `RuntimeException` if neither is configured. A hardcoded fallback string is strictly forbidden.
- Crypto key generation during installation must comply with PCI-DSS 3.6 guidelines: `APP_KEY` and `ENCRYPTION_KEY` are base64-encoded 32-byte strings; `HMAC_KEY` and `JWT_SECRET` are hexadecimal-encoded 32-byte strings.
