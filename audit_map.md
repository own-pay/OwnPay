# OwnPay Codebase Structural Audit Map (audit_map.md)

This document provides a comprehensive structural map of the OwnPay codebase as of local discovery on May 23, 2026.

## Part 1 — Structural Mapping (Step A)

### Directory Tree & Key Anchors
- **Root Directory:** `C:\laragon\www\ownpay`
- **Public Web Root:** `public/` (containing `index.php` and `.htaccess`)
- **Application Kernel & Entry Point:** `public/index.php` -> `src/Kernel.php`
- **Router:** `src/Http/Router.php`
- **Route Definitions:** `config/routes/web.php` and `config/routes/api.php`
- **Middleware Stacks:** Defined in `config/middleware.php` and implemented in `src/Middleware/`
- **Database Layer:** Custom wrapper `src/Core/Database.php` referencing standard SQL DDL `database/schema.sql`
- **Configuration Layer:** Auto-loading `.env` via `vlucas/phpdotenv`. Service providers configured via `config/services.php`
- **Twig View Templates:** Under `templates/` (Admin, Checkout, Email, Error)
- **Plugin Sandbox Isolation Layer:** `src/Plugin/PluginSandbox.php`
- **CLI Commands / Cron Endpoint:** `src/Controller/Page/CronController.php` matching `/cron/{secret}`
- **Test Suite:** PHPUnit tests configured in `phpunit.xml` and stored in `tests/`

### Direct Third-Party Dependencies (composer.lock)
- `brick/math` (v0.14.8) — Arbitrary-precision math operations
- `chillerlan/php-qrcode` (v5.0.5) — QR code generation
- `firebase/php-jwt` (v7.0.5) — JWT token coding & verification
- `twig/twig` (v3.26.0) — Templating engine
- `vlucas/phpdotenv` (v5.6.3) — Environment variable management
- `graham-campbell/result-type` (v1.1.4) / `phpoption/phpoption` (v1.9.5) — Monads/Options

---

## Part 2 — Discovered Features (Step B)

| Feature | Location | Auth Required | Permissions Required | Financial Touch | Input Accepted |
|---|---|---|---|---|---|
| Public Landing | `LandingController@index` | No | None | No | No |
| Web Admin Login | `AuthController@login` | No | None | No | Yes (POST username/password) |
| Web Two-Factor Verify | `AuthController@twoFactorVerify` | Partial | None | No | Yes (POST OTP) |
| Web Password Reset | `AuthController@forgotSubmit` | No | None | No | Yes (POST email) |
| Checkout Show / Pay | `CheckoutController@show` / `pay` | No | None | Yes | Yes (POST payment data) |
| Intent Checkout | `PaymentIntentCheckoutController` | No | None | Yes | Yes (POST payment data) |
| Invoice Checkout | `InvoiceCheckoutController` | No | None | Yes | Yes (Query token) |
| Payment Link Checkout | `PaymentLinkCheckoutController` | No | None | Yes | Yes (POST inputs) |
| SPA Admin Fragment | `DashboardController@fragment` | Yes | `admin` middleware group | No | Yes (Path parameter) |
| Admin Transactions | `TransactionController` | Yes | RBAC permissions | Yes | Yes (Search/filter/update) |
| Admin Invoices | `InvoiceController` | Yes | RBAC permissions | Yes | Yes (CRUD inputs) |
| Admin Payment Links | `PaymentLinkController` | Yes | RBAC permissions | Yes | Yes (CRUD inputs) |
| Admin Customers | `CustomerController` | Yes | RBAC permissions | No | Yes (CRUD inputs) |
| Admin Brands | `BrandController` | Yes | RBAC permissions | No | Yes (CRUD inputs/switch) |
| Admin Staff | `StaffController` | Yes | RBAC permissions | No | Yes (CRUD inputs) |
| Admin Gateways | `GatewayController` | Yes | RBAC permissions | Yes | Yes (Credentials/settings) |
| Admin Domains | `DomainController` | Yes | RBAC permissions | No | Yes (Domain verify/delete) |
| Admin Settings | `SettingsController` | Yes | RBAC permissions | No | Yes (Runtime settings save) |
| Admin Currencies | `CurrencyController` | Yes | RBAC permissions | Yes | Yes (Status change) |
| Admin Devices | `DeviceController` | Yes | RBAC permissions | No | Yes ( Revoke/Generate OTP) |
| Admin SMS Template Hub | `SmsTemplateAdminController` | Yes | RBAC permissions | Yes | Yes (Test regex/analyze templates) |
| Webhook Unified Endpoint | `UnifiedWebhookController` | Signature/IP | Signature & IP allowlist | Yes | Yes (Raw webhook payloads) |
| Cron Job Trigger | `CronController` | Secret | Secret validation | Yes | Yes (Query secret parameter) |
| Mobile Device Pairing | `DeviceController@pair` | No | Pairing OTP | No | Yes (JWT Generation) |
| Mobile Heartbeat / SMS sync | `SmsController@receive` | Yes | JWT (mobile) | Yes | Yes (SMS body / metadata) |

---

## Part 3 — Data Flow Maps (Step C)

### 1. Payment Lifecycle Flow
- **Initiation:** `Api\PaymentController@initiate` or `PaymentLinkCheckoutController@submit` creates `op_payment_intents` and/or `op_transactions` in `pending`.
- **Checkout View:** `CheckoutController@show` or `PaymentIntentCheckoutController@show` displays template.
- **Capture:** `CheckoutController@pay` processes capturing (manual verification request or calling third-party gateways). Updates status to `completed` or `failed`.
- **Settlement/Ledger Posting:** Completing transaction calls `LedgerService` to record double-entry entries in `op_ledger_entries`.

### 2. Refund Flow
- **Request:** `Api\RefundController@create` or Admin interface inputs refund details.
- **Verification:** Checks if captured transaction is refundable and amount does not exceed net amount.
- **Processing:** Alters transaction status to `refunded` or updates ledger assets/liabilities to reverse funds.

### 3. Webhook Handling Flow
- **Receiving Endpoint:** `/webhook/{gateway}` maps to `UnifiedWebhookController@handle`.
- **Verification:** `RequestSignatureMiddleware` checks signature.
- **Processing:** Gateway specific webhook adapters (plugins or core) parse payload, resolve matching transaction by ID, and update `op_transactions` to `completed`.

### 4. Merchant Onboarding
- **Invitation/Creation:** Admin creates new merchant records in `op_merchants` and sets up brand details.
- **Configuration:** Custom domain routing and gateway configs populated.

---

## Part 4 — Trust Boundaries (Step D)

### 1. Unauthenticated Public Visitor
- Scope: `/`, `/login`, `/checkout/*`, `/pay/*`.
- Access constraints: Rate limited via `RateLimiterMiddleware`. Cannot access administrative panel.

### 2. Authenticated End Customer (Checkout Flow)
- Scope: Access to payment form, express payment actions, status polling.
- Isolation: Validated strictly using the cryptographically signed `token` parameter mapping to `op_payment_intents` or `op_invoices`.

### 3. Staff Users / Merchants
- Scope: `/admin/*` SPA fragments.
- Isolation: Scoped dynamically by their assigned `merchant_id` via `TenantScope` and limited to permissions resolved via `PermissionMiddleware`.

### 4. Platform Super-Administrator
- Scope: All global settings, brand creation, global database audit log visibility, multi-brand switches.

### 5. Incoming Webhook
- Scope: `/webhook/{gateway}`.
- Verification: Restricted by IP address whitelist checks and signature comparison (HMAC validation).

### 6. Mobile Application SMS Sync (Companion App)
- Scope: `/api/mobile/v1/*`.
- Verification: Cryptographically secure JWT tokens containing specific pairing claims.

---

## Part 5 — Dependency Security Index (Step E)

High-priority cryptographic, session, token, and parsing packages highlighted:
1. `firebase/php-jwt` — Cryptographic signature token generation and validation for companion app JWT authentication.
2. `chillerlan/php-qrcode` — Generates payment and pairing visual QR codes.
3. `vlucas/phpdotenv` — Environment settings loader.
4. `twig/twig` — Escaped template processor (Requires context safety audits for potential Sandbox bypasses).
