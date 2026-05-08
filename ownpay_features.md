# Comprehensive OwnPay Platform Features

This document outlines every single feature, module, and user flow within the OwnPay platform. It serves as a complete platform requirements and capabilities document for the V2 architecture.

---

## 1. Multi-Brand / Store Management (Core Architecture)
- **Features details:** A single administrative installation hosts multiple isolated brands (stores). It is a Single-Owner, Multi-Brand model. Each brand operates independently with its own gateways, customers, transactions, API keys, and custom domains. Self-registration is disabled; the platform owner provisions everything.
- **Features user flow:** Admin logs in -> Navigates to Brands -> Clicks "Add New Brand" -> Enters brand details (name, base currency, timezone, support email) -> Saves. Admin uses the top navigation bar dropdown to instantly switch context between active brands.
- **Features Ranking:** Critical
- **Technical flow:** Request hits the application -> `BrandContext` service resolves the active brand from the user's session (UI) or API token (API) -> The `TenantScope` trait automatically appends `WHERE merchant_id = X` to all database repository queries (transactions, customers, etc.), guaranteeing data isolation.

## 2. Staff & Role-Based Access Control (RBAC)
- **Features details:** The platform owner invites staff members and assigns them to specific brands with granular role-based permissions. Roles dictate what modules (e.g., refunds, ledger, settings) are accessible.
- **Features user flow:** Admin navigates to Staff -> Clicks "Invite Staff" -> Enters email, name, assigns a Role (e.g., Manager, Support), and selects the target Brand -> System emails staff to set a password.
- **Features Ranking:** High
- **Technical flow:** User authenticates -> Session stores `auth_role_id` -> When accessing a route, `PermissionMiddleware` intercepts -> Checks required capability against permissions mapped in `op_roles` and `op_role_permissions`. Superadmins bypass all RBAC checks.

## 3. Universal Hosted Checkout Flow
- **Features details:** A secure, mobile-responsive hosted checkout page where end-customers pay for transactions initiated via API, payment links, or invoices.
- **Features user flow:** Customer lands on `/checkout/{token}` -> Views order summary -> Selects a payment gateway -> For APIs: redirected to provider or enters card data. For Manual: sees payment instructions, enters sender account/TXN ID, and submits -> Customer is routed to Success, Failed, or Pending Review screen.
- **Features Ranking:** Critical
- **Technical flow:** API creates a `PaymentIntent` -> Generates a secure JWT-like token -> Customer accesses URL -> `CheckoutController` loads active gateways for the brand -> Submits payment -> Updates transaction status -> Post-payment hooks fire.

## 4. Automated API Gateways Management
- **Features details:** Integration with modern automated payment providers (e.g., Stripe, SSLCommerz).
- **Features user flow:** Admin navigates to Gateways -> Sees list of installed API gateway plugins -> Clicks "Configure" -> Enters provider credentials (API Keys, Secrets) -> Toggles gateway to Active.
- **Features Ranking:** Critical
- **Technical flow:** Gateway configs are encrypted (AES-256-GCM) via `FieldEncryptor` -> At checkout, `GatewayBridge` instantiates the specific plugin class -> Generates redirect URLs or inline forms -> Webhooks from provider trigger status changes.

## 5. Manual Gateways Management
- **Features details:** Support for offline or non-API payment methods (Bank Transfer, Cash on Delivery, Mobile Money like bKash/Nagad).
- **Features user flow:** Admin navigates to Gateways -> Clicks "Add Manual Gateway" -> Uploads a logo, names it, provides transfer instructions -> Defines required customer input fields (e.g., "Sender Number", "Transaction ID") -> Saves.
- **Features Ranking:** High
- **Technical flow:** Configuration saved in `op_manual_gateways` -> At checkout, renders a form matching the defined fields -> Customer submits -> Transaction status set to `Pending Review`.

## 6. Mobile App Companion Integration
- **Features details:** An Android companion app connects to the platform to act as a bridge for incoming SMS messages, primarily used for verifying manual mobile money transactions.
- **Features user flow:** Admin goes to Devices -> Clicks "Pair New Device" -> System generates a QR code -> Admin scans QR code with the OwnPay Android App -> App registers and connects, running in the background.
- **Features Ranking:** Critical (USP)
- **Technical flow:** Mobile App scans QR containing pairing token -> Calls `/api/mobile/v1/devices/pair` -> Receives long-lived JWT -> App sends periodic requests to `/api/mobile/v1/devices/heartbeat` to show online status in admin panel.

## 7. Automated SMS Verification & Reconciliation
- **Features details:** Automatically verifies manual gateway payments (like bKash/Nagad) by parsing bank SMS alerts forwarded by the Mobile App and matching them to customer-submitted transaction IDs.
- **Features user flow:** Customer submits manual payment with TXN ID -> Mobile App receives SMS from bank -> Forwards it to server -> Server matches TXN ID and Amount -> Auto-approves the payment without staff intervention.
- **Features Ranking:** Critical (USP)
- **Technical flow:** App POSTs to `/api/mobile/v1/sms` -> `SmsParserService` applies heuristics or regex templates -> Extracts Amount and TXN ID -> `ReconciliationService` queries transactions scoped to `Pending Review` -> If exact match, transitions to `Completed` -> Triggers ledger and webhooks.

## 8. SMS Center (Heuristic & Regex Templates)
- **Features details:** Admin interface to define exactly how the system should parse incoming SMS messages from different banks or mobile money providers.
- **Features user flow:** Admin navigates to SMS Center -> Templates -> Creates a template -> Defines Regex (e.g., `TrxID (?<trxid>\w+)`) or uses Heuristics -> Tests the rule against a sample SMS -> Saves.
- **Features Ranking:** High
- **Technical flow:** Incoming SMS string is run through active `op_sms_templates` -> Regex named capture groups extract variables (amount, fee, sender, trxid) -> Mapped to internal data structures.

## 9. SMS Inbox & Raw Data Logs
- **Features details:** Complete audit trail of every SMS received from connected mobile devices.
- **Features user flow:** Admin navigates to SMS Center -> SMS Data -> Views raw inbox of all messages forwarded by the app, color-coded by parsing success/failure.
- **Features Ranking:** Low
- **Technical flow:** Messages are stored in `op_sms_parsed` -> `match_status` enum tracks if the SMS successfully reconciled a transaction or was ignored.

## 10. Mobile Push Notification Queue
- **Features details:** System can send commands and push notifications down to the paired Android devices.
- **Features user flow:** System triggers a push event (e.g., "Device Revoked") -> Device pulls or receives notification.
- **Features Ranking:** Low
- **Technical flow:** Server queues message in `op_mobile_notifications` -> Device polls `/api/mobile/v1/notifications` -> Acknowledges receipt via `/api/mobile/v1/notifications/ack`.

## 11. Double-Entry Ledger Accounting
- **Features details:** A strict, immutable double-entry accounting system tracking every financial movement (Payments, Fees, Refunds, Settlements) to ensure absolute financial integrity.
- **Features user flow:** Admin navigates to Ledger -> Views Chart of Accounts (Assets, Liabilities, Equity, Revenue, Expenses) -> Clicks an account -> Views immutable journal entries (Debits/Credits) -> Views real-time verified balances.
- **Features Ranking:** Critical
- **Technical flow:** `LedgerService` fires post-payment -> Creates `LedgerTransaction` header -> Creates paired `LedgerEntry` rows. Database transaction enforces that `sum(debits) = sum(credits)`.

## 12. Ledger Balance Verification & Integrity Check
- **Features details:** A diagnostic tool to audit the entire ledger and mathematically prove that debits equal credits globally.
- **Features user flow:** Admin navigates to Balance Verification -> Clicks "Run Verification" -> System runs deep audit -> Displays "Passed" or highlights anomalies.
- **Features Ranking:** High
- **Technical flow:** Controller executes massive SQL aggregations -> Validates `SUM(debit) - SUM(credit) = 0` per transaction block -> Logs audit results.

## 13. Transaction Management & Status Tracking
- **Features details:** The core CRM for payments. Tracks lifecycle from Intent to Completion.
- **Features user flow:** Admin navigates to Transactions -> Views paginated list -> Filters by status/date/gateway -> Clicks transaction -> Views timeline, ledger entries, customer info -> Staff can manually mark `Pending Review` items as `Completed` or `Failed`.
- **Features Ranking:** Critical
- **Technical flow:** Transactions utilize the `TransactionStatus` enum -> State machine logic ensures valid transitions (cannot move from `Refunded` to `Completed`).

## 14. Invoicing System
- **Features details:** B2B billing solution to send itemized invoices directly to customers.
- **Features user flow:** Admin navigates to Invoices -> Create Invoice -> Selects Customer -> Adds line items (Item A $10, Item B $20) -> Sends email -> Customer receives email -> Clicks link -> Views PDF-like web invoice -> Pays via standard checkout.
- **Features Ranking:** High
- **Technical flow:** Invoice generates unique token -> When accessed, generates dynamic `PaymentIntent` matching the invoice total -> On checkout completion, invoice status updates to Paid.

## 15. Payment Links
- **Features details:** Reusable URL links for fixed or variable amounts, perfect for social media or chat sales.
- **Features user flow:** Admin navigates to Payment Links -> Creates link -> Sets fixed price or toggles "Customer enters amount" -> Copies URL -> Shares -> Customer clicks -> Enters name/email -> Pays.
- **Features Ranking:** High
- **Technical flow:** Slug-based routing (`/pay/{slug}`) -> Intercepts request -> Creates one-off `PaymentIntent` -> Funnels to universal checkout.

## 16. Custom Domain Management & DNS Verification
- **Features details:** Brands can white-label their checkout URLs by mapping custom domains.
- **Features user flow:** Admin switches to Brand X -> Domains -> Adds `pay.mybrand.com` -> UI displays required CNAME records -> Admin configures DNS -> Clicks "Verify" -> Checkout links dynamically update to use the custom domain.
- **Features Ranking:** Medium
- **Technical flow:** Domain added to `op_domains` -> `DnsVerifier` checks external DNS -> `DomainMiddleware` intercepts incoming web requests -> Maps `host` header to `merchant_id` to enforce brand context.

## 17. Developer API & Bearer Authentication
- **Features details:** REST API for merchants to programmatically integrate payments into their own platforms.
- **Features user flow:** Admin navigates to API Keys -> Generates a pair (Public/Secret) -> Developer uses Secret key as Bearer token -> Hits `/api/v1/payments/initiate` -> Receives checkout URL.
- **Features Ranking:** Critical
- **Technical flow:** `BearerAuthMiddleware` validates token -> Extracts associated `merchant_id` -> Injects into container for scoped processing -> Enforces API rate limits.

## 18. API Key Generation & Revocation
- **Features details:** Secure lifecycle management of API credentials.
- **Features user flow:** Admin navigates to API Keys -> Generates key -> System displays Secret Key *once* -> Admin copies it -> Admin can later click "Revoke" to instantly kill the key's access.
- **Features Ranking:** High
- **Technical flow:** Secret keys are generated cryptographically -> Stored securely -> Revocation marks key inactive, instantly blocking middleware auth.

## 19. Webhook System, Delivery Logs & Retries
- **Features details:** Real-time push notifications to external developer systems when payment statuses change.
- **Features user flow:** Developer registers endpoint -> Payment completes -> OwnPay sends POST payload to endpoint -> If endpoint is down, OwnPay retries. Admin can view exact request/response bodies and HTTP status codes in the UI.
- **Features Ranking:** High
- **Technical flow:** Event fires -> `WebhookDispatcher` queues job -> Worker POSTs payload with HMAC SHA-256 signature -> Logs delivery to `op_webhook_delivery_logs` -> If non-200 response, schedules exponential backoff retry via `WebhookRetryJob`.

## 20. Customer Relationship Management (CRM) & PII Masking
- **Features details:** Tracks all customers, their transaction history, and total lifetime value.
- **Features user flow:** Admin goes to Customers -> Clicks customer -> Views contact info and chronologically ordered past transactions.
- **Features Ranking:** Medium
- **Technical flow:** `CustomerRepository` upserts based on email/phone -> `CustomerPiiService` optionally masks emails (e.g., `j***@gmail.com`) based on the viewing staff member's RBAC privileges.

## 21. Refund Management
- **Features details:** Process full or partial refunds for completed transactions.
- **Features user flow:** Admin views a Completed Transaction -> Clicks "Refund" -> Enters amount and reason -> Submits -> Customer is refunded (if API gateway supports it) and ledger is updated.
- **Features Ranking:** High
- **Technical flow:** `RefundService` validates amount against remaining balance -> Calls Gateway Plugin `refund()` method -> On success, creates `op_refunds` record -> Books reversal entries in Ledger.

## 22. Fee & Settlement Engine
- **Features details:** Tracks platform fees vs merchant revenue, and manages payouts/settlements.
- **Features user flow:** Automatic background tracking. Admin can view Settlement reports to see exactly how much money is owed to a brand vs how much the platform keeps as fees.
- **Features Ranking:** High
- **Technical flow:** `FeeService` calculates percentages/fixed fees based on `op_fee_rules` -> Ledger splits the incoming funds: Credit Merchant Revenue Account, Credit Platform Fee Account -> `SettlementService` handles moving funds to Payout accounts.

## 23. Dispute Management
- **Features details:** Handling chargebacks or customer disputes initiated by external gateways (like Stripe).
- **Features user flow:** Webhook receives chargeback -> Transaction marked as Disputed -> Admin views Disputes panel -> Uploads evidence -> System submits evidence to gateway.
- **Features Ranking:** Low
- **Technical flow:** Webhook intercepts `chargeback.created` -> `DisputeService` creates `op_disputes` record -> Adjusts ledger to freeze funds.

## 24. Extensibility: Plugins, Themes, Addons
- **Features details:** Modular architecture allowing custom code execution without modifying core files.
- **Features user flow:** Admin goes to Plugins/Themes/Addons -> Uploads `.zip` -> System reads `manifest.json` -> Admin clicks Activate -> Features integrate instantly.
- **Features Ranking:** High
- **Technical flow:** Zip extracted to `modules/` -> `PluginLoader` discovers manifest -> `PluginManager` handles install/activate -> Boot process registers WordPress-style hooks via `EventManager` to safely inject UI or override logic.

## 25. Currencies & Exchange Rates Management
- **Features details:** Multi-currency support with dynamic or manual exchange rates.
- **Features user flow:** Admin navigates to Settings -> Currency -> Sets base system currency -> Enables supporting currencies (USD, BDT, EUR) -> Sets manual exchange rate or enables auto-fetching.
- **Features Ranking:** Medium
- **Technical flow:** `CurrencyService` formats money based on decimal configuration -> `CurrencyUpdateJob` runs via cron to fetch live rates -> Checkouts dynamically convert amounts if Gateway currency differs from Brand currency.

## 26. Global System Settings & Maintenance Mode
- **Features details:** Platform-wide configuration.
- **Features user flow:** Admin navigates to Settings -> Tabs for General, API, Currency, FAQ, Email, Security, Theme. Admin can check "Maintenance Mode" to take the checkout system offline.
- **Features Ranking:** High
- **Technical flow:** Stored in `op_system_settings`. `MaintenanceMiddleware` intercepts all non-admin routes and returns a 503 Maintenance page if enabled.

## 27. Two-Factor Authentication (2FA)
- **Features details:** TOTP-based security for staff accounts.
- **Features user flow:** Staff goes to My Account -> 2FA -> Scans QR code with Google Authenticator -> Enters code -> 2FA enabled. On next login, must provide 6-digit code. Superadmin can force 2FA globally.
- **Features Ranking:** High
- **Technical flow:** Utilizes Argon2id + TOTP libraries. `TwoFactorMiddleware` blocks access to admin routes if `$_SESSION['two_fa_enabled']` is true but the session hasn't verified the current login.

## 28. Immutable Audit Logging
- **Features details:** Tracks "Who did what, and when" for compliance.
- **Features user flow:** Admin goes to Activities/Audit -> Views timeline of all staff actions (e.g., "Staff A refunded TXN-123").
- **Features Ranking:** High
- **Technical flow:** `AuditLogger` intercepts Repository events -> Serializes the before/after state as a JSON diff -> Stores in `op_audit_logs`.

## 29. IP Allowlisting & Session Management
- **Features details:** Restrict admin access to specific office IPs, and configure timeout thresholds.
- **Features user flow:** Admin goes to Settings -> Security -> Enters IP addresses. Sets Session Timeout to 30 mins.
- **Features Ranking:** Medium
- **Technical flow:** `IpAllowlistMiddleware` checks `$_SERVER['REMOTE_ADDR']` against config. `SessionMiddleware` invalidates session if idle time exceeds configuration.

## 30. Reporting, Analytics & CSV Export
- **Features details:** Visual charts and raw data exports.
- **Features user flow:** Admin views Dashboard for charts (Revenue over time, Gateway breakdown). Navigates to Reports -> Selects date range -> Clicks "Export CSV" -> Downloads raw financial data.
- **Features Ranking:** Medium
- **Technical flow:** Controller executes grouped SQL queries for charts. CSV export streams data directly to response buffer to prevent memory exhaustion on large datasets.

## 31. System Health & Rate Limiting
- **Features details:** DDOS protection and API monitoring.
- **Features user flow:** API consumers hitting endpoints too fast receive HTTP 429 Too Many Requests.
- **Features Ranking:** High
- **Technical flow:** `RateLimiterMiddleware` tracks requests in Redis/File cache -> Blocks if threshold exceeded. `HealthController` provides a `/api/v1/health` endpoint for external uptime monitors.

## 32. Idempotency Key Engine
- **Features details:** Prevents duplicate payments if a network request drops and the client retries.
- **Features user flow:** Developer sends `Idempotency-Key` header with payment request. If sent twice, the second request returns the exact same cached response as the first without charging the card again.
- **Features Ranking:** High
- **Technical flow:** `IdempotencyBridge` intercepts API request -> Checks `op_idempotency_keys` -> If exists, halts execution and returns saved JSON response -> If new, processes payment and saves response against the key for 24 hours.

## 33. Self-Update System (OTA)
- **Features details:** Over-The-Air platform upgrades.
- **Features user flow:** Admin goes to System Update -> Checks for update -> Clicks Apply -> System updates core files and DB schemas.
- **Features Ranking:** Low
- **Technical flow:** Uses `UpdateCheckJob`. Downloads zip -> puts site in maintenance -> runs `schema.sql` diffs -> clears cache -> restores site.
