# OwnPay - Official Feature Tracking & Implementation Matrix

> **The definitive tracking registry for all built-in, active, and roadmap features in OwnPay.**
> Every feature implemented in OwnPay is cataloged here with its domain, technical mechanism, status, and implementation reference.

---

## 📌 Feature Governance & Agent Rule

1. **Mandatory Feature Registry:** Whenever a new feature is requested, designed, or planned, it MUST be assigned a unique ID (e.g. `FEAT-XXX-000`) and logged in this tracking document under the appropriate status.
2. **Implementation Completion:** When a feature is completed, tested, and verified, its status MUST be updated to `[DONE]` along with the relevant commit hash, files modified, and test verification notes.
3. **Traceability:** Every feature entry links directly to its domain files, database tables, and controller/service layers.

---

## 🚦 Status Legend

- `[DONE]` : Fully implemented, covered by tests, and verified at PHPStan Level 9.
- `[IN_PROGRESS]` : Currently being designed or implemented in the active branch.
- `[PLANNED]` : Prioritized for upcoming release milestones.
- `[BACKLOG]` : Under evaluation for future platform versions.

---

## 📊 Comprehensive Feature Matrix

### 1. Sovereign White-Label & Custom Domain Architecture

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-DOM-001` | **Custom Domain Mapping** | Resolves `HTTP_HOST` in `DomainMiddleware` against `op_domains` records. | `[DONE]` | `src/Middleware/DomainMiddleware.php`, `src/Repository/DomainRepository.php` |
| `FEAT-DOM-002` | **Automated DNS Verification** | Validates domain ownership via `_ownpay-verification.{domain}` TXT & A-record lookups. | `[DONE]` | `src/Service/Domain/DomainService.php`, `src/Cron/DnsVerificationJob.php` |
| `FEAT-DOM-003` | **Dynamic URL Builder** | `DomainUrlService` constructs checkout, webhook, and callback URLs using verified brand domains. | `[DONE]` | `src/Service/Domain/DomainUrlService.php` |
| `FEAT-DOM-004` | **Private Admin Route Shielding** | Returns hard 404 for `/admin/*` on custom domains; restricts admin to master `APP_DOMAIN`. | `[DONE]` | `src/Middleware/DomainMiddleware.php` |
| `FEAT-DOM-005` | **Dynamic CSP Frame-Ancestors** | Restricts iframe frame-ancestors strictly to DNS-verified brand domains to prevent clickjacking. | `[DONE]` | `src/Middleware/SecurityHeadersMiddleware.php` |

---

### 2. Multi-Brand Partitioning & Platform Scoping

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-BRD-001` | **Tenant-Scoped Repositories** | `TenantScope` trait ensures repository cloning with `$repo->forTenant($mid)` for isolated CRUD. | `[DONE]` | `src/Repository/TenantScope.php`, `src/Repository/BaseRepository.php` |
| `FEAT-BRD-002` | **The Platform Scope (`is_platform=1`)** | Reserved `is_platform = 1` row (`__platform__`) owning All-Brands global API keys and ledger defaults. | `[DONE]` | `src/Service/Brand/BrandContext.php` |
| `FEAT-BRD-003` | **Single-Pane Brand Switcher** | Super-admins switch active store contexts seamlessly from the admin header bar. | `[DONE]` | `src/Controller/Admin/BrandController.php`, `templates/admin/layout/header.twig` |
| `FEAT-BRD-004` | **Hierarchical Settings Cascade** | Resolves brand setting override -> All-Brands global default -> code default. | `[DONE]` | `src/Repository/SettingsRepository.php` |

---

### 3. Payment Gateway System & Plugin Sandbox

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-GW-001` | **123+ Modular Gateway Adapters** | Standardized gateway plugins in `modules/gateways/` implementing `GatewayAdapterInterface`. | `[DONE]` | `modules/gateways/`, `src/Gateway/GatewayAdapterInterface.php` |
| `FEAT-GW-002` | **Custom Manual / Offline Gateways** | Dynamic forms in `op_manual_gateways` (Bank Transfer, Cash, Agent Cash-in) with proof fields. | `[DONE]` | `src/Repository/ManualGatewayRepository.php`, `src/Controller/Admin/GatewayController.php` |
| `FEAT-GW-003` | **AST Plugin Security Sandbox** | Token-based security parser blocking `eval`, `shell_exec`, backticks, and direct SQL queries in plugins. | `[DONE]` | `src/Security/PluginSandbox.php`, `src/Plugin/PluginLoader.php` |
| `FEAT-GW-004` | **Encrypted Gateway Credentials** | API secrets and webhook keys encrypted at rest with AES-256-GCM in `op_gateway_configs`. | `[DONE]` | `src/Security/FieldEncryptor.php`, `src/Repository/GatewayConfigRepository.php` |
| `FEAT-GW-005` | **Symlink Traversal Guard** | Canonical path validation and `is_link()` checks prevent symlink directory escapes in plugins. | `[DONE]` | `src/Plugin/PluginLoader.php` (`9ed16265`) |

---

### 4. Checkout Experience & UI Accessibility

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-CK-001` | **Hosted Branded Checkout** | Standalone checkout page at `/checkout/intent/{token}` with brand colors and logos. | `[DONE]` | `src/Controller/Checkout/PaymentIntentCheckoutController.php`, `templates/checkout/checkout.twig` |
| `FEAT-CK-002` | **Embeddable Modal Checkout** | Embedded JavaScript modal popup for zero-redirect checkouts on merchant stores. | `[DONE]` | `public/assets/js/checkout.js`, `templates/checkout/partials/_manual-popup.twig` |
| `FEAT-CK-003` | **Multi-Use Payment Links** | Single-use and capped multi-use payment links (`op_payment_links`) with automatic exhaustion. | `[DONE]` | `src/Repository/PaymentLinkRepository.php`, `src/Service/Payment/PaymentCompletionListener.php` |
| `FEAT-CK-004` | **WCAG 2.2 Modal Accessibility** | Dialog modals with `role="dialog"`, `aria-modal="true"`, focus capture, and Escape/Tab handling. | `[DONE]` | `public/assets/js/admin.js`, `public/assets/js/checkout.js` |
| `FEAT-CK-005` | **Mobile Touch Targets & Zoom** | 40px minimum touch targets and unrestricted pinch-to-zoom (WCAG 1.4.4) on checkout screens. | `[DONE]` | `public/assets/css/checkout.css`, `public/assets/css/tokens.css` |

---

### 5. Double-Entry Accounting & GAAP Ledger

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-LED-001` | **Triple-Table Ledger Architecture** | Pure double-entry schema in `op_ledger_accounts`, `op_ledger_transactions`, `op_ledger_entries`. | `[DONE]` | `src/Repository/LedgerRepository.php`, `src/Service/Payment/LedgerService.php` |
| `FEAT-LED-002` | **Mathematical Balance Invariant** | Enforces sum(debit) === sum(credit) at 4-decimal precision using BCMath string arithmetic. | `[DONE]` | `src/Service/Payment/LedgerService.php` |
| `FEAT-LED-003` | **Idempotency Mutex Lock** | `SELECT ... FOR UPDATE` row locks on `(merchant_id, reference_type, reference_id, description)`. | `[DONE]` | `src/Service/Payment/LedgerService.php` |
| `FEAT-LED-004` | **Refund Fee Proration** | Exact original fee-to-amount ratio proration during partial and full refunds. | `[DONE]` | `src/Service/Payment/LedgerService.php`, `src/Service/Payment/RefundService.php` |
| `FEAT-LED-005` | **Real-Time Balance Reconciliation** | Automated reconciliation verifying transaction revenue sums against ledger balances. | `[DONE]` | `src/Service/Payment/ReconciliationService.php`, `src/Controller/Admin/ReconciliationController.php` |

---

### 6. Payment Intents & State Machine

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-INT-001` | **Atomic Intent Lifecycle** | Strict state machine (`pending -> processing -> completed / failed / cancelled / expired`). | `[DONE]` | `src/Service/Payment/PaymentIntentService.php`, `src/Enum/TransactionStatus.php` |
| `FEAT-INT-002` | **Anti-Double-Charge Lock** | `claimPendingForPay()` atomically acquires pending records, blocking concurrent clicks. | `[DONE]` | `src/Repository/TransactionRepository.php` |
| `FEAT-INT-003` | **Safe Back-Navigation & Retry** | `reactivateForRetry()` auto-reverts abandoned intents after 10m and clears gateway lock. | `[DONE]` | `src/Repository/PaymentIntentRepository.php`, `src/Repository/TransactionRepository.php` |
| `FEAT-INT-004` | **Stale Intent Expiration Cron** | Background cron auto-expires uncompleted intents exceeding configured TTL windows. | `[DONE]` | `src/Cron/CronJobRunner.php`, `src/Repository/PaymentIntentRepository.php` |

---

### 7. Mobile Companion App & SMS Verification Engine

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-MOB-001` | **Hardware-Pinned Device Pairing** | 6-digit OTP pairing with rate limiting and hardware UUID binding in `op_paired_devices`. | `[DONE]` | `src/Service/Device/DevicePairingService.php`, `src/Controller/Admin/DeviceController.php` |
| `FEAT-MOB-002` | **Two-Tier JWT Auth Engine** | Short-lived access tokens (`typ=access`, 1-day) and long-lived refresh tokens (`typ=refresh`, 30-day). | `[DONE]` | `src/Service/Auth/JwtService.php`, `src/Controller/Api/Mobile/NotificationController.php` |
| `FEAT-MOB-003` | **Smart Regex SMS Parser** | Multi-carrier pattern analyzer extracting Amount, TrxID, and Sender phone from bank SMS. | `[DONE]` | `src/Service/Sms/SmsParserService.php`, `src/Service/Sms/SmartSmsAnalyzer.php` |
| `FEAT-MOB-004` | **Carrier SMS Deduplication** | Fingerprint hashing and timestamp proximity guards prevent duplicate SMS settlement. | `[DONE]` | `src/Repository/SmsDataRepository.php`, `src/Cron/SmsVerificationJob.php` |
| `FEAT-MOB-005` | **Cross-Device IDOR Shield** | Strict hardware `device_uuid` filtering on mobile notification acknowledgments. | `[DONE]` | `src/Repository/MobileNotificationRepository.php` |

---

### 8. Enterprise Security & Cryptography

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-SEC-001` | **AES-256-GCM Field Encryption** | Authenticated PII encryption for customer names, phones, emails, and gateway keys. | `[DONE]` | `src/Security/FieldEncryptor.php` |
| `FEAT-SEC-002` | **Argon2id Password Security** | Password hashing with automatic memory and time cost upgrades upon login. | `[DONE]` | `src/Security/Authenticator.php` |
| `FEAT-SEC-003` | **Atomic Password Reset Claim** | Single-transaction token claim with SHA-256 hashing and automated API key revocation. | `[DONE]` | `src/Service/Auth/PasswordResetService.php`, `src/Repository/PasswordResetRepository.php` |
| `FEAT-SEC-004` | **Container Immutability** | Individual service locking and `$container->freeze()` seals the DI container post-boot. | `[DONE]` | `src/Kernel.php`, `src/Container.php` |
| `FEAT-SEC-005` | **Replay-Proof Webhook Signatures** | 300s timestamp freshness tolerance and SHA-256 body hash nonce caching. | `[DONE]` | `src/Middleware/RequestSignatureMiddleware.php` |
| `FEAT-SEC-006` | **Tamper-Evident Audit Hash Chain** | HMAC-SHA256 forward hash chains (`prev_hash`) in `op_audit_logs` detect database tampering. | `[DONE]` | `src/Repository/AuditLogRepository.php`, `src/Service/System/AuditService.php` |

---

### 9. Staff Management & Granular RBAC

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-RBAC-001` | **Custom Role-Based Access Control** | Fine-grained permission assignments (`op_roles`, `op_role_permissions`). | `[DONE]` | `src/Controller/Admin/RolesController.php`, `src/Repository/RoleRepository.php` |
| `FEAT-RBAC-002` | **Brand Assignment Isolation** | Staff users strictly restricted to their assigned `merchant_id` context. | `[DONE]` | `src/Middleware/PermissionMiddleware.php`, `src/Service/Brand/BrandContext.php` |
| `FEAT-RBAC-003` | **HTTP Method Permission Upgrading** | State-changing verbs (`POST`, `PUT`, `PATCH`, `DELETE`) auto-upgrade required check to `.manage`. | `[DONE]` | `src/Middleware/PermissionMiddleware.php` |
| `FEAT-RBAC-004` | **Two-Factor Authentication (TOTP)** | RFC 6238 TOTP two-factor authentication with encrypted secret storage. | `[DONE]` | `src/Service/Auth/TotpService.php`, `src/Controller/Admin/TwoFactorSetupController.php` |

---

### 10. Developer REST APIs & Webhooks

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-API-001` | **Payment Intents REST API** | `POST /api/v1/payment-intents` for creating, inspecting, and cancelling intents. | `[DONE]` | `src/Controller/Api/PaymentIntentController.php` |
| `FEAT-API-002` | **Transactions REST API** | `GET /api/v1/transactions` with filtering, searching, and pagination. | `[DONE]` | `src/Controller/Api/TransactionController.php` |
| `FEAT-API-003` | **Invoices REST API** | `POST /api/v1/invoices` for programmatic invoice creation, management, and voiding. | `[DONE]` | `src/Controller/Api/InvoiceController.php` |
| `FEAT-API-004` | **Asynchronous Webhook Dispatcher** | HMAC-SHA256 signed event delivery to merchant backends with automatic retry queuing. | `[DONE]` | `src/Service/Notification/WebhookDispatcher.php` |
| `FEAT-API-005` | **Developer Testing Console** | Admin diagnostic tool to generate signatures, test payloads, and inspect webhook logs. | `[DONE]` | `src/Controller/Admin/DeveloperController.php`, `templates/admin/developer/index.twig` |

---

### 11. Transaction Management & Manual Approval

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-TXN-001` | **Transaction Explorer & Filters** | Paginated transaction listing with status chips, date pickers, and gateway filters. | `[DONE]` | `src/Controller/Admin/TransactionController.php`, `templates/admin/transactions/index.twig` |
| `FEAT-TXN-002` | **Admin Manual Transaction Approval** | Manually approve and mark any non-terminal transaction (`pending`, `processing`, `awaiting_verification`) as `completed` with automated double-entry ledger bookkeeping. | `[DONE]` | `src/Controller/Admin/TransactionController.php`, `templates/admin/transactions/edit.twig`, `src/Service/Payment/PaymentCompletionListener.php` |
| `FEAT-TXN-003` | **Admin Manual Cancellation** | Manually cancel any active non-terminal transaction safely. | `[DONE]` | `src/Controller/Admin/TransactionController.php`, `templates/admin/transactions/edit.twig` |
| `FEAT-TXN-004` | **Partial & Full Refund Engine** | Process refunds with maximum balance validation, gateway API dispatch, and ledger proration. | `[DONE]` | `src/Controller/Admin/RefundController.php`, `src/Service/Payment/RefundService.php` |
| `FEAT-TXN-005` | **Automatic Payment Intent Sync** | Manual transaction approval automatically synchronizes linked payment intent and invoice statuses to `completed` / `paid`. | `[DONE]` | `src/Service/Payment/PaymentCompletionListener.php` |

---

### 12. Invoicing, Currencies, Disaster Recovery & Telemetry

| Feature ID | Feature Name | Description & Technical Layer | Status | Implementation Ref / Files |
| :--- | :--- | :--- | :---: | :--- |
| `FEAT-INV-001` | **Dynamic Invoicing Engine** | Recalculates line items, subtotals, and taxes dynamically with BCMath precision. | `[DONE]` | `src/Service/Payment/InvoiceService.php`, `src/Controller/Admin/InvoiceController.php` |
| `FEAT-CUR-001` | **Dynamic Currency Conversion** | Real-time exchange rate sync via background cron and multi-currency checkout support. | `[DONE]` | `src/Service/Payment/CurrencyService.php`, `src/Cron/CurrencyUpdateJob.php` |
| `FEAT-SYS-001` | **Disaster Recovery & Backups** | Single-click compressed database and filesystem backup generator. | `[DONE]` | `src/Update/BackupService.php`, `src/Controller/Admin/BackupController.php` |
| `FEAT-SYS-002` | **Cryptographically Signed Updater** | Self-updater verifying release archives against official RSA public keys. | `[DONE]` | `src/Update/UpdateService.php`, `src/Controller/Admin/UpdateController.php` |
| `FEAT-I18N-001` | **Dynamic Translation Editor** | In-browser language manager with brand-scoped overrides and fallback hierarchies. | `[DONE]` | `src/Service/System/TranslationService.php`, `src/Controller/Admin/TranslationController.php` |
| `FEAT-TEL-001` | **System Health Diagnostics** | Real-time monitor checking PHP extensions, directory permissions, database, and Redis. | `[DONE]` | `src/Controller/Admin/SystemHealthController.php`, `templates/admin/system/health.twig` |

---

## 🚀 Upcoming & Planned Roadmap Backlog

| Feature ID | Feature Name | Target Domain | Priority | Target Milestone | Description |
| :--- | :--- | :--- | :---: | :---: | :--- |
| `FEAT-PLN-001` | **One-Click Gateway API Re-Query** | Gateways / Admin | High | `v0.2.1` | Admin button on in-flight `processing` transactions to query the external gateway's inquiry API directly on demand. |
| `FEAT-PLN-002` | **Plugin Marketplace Integration** | Plugins | Medium | `v0.3.0` | In-admin browser to discover and install community gateways from `plugin.ownpay.org`. |
| `FEAT-PLN-003` | **Customer Self-Service Receipt Portal** | Checkout / Customer | Low | `v0.3.0` | Dedicated magic-link authenticated portal for customers to view and download past payment receipts. |
| `FEAT-PLN-004` | **WebPush Notification Alerts** | Mobile & Notifications | Medium | `v0.3.0` | Browser WebPush API integration for instant desktop/mobile payment alert notifications. |

---

## 📝 Update Protocol for AI Agents

Whenever you introduce, refactor, or complete a feature in OwnPay:

1. Locate the feature or add a new entry under the relevant domain table.
2. Mark its status as `[DONE]`.
3. Include the exact technical mechanism, file references, and relevant commit hash.
4. Run the quality verification suite (`vendor/bin/phpstan analyse`, `vendor/bin/phpunit`, `npm test`).
