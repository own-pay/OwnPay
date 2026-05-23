---
trigger: model_decision
description: Apply for security/cryptography reviews, auth audits, JWT/token/password/CSRF/webhook/plugin security, or when reviewing keys, encryption, signatures, or credentials in OwnPay.
---

# Security & Cryptography Rules — OwnPay Platform (May 2026)

These rules define cryptographic, authentication, and security enforcement standards for the PHP 8.2+ codebase. All rules are mandatory.

---

## 1. Authentication & Password Security
- User passwords MUST use **Argon2id** (`PASSWORD_ARGON2ID`) — never MD5, SHA1, bcrypt, or plain SHA256.
- Argon2id parameters MUST meet OWASP 2025 minimums: `memory_cost ≥ 65536` (64MB), `time_cost ≥ 3`, `threads ≥ 4`.
- Store hashes in `password` column of `op_merchant_users` — never in plaintext or as reversible encryption.
- `password_verify()` MUST be used for verification.
- Reset tokens MUST use `random_bytes(32)` encoded as hex/base64 — never `rand()`, `mt_rand()`, or `uniqid()`. They must expire in 1 hour and be single-use.
- Account lockout: max 5 failed logins within 15 minutes triggers a temporary lockout with exponential backoff.
- RBAC validation via `PermissionMiddleware` is required for all administrative routes.
- Superadmin accounts MUST enforce 2FA — login MUST be blocked without TOTP.

---

## 2. Dynamic 2FA & TOTP Replay Protection
- TOTP secrets are encrypted using AES-256-GCM with the `ENCRYPTION_KEY` and stored as `totp_secret_enc` in `op_merchant_users`.
- **Replay Protection**: `TwoFactorMiddleware` MUST track the last verified time window in session (`$_SESSION['totp_last_used_window']`). Reject submitted codes within the same window (±1 interval of ~30s).
- TOTP verification MUST use constant-time comparison (`hash_equals`).
- Backup codes (if any) must be single-use, Argon2id-hashed, and stored in a separate table.
- Recovery flows MUST require additional verification (email + admin confirmation) — no simple bypass.

---

## 3. JWT Claims, Verification & Secret Resolution
- All mobile API routes use JWT verification via `mobile` middleware group (`JwtAuthMiddleware`).
- **Strict Claims Checking**: JWTs MUST contain: `iss` (issuer), `aud` (audience), `sub` (subject), `exp` (expiration), and `jti` (JWT ID, required on refresh tokens). Reject if missing.
- **Algorithm Enforcement**: Only `HS256` or `RS256` are permitted. Reject `alg: none` immediately.
- **Secret Resolution**: Resolve via: device-specific secret → global `JWT_SECRET` env.
- `JwtService::encode()` and `decode()` MUST NOT accept an optional `$secret` parameter — class-level injected only.
- **Active Revocation**: Every request MUST check `op_paired_devices` to verify the device is active (`status = 'active'`). Reject revoked devices immediately.
- **JTI Blacklisting**: Refresh tokens MUST include a unique `jti`. Store used JTIs in `op_cache` (`key_name` / `expires_at`) to prevent replay.
- Expiration: Access tokens max 1 hour. Refresh tokens max 30 days.

---

## 4. Webhook IP Allowlisting & Signature Verification
- Webhook/IPN endpoints MUST use `IpAllowlistMiddleware` + `RequestSignatureMiddleware`.
- Webhook signature checks MUST use `hash_equals()` with HMAC-SHA256 computed using `HMAC_KEY`.
- Read raw request body before parsing for signature verification — do not re-serialize parsed data.
- Validate payload schema after signature verification.
- Allowlist IPs must load from configuration — not hardcoded.
- Replay: Webhook requests must check a timestamp claim and reject if older than 5 minutes.

---

## 5. Plugin Sandbox Security Scanner
- `PluginSandbox` MUST perform static analysis on plugin PHP files before activation.
- **Hard Blocklist**: Reject if files contain: `exec`, `shell_exec`, `passthru`, `system`, `popen`, `proc_open`, `eval`, backticks, `pcntl_exec`, `dl`, `assert` (string form), `preg_replace` with `/e`, or `base64_decode` on user input.
- **Dynamic Bypass**: Flag dynamic calls such as `$func = 'exec'; $func('id')` and `call_user_func/array('exec', ...)`.
- **Allowlist**: Permitted runtime helpers are `fwrite`, `ini_set`, `header`, `setcookie`, `json_encode`, `json_decode`, `base64_encode`.
- Plugins may only inject CSP via `checkout.csp.sources` filter hook or `manifest.json`. No direct dynamic injection.
- Plugin files must not be directly accessible via web URLs — only loaded via `PluginLoader`.

---

## 6. CSRF Mitigation
- CSRF validation is enforced on all non-API POST/PUT/PATCH/DELETE requests via `CsrfMiddleware`.
- **Token Retrieval**: ALWAYS use `\OwnPay\Security\SecurityHelpers::csrfToken()`. Manual access to `$_SESSION` for tokens is prohibited.
- **Canonical Session Key**: Use `_csrf_token` (with leading underscore). The legacy `csrf_token` is forbidden.
- CSRF tokens MUST use `random_bytes(32)` hex, rotate after mutating state, use `hash_equals()`, and reside in forms as `_csrf_token`.
- SameSite cookie attribute MUST be set to `Strict` or `Lax` on session cookies.

---

## 7. Key Configuration & Cryptographic Standards
- **Key Requirements**: `APP_KEY` (base64 32-byte), `ENCRYPTION_KEY` (base64 32-byte), `HMAC_KEY` (hex 32-byte), `JWT_SECRET` (hex 32-byte).
- Keys MUST use `random_bytes(32)`.
- `PaymentIntentCheckoutController` MUST throw `RuntimeException` if neither `HMAC_KEY` nor `APP_KEY` is configured.
- Keys MUST reside in `.env` only — never in database settings, code, comments, or logs.

---

## 8. Encryption Standards
- **Symmetric**: AES-256-GCM only. ECB, CBC without MAC, DES, 3DES are forbidden.
- **Hashing**: Argon2id for passwords. MD5, SHA1, bcrypt-only, SHA256 are forbidden.
- **HMAC**: HMAC-SHA256 or HMAC-SHA512. MD5/SHA1 HMAC are forbidden.
- **Signatures**: Ed25519 or RSA-PSS (2048+ bit).
- **Random**: `random_bytes()`. Do not use `rand()`, `mt_rand()`, or `uniqid()`.
- **TLS**: TLS 1.2 minimum (TLS 1.3 preferred).

---

## 9. Sensitive Data Handling & PII Protection
- Store encrypted at rest (AES-256-GCM): `totp_secret_enc`, gateway credentials, and card data.
- Mask PII (email, phone, name) in log outputs using `PIIMasker`.
- Error messages in production (`APP_DEBUG=false`) MUST be generic — no traces, database errors, or file paths.
- `Kernel::handleException()` MUST sanitize file paths before logging.

---

## 10. Session Security & Mass Assignment Protection
- Session IDs MUST be regenerated with `session_regenerate_id(true)` immediately after login.
- Session cookies MUST use: `Secure; HttpOnly; SameSite=Strict; Path=/`
- Admin sessions expire after 8 hours of inactivity. Destroy session and invalidate cookies on logout.
- **Mass Assignment**: No controller may pass raw `$_POST` or request body directly to repositories.
- Use `InputSanitizer::array()` with an explicit field allowlist.
- Prevent assignment of: `merchant_id`, `role_id`, `is_superadmin`, `password`, `totp_secret_enc`, `two_factor_enabled`, `balance`, ledger `type` columns.
