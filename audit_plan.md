# OwnPay Adversarial Audit Plan (audit_plan.md)

This plan outlines the specific security checks, abuse scenarios, code quality, infrastructure, and compliance items to be audited in this session.

## Audit Checklist

| Check ID | Domain | What to look for | Files to read | Priority | Status |
|---|---|---|---|---|---|
| CK-A1-01 | A-Security | Session creation, storage, destruction, and fixation vulnerabilities | `src/Middleware/SessionMiddleware.php` | P0 | [x] |
| CK-A1-02 | A-Security | API key generation entropy and timing safe verification | `src/Repository/ApiKeyRepository.php`, `src/Middleware/BearerAuthMiddleware.php` | P0 | [x] |
| CK-A1-03 | A-Security | Brute force delays, lockouts, and rate limiting validation on login | `src/Controller/Admin/AuthController.php`, `src/Middleware/RateLimiterMiddleware.php` | P0 | [x] |
| CK-A1-04 | A-Security | 2FA TOTP verification, time-window replay protection | `src/Middleware/TwoFactorMiddleware.php`, `src/Controller/Admin/AuthController.php` | P0 | [x] |
| CK-A1-05 | A-Security | Argon2id hash strength and secure password verify | `src/Controller/Admin/AuthController.php`, `src/Repository/MerchantUserRepository.php` | P0 | [x] |
| CK-A1-06 | A-Security | JWT token claims validation and mobile companion pairing flow | `src/Middleware/JwtAuthMiddleware.php`, `src/Controller/Api/Mobile/DeviceController.php` | P0 | [x] |
| CK-A2-01 | A-Security | Role-Based Access Control logic and authorization bypass checks | `src/Middleware/PermissionMiddleware.php` | P1 | [x] |
| CK-A2-02 | A-Security | IDOR and tenant separation bounds validation | `src/Repository/TenantScope.php`, `src/Repository/BaseRepository.php` | P1 | [x] |
| CK-A3-01 | A-Security | SQL Injection: concatenated/interpolated parameters in Database queries | `src/Core/Database.php`, `src/Repository/BaseRepository.php` | P1 | [x] |
| CK-A3-02 | A-Security | Remote Code Execution: dangerous system calls (`exec`, `shell_exec`, etc.) and Plugin Sandbox bypass | `src/Plugin/PluginSandbox.php`, `src/Plugin/PluginLoader.php` | P1 | [x] |
| CK-A3-03 | A-Security | Unsafe deserialization vulnerabilities (`unserialize`) | Codebase search | P2 | [x] |
| CK-A4-01 | A-Security | Webhook Signature verification, hash_equals timing-safe validation | `src/Middleware/RequestSignatureMiddleware.php` | P1 | [x] |
| CK-A4-02 | A-Security | Insecure cryptography: usage of weak hash algorithms (MD5, SHA1) or hardcoded secrets | Codebase search | P1 | [x] |
| CK-A5-01 | A-Security | Cross-Site Scripting (XSS) in templates and dynamic inputs | `templates/`, `src/Service/System/InputSanitizer.php` | P2 | [x] |
| CK-A6-01 | A-Security | Cross-Site Request Forgery (CSRF) protection on state changes | `src/Middleware/CsrfMiddleware.php` | P2 | [x] |
| CK-A7-01 | A-Security | Path traversal and arbitrary file upload vulnerabilities | `src/Controller/Admin/SettingsController.php`, `src/Service/System/FilesystemService.php` | P2 | [x] |
| CK-A8-01 | A-Security | Server-Side Request Forgery (SSRF) in webhook/outgoing client | `src/Service/System/HttpClient.php` | P1 | [x] |
| CK-A9-01 | A-Security | Security headers configuration (CSP, X-Frame-Options, HSTS) | `src/Middleware/SecurityHeadersMiddleware.php` | P3 | [x] |
| CK-A9-02 | A-Security | Information disclosure in verbose debug error pages | `src/Kernel.php` | P3 | [x] |
| CK-A10-01 | A-Security | Vulnerable dependencies check in composer.lock | `composer.lock` | P4 | [x] |
| CK-B1-01 | B-Business | Double spend and transaction capturing concurrency race conditions | `src/Controller/Checkout/CheckoutController.php` | P0 | [x] |
| CK-B1-02 | B-Business | Payment intent state machine validation | `src/Repository/PaymentIntentRepository.php`, `src/Controller/Checkout/PaymentIntentCheckoutController.php` | P0 | [x] |
| CK-B2-01 | B-Business | Floating-point monetary calculations vs minor currency units / decimal precision | Codebase arithmetic search | P0 | [x] |
| CK-B2-02 | B-Business | Zero or negative payment amount acceptance | `src/Controller/Checkout/CheckoutController.php` | P0 | [x] |
| CK-B3-01 | B-Business | Webhook delivery verification and replay/idempotency protection | `src/Controller/Webhook/UnifiedWebhookController.php` | P1 | [x] |
| CK-B4-01 | B-Business | Refund limit validation (cannot exceed capture amount, duplicate refunds) | `src/Controller/Api/RefundController.php`, `src/Repository/RefundRepository.php` | P1 | [x] |
| CK-B5-01 | B-Business | Payout logic and account balance verification checks | `src/Repository/LedgerRepository.php` | P1 | [x] |
| CK-B6-01 | B-Business | Multi-tenant financial account scoping | `src/Service/Brand/BrandContext.php` | P1 | [x] |
| CK-B7-01 | B-Business | Suspended merchant transaction validation | `src/Controller/Checkout/CheckoutController.php` | P1 | [x] |
| CK-B8-01 | B-Business | Fee calculation logic security (platform fees computed server-side) | `src/Repository/FeeRuleRepository.php` | P1 | [x] |
| CK-B9-01 | B-Business | Rate limiting implementation on sensitive endpoints | `src/Middleware/RateLimiterMiddleware.php` | P3 | [x] |
| CK-B10-01 | B-Business | Log modification capabilities and financial audit log integrity | `src/Service/System/AuditLogger.php`, `src/Repository/AuditLogRepository.php` | P3 | [x] |
| CK-C1-01 | C-Code | PHP 8.2 compatibility, deprecated string interpolation, dynamic properties | Codebase search | P3 | [x] |
| CK-C2-01 | C-Code | Exception swallowing and silent errors (@ sign usage) | Codebase search | P3 | [x] |
| CK-C3-01 | C-Code | Unified validation vs ad-hoc validation input checks | `src/Service/System/InputSanitizer.php` | P3 | [x] |
| CK-C4-01 | C-Code | Transaction boundaries (`beginTransaction`) for financial state | `src/Repository/LedgerRepository.php` | P3 | [x] |
| CK-C5-01 | C-Code | Exposed credentials in codebase | `config/`, `.env.example` | P3 | [x] |
| CK-C6-01 | C-Code | PII, card data, or secret leakage in logs | `src/Service/System/Logger.php` | P3 | [x] |
| CK-C7-01 | C-Code | Leftover debug statements (`var_dump`, `dd`, `die`) or bypass test routes | Codebase search | P3 | [x] |
| CK-C8-01 | C-Code | Separation of concerns (fat controllers vs domain service classes) | Codebase search | P3 | [x] |
| CK-D1-01 | D-Infra | Internals block configuration (Nginx/Apache config rules) | `.htaccess`, `nginx.conf.example` | P4 | [x] |
| CK-E1-01 | E-Compliance | PCI-DSS cardholder data storage (RAW PAN, CVV, or track storage check) | Codebase database inserts | P4 | [x] |
| CK-E2-01 | E-Compliance | GDPR PII retention period and deletion capability | Codebase search | P4 | [x] |
| CK-E3-01 | E-Compliance | AGPL-3.0 compatibility of dependencies | `composer.json` | P4 | [x] |
