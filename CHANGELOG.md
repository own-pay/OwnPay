# Changelog

All notable changes to OwnPay are documented in this file.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).  
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.1.0] — 2026-06-30

> **Initial public release.** OwnPay is a self-hosted, enterprise-grade, open-source payment
> gateway platform built on PHP 8.3+. A single owner operates the full system; multiple
> brands (stores) run under isolated custom domains — each with its own gateways, ledger,
> customers, and visual identity.

---

### Added

#### Core Framework

- Lightweight **PSR-11 dependency injection container** with reflection-based auto-wiring and singleton/transient lifecycle support.
- 10-step **Kernel boot cycle**: environment loading → container build → timezone config → plugin boot → middleware stack → `system.boot` event → route compilation → request matching → controller dispatch → `system.shutdown` event.
- **WordPress-style hook/filter system** (`EventManager`) with named action queues and priority-ordered filter chains, available to every plugin.
- **PSR-4 autoloading** of all application code under the `OwnPay\` namespace (`src/`).
- **Graceful last-resort error renderer** (`ErrorPageRenderer`) — fully inline HTML, zero service dependencies, renders 500/503/maintenance pages even when Twig, the database, or the DI container is broken.
- **Maintenance mode** with segment-aware path whitelist (`/admin`, `/login`, `/webhook`, `/cron`, `/checkout`) so gateway callbacks continue processing and the operator can still log in during downtime.

#### Multi-Brand Architecture

- **Single-owner, multi-brand model**: one super-administrator manages N brands (`op_merchants`), each isolated by `merchant_id` across every scoped database table.
- **`TenantScope` trait** — enforces brand data isolation at the query layer; provides `forTenant()`, `forAllTenants()`, `paginateScoped()`, `findScoped()`, `createScoped()`, `updateScoped()`, `deleteScoped()`.
- **`BrandContext` service** — resolves active brand from request, handles the All-Brands (platform) scope, exposes `getPlatformId()` and `getWriteMerchantId()` to prevent data cross-contamination.
- **Reserved platform-owner row** (`is_platform = 1`, slug `__platform__`) seeded by migration `013_add_platform_owner.sql`; excluded from all brand selectors.
- **Brand-scoped settings cascade**: `SettingsRepository::getScoped()` resolves brand override → All-Brands global default → code default.
- **Per-brand RBAC** — `op_roles` + `op_role_permissions`; `brands.access_all` permission gates cross-brand (All-Brands) access. `PermissionMiddleware::resolvePermission()` maps every admin route to its required permission slug.

#### White-Label Custom Domains

- **`DomainMiddleware`** — resolves every inbound `HTTP_HOST` against `op_domains`; injects `merchant_id`, `custom_domain`, and `domain_type` into request attributes; returns HTTP 404 for `/admin/*` paths on custom domains; returns HTTP 503 for domains pending DNS verification.
- **`DomainUrlService`** — single source of truth for all customer-facing and gateway-facing URL construction with priority: `GATEWAY_CALLBACK_URL` env → brand custom domain → `APP_URL` → request host → `https://localhost`.
- DNS verification workflow with `dns_verified` flag; unverified domains are fully blocked.
- `APP_DOMAIN` bare-hostname env var used as the master-domain sentinel.

#### Installer Wizard

- Four-step guided installation: directory permission checks → database probing + schema build → superadmin & default brand creation → key generation + `.env` write + `storage/.installed` lock marker.
- Installer middleware group is **database-independent** by design — no session, settings, or DB-backed rate-limiter middleware; `RateLimiterMiddleware` wraps DB access in try/catch for graceful bypass.
- Base64-safe `.env` parsing via `vlucas/phpdotenv` (avoids `parse_ini_file` breakage on base64 `APP_KEY`/`ENCRYPTION_KEY`).

#### Admin Panel

- Responsive admin panel with dark-mode, glassmorphism UI, and mobile-aware sidebar navigation.
- Sidebar order: Dashboard → Payments → Gateways → People → Mobile & SMS → Reports & Finance → Developers → Appearance → System → Account.
- Dashboard with real-time KPIs: total revenue, pending transactions, active gateways, and brand health indicators.
- **`AdminPageTrait`** — centralises template rendering, brand-scoped logo/favicon injection, and `requireGlobalView()` guard for All-Brands-only operations.
- Full CRUD admin pages: Brands, Staff, Roles & Permissions, Customers, Gateways, API Keys, Invoices, Payment Links, Transactions, Manual Gateways, Webhooks/IPN, Audit Log, System Settings, Currency Management, Exchange Rates, Reports, Appearance / Branding, Landing Page CMS, and Dispute Management.

#### Payment Gateway Plugin System

- **123 bundled gateway plugins** covering global, regional, and crypto payment networks, including:
  - **Southeast Asia**: bKash API, Nagad Merchant API, SSLCommerz, PortWallet, ShurjoPay, AamarPay, CellFin, NexusPay, Rocket, uPay, OK Wallet, GCash, Maya, GrabPay, ShopeePay, OVO, DANA, Touch 'n Go, PromptPay, TrueMoney, QRIS (Midtrans).
  - **South Asia**: Razorpay, Cashfree, Instamojo, CCAvenue, PayTM, PhonePe, MobiKwik, JazzCash, EasyPaisa.
  - **Africa**: M-Pesa, MTN MoMo, Flutterwave, Paystack, Airtel Money, Orange Money, Fawry, MyFatoorah, OPay.
  - **Latin America**: MercadoPago, MercadoLibre Wallet, EBANX, dLocal, Pix (Brazil), PagSeguro, PayU, Kushki.
  - **Europe / Global**: Stripe, PayPal Checkout, Adyen, Braintree, Klarna, Mollie, Authorize.Net, Square, 2Checkout, Checkout.com, Worldpay, CyberSource, BlueSnap, Heartland, NMI, Rapyd, Wise, Worldline, GoCardless, iDEAL, Bancontact, Sofort, Giropay, Trustly, Przelewy24, BLIK, EPS, Skrill, Neteller, Paddle, FastSpring.
  - **Crypto**: Binance Merchant API, Binance Personal, BitPay, BTCPay, Coinbase Commerce, OpenNode, NowPayments, OxaPay.
  - **Middle East & Pacific**: Tap Payments, PayTabs, Payme (Israel), PayStation (NZ), Moneris (CA), First Data, Fiserv, Elavon, FattMerchant, Stax, Helcim, PayTrace, TrustCommerce, TSYS, Chase Paymentech, Payment Depot, Biller Genie, Amazon Pay, Apple Pay, Google Pay, Kakaopay, Toss, Billplz, PayFast, Sezzle, Afterpay, Affirm.
- **Plugin manifest schema** (`manifest.json`) with slug validation, entrypoint declaration, type whitelist (`gateway|addon|theme`), optional `routes`, `cron`, `admin_menu`, `namespace`, `migrations`, and `csp` keys.
- **Footgun scanner** (`PluginSandbox`) — token-scans every plugin PHP file at load time and blocks `eval`, `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`.
- **Dynamic plugin logo resolution** (`PluginManager::resolveIconPath()`) — copies gateway icons into `public/assets/img/gateways/` on demand; rejects non-image extensions.
- Plugin activation, deactivation, and uninstall lifecycle with database migration execution.
- Per-brand plugin activation state enforced through `EventManager` owner stack.
- **Multi-file plugin support**: PSR-4 autoloader registered per plugin, realpath-contained to the plugin directory.

#### Manual (Offline) Gateway System

- **Platform template + per-brand account** model: All-Brands view defines the gateway type (slug, name, logo, input schema, currency limits, SMS rules); each brand adds its own account row (payment instructions, QR code).
- `ManualGatewayRepository::listActiveForCheckout()` — collapses template + brand account into one row per slug at checkout; brand account always wins over platform template.
- `GatewayController::createManual` guarded by `requireGlobalView()`.

#### Double-Entry Ledger Engine

- Full **GAAP-compliant double-entry bookkeeping** (`op_ledger_accounts`, `op_ledger_transactions`, `op_ledger_entries`).
- Every journal entry posts balanced debit/credit pairs; balance is maintained as a schema-level constraint.
- GAAP directionality enforced: Asset/Expense balances increase on debit; Liability/Equity/Revenue balances increase on credit.
- Ledger accounts strictly scoped to brand via `LedgerRepository::findOrCreateAccount()`.
- Mutex-locked transaction posting to prevent race-condition double-posting.
- Refund processing integrated into the ledger with atomic DB transactions and balance validation (`RefundService`).

#### Checkout & Payment Flows

- **Payment Intent checkout** (`/checkout/{token}`) — branded, white-labeled single-page checkout with per-brand CSS variables, custom CSS/JS injection, and checkout hook triggers.
- **Invoice system** — line-item invoices with dynamic subtotal/total recalculation; `op_invoice_items` purged and reinserted on every update to prevent orphan records.
- **Payment links** (`/pay/{slug}`) — shareable, reusable payment pages with configurable amounts.
- **Dispute management** — dispute creation, status tracking, and resolution workflow.
- **Gateway callback lease** — atomic `status: processing → callback_processing` UPDATE prevents concurrent double-capture; lease is released on soft failure so later retries can still complete.
- **Webhook IPN processor** (`WebhookInboundProcessor`) — processes inbound gateway webhooks, accepts `callback_processing` transactions as completable, with retry queue and audit-log integrity tracking.
- **Dynamic Content Security Policy** built from active gateway manifest `csp` declarations only (never all 123 bundles); 7,500-byte failsafe prevents FastCGI header overflow on shared hosting.
- **CSRF protection** on all mutation endpoints via `CsrfMiddleware` and `SecurityHelpers::csrfToken()`.

#### Multi-Currency & Exchange Rates

- Currency management with `op_currencies` (ISO 4217) seeded for all major currencies; `decimal_places` per currency.
- `op_exchange_rates` — `base_currency`/`target_currency` UNIQUE pair with manual and automated rate update support.
- `CurrencyService::convert()` — pivots through system base currency at checkout for automatic gateway currency conversion.
- Immutable audit trail fields (`original_amount`, `original_currency`, `exchange_rate`, `converted_amount`, `converted_currency`) persisted in `op_transactions.metadata`.
- `supportedCurrencies(): array` on every gateway adapter; empty array = any currency via `GatewayDefaults` trait; BDT-only gateways (bKash, Nagad) explicitly declare `['BDT']`.

#### Companion Mobile App & SMS Verification

- **Android companion app** backend: device pairing via OTP exchange, JWT issuance and rotation, JTI blacklisting in `op_cache`.
- `op_paired_devices` — stores paired device UUID, last heartbeat, and revocation state.
- **`JwtAuthMiddleware`** — validates JWT tokens against live database state; revoked/deactivated devices are denied immediately.
- **SMS verification engine** (`SmsVerificationJob`) — matches inbound bank/gateway SMS against pending transactions by provider transaction ID (exact) or amount + gateway + time window (single unambiguous brand match only; ambiguous multi-brand amounts are refused).
- **All-Brands device scope**: a platform-scoped device matches SMS globally across all brands with safe cross-brand re-attribution via `SmsParsedRepository::rebindToBrand()`.
- Real-time device online status (180s heartbeat threshold) driven by companion `POST /api/mobile/v1/devices/heartbeat`.
- SMS template management — platform-owned global templates created in All-Brands view; device sender whitelist includes platform templates so All-Brands templates apply to every paired device.

#### Security Hardening

- **Argon2id** password hashing for all admin and staff accounts.
- **TOTP two-factor authentication** (`two_factor_enabled`, `totp_secret_enc`) with replay protection.
- **Self-service password reset** — 256-bit random tokens; only SHA-256 hash stored in `op_password_resets`; single-use, 1-hour TTL, DB-clock freshness; no account enumeration on the forgot-password surface.
- **Rate limiting** (`RateLimiterMiddleware`) on authentication, password reset, OTP, and sensitive API endpoints.
- **`SecurityHeadersMiddleware`** — injects HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, and dynamic CSP on every response.
- **Parameterized PDO queries** enforced throughout; SQL string concatenation is strictly prohibited.
- Route parameter regex constraints prevent route-based injection; `{identifier}` is the sole exception allowing `@`, `+`, `%` for customer email/phone lookup.
- All secrets loaded from environment variables; `.env` excluded from version control.
- `window.opCopyText()` global clipboard helper in the admin panel — HTTP/HTTPS compatible clipboard fallback to prevent JavaScript clipboard API failures on non-secure local contexts.

#### API Layers

- **Merchant REST API** — authenticated via API key (`op_api_keys`); payment initiation, transaction status, refund, and webhook management endpoints.
- **Mobile API** (`/api/mobile/v1/`) — JWT-authenticated companion device endpoints: pairing, heartbeat, SMS submission, notification acknowledgment with device-UUID–scoped IDOR prevention.
- **Admin API** — internal admin-facing endpoints for live dashboard data and device status polling.
- **OpenAPI 3.1.1 specification** at `docs/v2/api/openapi.yaml`; interactive public API tester bundled in `docs/v2/api/`.
- **Webhook retry system** — failed webhook deliveries are queued and retried.

#### Notification & Email System

- Event-driven email notifications (`EmailNotificationService`) triggered on `payment.transaction.completed` and `refund.created`.
- Per-brand sender identity (`mail_from_email`, `mail_from_name`) resolved via the brand → global settings cascade.
- Email failure is fully isolated inside try/catch and never disrupts payment completion.
- Transactional email templates: `payment_completed.twig`, `refund_created.twig`, `password_reset.twig`.

#### Fee Rules Engine

- `op_fee_rules` — brand-scoped commission configuration with four-tier specificity resolution: Brand+Gateway → Brand+Any Gateway → Global+Gateway → Global+Any Gateway.
- Currency is an exact `CHAR(3)` match; no currency-agnostic fallback.
- `FeeRuleRepository::resolveActiveRule()` resolves the most specific active rule at transaction time; ties broken by newest rule ID.

#### Brand Theme Engine

- `BrandThemeService` — resolves per-brand visual identity with field-level priority: brand-scoped `op_system_settings` → `op_merchants.settings` JSON → global settings.
- Fields: `name`, `logo`, `favicon`, `color`, `accent_color`, `support_email`, `custom_css`, `custom_js`, `footer_text`, `show_powered_by`.
- Per-brand logo and favicon uploaded to `public/assets/uploads/brands/`.
- Custom CSS/JS injected raw into checkout templates; theme hook triggers fire via `checkout.head` and `checkout.footer` filters.
- **`own-pay` default theme plugin** bundled under `modules/themes/own-pay`.

#### Addon Plugins

- **`mail-gateway` addon** — pluggable transactional mail transport.
- **`sms-gateway` addon** — pluggable outbound SMS transport for OTP and notifications.
- **`telegram-bot` addon** — Telegram bot integration for real-time payment notifications.
- **`example-kit` addon** — developer reference addon demonstrating the plugin API.

#### Developer Tools & Observability

- **Audit log** (`op_audit_log`) — tamper-evident event trail for all security-relevant operations with integrity hashing.
- **Cron runner** (`CronJobRunner`) — declarative cron scheduling via plugin manifests; `RefundReconciliationJob` reconciles gateway refund states on schedule.
- **CLI tools** (`cli/`) — developer command-line utilities.
- **Balance verification report** — cross-checks ledger balances against transaction totals.
- **Multi-language (i18n) system** — file-based translation loading with auto-recovery directory management.
- **Contributors dashboard** — live GitHub contributors data visualisation in the admin panel.
- **Landing page CMS** — full-featured public marketing page with admin-editable content blocks.
- **PHPStan Level 9** static analysis enforced across the entire codebase with zero allowed errors.
- **390+ PHPUnit test cases** covering business logic, ledger invariants, plugin lifecycle, security controls, and regression guards.
- **Twig CS Fixer**, **ESLint**, and **Stylelint** integrated for template, JS, and CSS quality gates.
- **WooCommerce gateway plugin** (`ownpay-wordpress.zip`) for WordPress/WooCommerce e-commerce integration.

#### Database Schema

- All tables prefixed with `op_`; strict naming conventions enforced (e.g. `two_factor_enabled`, `totp_secret_enc`, `decimal_places`, `base_currency`, `target_currency`, `match_status`, `device_id`).
- **Stored Generated Columns** on `op_transactions` — `invoice_id` and `payment_link_id` extracted from `metadata` JSON as indexed `BIGINT UNSIGNED STORED` columns for hot-path lookups without JSON extraction overhead.
- `op_system_settings` — unified key-value store replacing the decommissioned legacy SQLite `op_env` table; nullable `merchant_id` with `uk_group_key_merchant` unique constraint enables brand-scoped overrides alongside global defaults.
- `op_password_resets` — OWASP-aligned token table: `token_hash CHAR(64) UNIQUE`, `used_at` (NULL until consumed), `expires_at`, `user_id FK → op_merchant_users ON DELETE CASCADE`.
- `op_cache` — generic key/TTL cache store; used for JTI blacklisting and rate limiter state.
- 16 sequential migration files (`database/migrations/`) bootstrapping the complete schema and seeding default data.
- **Decommissioned and purged**: `op_settlements`, `op_settlement_items`, `SettlementService`, `SettlementRepository`.

---

### Dependencies

| Package | Version | Purpose |
|---|---|---|
| `php` | `^8.3` | Runtime requirement |
| `twig/twig` | `^3.26` | Auto-escaped server-side templating |
| `vlucas/phpdotenv` | `^5.6` | Environment variable loading |
| `firebase/php-jwt` | `^7.0` | Mobile companion JWT issuance and validation |
| `ramsey/uuid` | `^4.9` | UUID generation for devices and tokens |
| `chillerlan/php-qrcode` | `^5.0` | QR code generation for manual gateways |
| `ext-bcmath` | `*` | Arbitrary-precision financial arithmetic |
| `ext-openssl` | `*` | Encryption, TOTP, and HMAC operations |
| `phpstan/phpstan` | `^2.1` | Static analysis at Level 9 |
| `phpunit/phpunit` | `12.5.29` | Unit and integration test suite |
| `vincentlanglet/twig-cs-fixer` | `^3.14` | Twig template linting and formatting |

---

### Notes

This is the **initial release** — there is no prior version to upgrade from.  
For a fresh installation, deploy the codebase and run the guided installer wizard at `/install`.

> **License:** AGPL-3.0-or-later

[0.1.0]: https://github.com/own-pay/ownpay/releases/tag/v0.1.0
