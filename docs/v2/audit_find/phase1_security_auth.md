# Forensic Audit Findings — Phase 1: Security, Authentication, & Session Management

This document details the critical findings, vulnerabilities, and structural flaws identified during the forensic audit of OwnPay's security and authentication modules.

---

## 1. Plaintext TOTP Secret Storage
- **Severity**: Critical
- **Component**: `src/Repository/MerchantUserRepository.php` & `src/Controller/Admin/TwoFactorSetupController.php`
- **Location**:
  - [MerchantUserRepository.php:L212-L230](src/Repository/MerchantUserRepository.php#L212-L230)
  - [TwoFactorSetupController.php:L50](src/Controller/Admin/TwoFactorSetupController.php#L50)
- **Vulnerability**:
  The database column is named `totp_secret_enc`, suggesting encrypted storage. However, the repository methods `getTotpSecret()` and `setTotpSecret()` save and retrieve the raw base32 secret in plaintext. No encryption wrapper (e.g., using `FieldEncryptor`) is ever applied to the secret before write or after read.
- **Impact**:
  If the database is compromised, an attacker can extract all staff/admin TOTP secrets, easily generate valid 2FA tokens, and bypass multi-factor authentication (MFA).

---

## 2. Stateless JWT Device Revocation Bypass
- **Severity**: High
- **Component**: `src/Middleware/JwtAuthMiddleware.php`
- **Location**:
  - [JwtAuthMiddleware.php:L48-L81](src/Middleware/JwtAuthMiddleware.php#L48-L81)
- **Vulnerability**:
  `JwtAuthMiddleware` validates companion mobile app requests using stateless JWT signatures. Although the system supports revoking paired devices (setting `status = 'revoked'` in `op_paired_devices`), the middleware never checks the database to verify if the decoded device ID (`did` claim) has been suspended or revoked.
- **Impact**:
  Once a JWT access token is issued, a revoked device remains authenticated and can proceed to push SMS or trigger actions until the token expires (typically 24 hours). Instant revocation is impossible.

---

## 3. Absence of Reverse Proxy Trust in Request Helpers
- **Severity**: High
- **Component**: `src/Http/Request.php`
- **Location**:
  - [Request.php:L221-L224](src/Http/Request.php#L221-L224) (for `ip()`)
  - [Request.php:L242-L245](src/Http/Request.php#L242-L245) (for `isSecure()`)
- **Vulnerability**:
  The request wrapper relies exclusively on `REMOTE_ADDR` for identifying client IPs and `HTTPS` server variable for detecting SSL. It does not parse or trust HTTP headers commonly set by upstream reverse proxies, load balancers, or Cloudflare (e.g., `X-Forwarded-For`, `X-Forwarded-Proto`, or `X-Real-IP`).
- **Impact**:
  - **IP Spoofing / Lockout**: `IpAllowlistMiddleware` checks the proxy server's internal IP instead of the caller's actual IP, locking out webhooks or allowing unauthorized requests if the proxy IP is inadvertently trusted. Audit logs will also log the proxy's IP.
  - **Cookie Exposure**: `SessionMiddleware` reads `isSecure()` to set the session cookie. In SSL-terminated proxy configurations, `isSecure()` evaluates to `false`, leaving session cookies without the `Secure` flag, exposing sessions to packet sniffing.
  - **HSTS Failure**: `SecurityHeadersMiddleware` will not send the `Strict-Transport-Security` header.

---

## 4. Null Coalescing Bug on Headers
- **Severity**: Medium
- **Component**: `src/Middleware/RequestSignatureMiddleware.php` & `src/Service/Payment/IdempotencyBridge.php`
- **Location**:
  - [RequestSignatureMiddleware.php:L28](src/Middleware/RequestSignatureMiddleware.php#L28)
  - [IdempotencyBridge.php:L26-L27](src/Service/Payment/IdempotencyBridge.php#L26-L27)
- **Vulnerability**:
  `Request::header()` has a return type hint of `string` and defaults to `''`. When the code attempts to resolve fallback headers using the null coalescing operator `??` (e.g. `$request->header('X-Signature') ?? $request->header('X-Hub-Signature-256')`), the left expression evaluates to `''` instead of `null` if the header is absent.
- **Impact**:
  - In `RequestSignatureMiddleware`, fallback headers like `X-Hub-Signature-256` or signature query parameters are completely ignored.
  - In `IdempotencyBridge`, the fallback key `X-Idempotency-Key` is ignored if `Idempotency-Key` is missing.

---

## 5. Role Permission Privilege Escalation
- **Severity**: High
- **Component**: `src/Controller/Admin/RolesController.php`
- **Location**:
  - [RolesController.php:L127-L129](src/Controller/Admin/RolesController.php#L127-L129)
- **Vulnerability**:
  The `store` and `update` endpoints for managing roles do not validate if the logged-in user possesses the permissions they are granting.
- **Impact**:
  A staff member with role-management privileges (`staff.manage`) can assign highly privileged permissions (such as `system.update` or `settings.manage`) to any role and escalate their own privileges.

---

## 6. Stale Session Partial Clean-up
- **Severity**: Low
- **Component**: `src/Middleware/TwoFactorMiddleware.php` & `src/Middleware/PermissionMiddleware.php`
- **Location**:
  - [TwoFactorMiddleware.php:L38-L41](src/Middleware/TwoFactorMiddleware.php#L38-L41)
  - [PermissionMiddleware.php:L42-L44](src/Middleware/PermissionMiddleware.php#L42-L44)
- **Vulnerability**:
  Upon discovering that an authenticated user has been deleted or deactivated in the DB, the middlewares only unset `auth_user_id` or a partial set of session variables instead of calling `session_destroy()` or clearing the entire `$_SESSION` array.
- **Impact**:
  Stale keys (such as `auth_role_id`, `auth_email`, `active_brand_id`) linger in the session, leading to potential state pollution or context bypass issues elsewhere.

---

## 7. CORS Wildcard Configuration Defect
- **Severity**: Low
- **Component**: `src/Middleware/CorsMiddleware.php`
- **Location**:
  - [CorsMiddleware.php:L65-L68](src/Middleware/CorsMiddleware.php#L65-L68)
- **Vulnerability**:
  If the administrator configures `CORS_ALLOWED_ORIGINS=*` in the environment, the parser returns `[]` (empty list), completely blocking cross-origin requests rather than supporting wildcard operations.
- **Impact**:
  Integrations relying on open API endpoints will experience CORS failures.
