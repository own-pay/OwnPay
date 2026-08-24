# OwnPay - Complete Feature & Capability Reference

> **The definitive directory of all built-in features, architectural capabilities, and platform mechanics in OwnPay.**

OwnPay is a self-hosted, sovereign payment orchestration platform engineered in modern PHP 8.3+. It gives merchants complete ownership over their payment checkout pipelines, custom domains, customer data, and accounting ledgers without relying on third-party SaaS middle-tier platforms.

---

## 📑 Feature Navigation Directory

1. [Sovereign White-Label & Custom Domain Engine](#1-sovereign-white-label--custom-domain-engine)
2. [Multi-Brand / Multi-Store Management](#2-multi-brand--multi-store-management)
3. [Payment Gateway & Plugin Architecture](#3-payment-gateway--plugin-architecture)
4. [Checkout Experiences & UI Modules](#4-checkout-experiences--ui-modules)
5. [Double-Entry Accounting & GAAP Ledger](#5-double-entry-accounting--gaap-ledger)
6. [Payment Intents & Atomic State Machine](#6-payment-intents--atomic-state-machine)
7. [Mobile Companion App & SMS Engine](#7-mobile-companion-app--sms-engine)
8. [Enterprise Security & Cryptography](#8-enterprise-security--cryptography)
9. [Staff Management & Granular RBAC](#9-staff-management--granular-rbac)
10. [Developer REST APIs & Webhook Dispatcher](#10-developer-rest-apis--webhook-dispatcher)
11. [Customer Management & PII Data Lifecycle](#11-customer-management--pii-data-lifecycle)
12. [Invoicing Engine & Dynamic Line Items](#12-invoicing-engine--dynamic-line-items)
13. [Multi-Currency & Real-Time Exchange Rates](#13-multi-currency--real-time-exchange-rates)
14. [Disaster Recovery, Backups & Self-Updater](#14-disaster-recovery-backups--self-updater)
15. [Internationalization (i18n) & Localization](#15-internationalization-i18n--localization)
16. [Theme Customization & Brand Styling](#16-theme-customization--brand-styling)
17. [Background Queue & Rate Limiting Engine](#17-background-queue--rate-limiting-engine)
18. [Diagnostic Telemetry, Audit Logs & Health Monitoring](#18-diagnostic-telemetry-audit-logs--health-monitoring)

---

## 1. Sovereign White-Label & Custom Domain Engine

OwnPay allows a single server installation to host unlimited independent brands, each operating under its own fully isolated custom domain.

| Capability | Technical Mechanism | Benefit |
| :--- | :--- | :--- |
| **Custom Domain Mapping** | Resolves `HTTP_HOST` in `DomainMiddleware` against `op_domains` records. | Brand-specific checkout URLs (e.g. `pay.mystore.com`). |
| **Automated DNS Verification** | Verifies ownership via `_ownpay-verification.{domain}` TXT and A-records. | Prevents domain hijacking and misrouting. |
| **Dynamic URL Construction** | `DomainUrlService` constructs checkout, webhook, and status URLs dynamically. | Primary server domain is never exposed to customers. |
| **Private Admin Hardening** | `DomainMiddleware` throws a hard **404 Not Found** for `/admin/*` on custom domains. | Admin dashboard is strictly restricted to master `APP_DOMAIN`. |
| **Dynamic Frame Ancestors** | Scopes CSP `frame-ancestors` to DNS-verified merchant domains. | Blocks clickjacking while allowing safe embedded checkouts. |

---

## 2. Multi-Brand / Multi-Store Management

OwnPay is a **single-owner, multi-brand platform** (NOT a public SaaS). One super-administrator manages multiple internal stores with zero cross-brand data leakage.

| Capability | Technical Mechanism | Benefit |
| :--- | :--- | :--- |
| **Tenant Scoping** | `TenantScope` repository trait scopes all database CRUD operations via `merchant_id`. | Mathematical isolation across brands in a shared database. |
| **The "All Brands" Platform Scope** | Reserved `is_platform = 1` merchant row (`__platform__`) resolved via `BrandContext::getPlatformId()`. | Clean ownership for platform-wide API keys and global ledger accounts. |
| **Single-Click Brand Switcher** | Super-admins switch active brand contexts seamlessly from the admin header. | Manage multiple distinct business entities from a single pane of glass. |
| **Per-Brand Configuration** | Scoped settings in `op_system_settings` with fallback to platform defaults. | Brand-level currency, timezone, logo, and fee overrides. |

---

## 3. Payment Gateway & Plugin Architecture

Modular, sandboxed plugin system hosting 123+ payment providers and unlimited custom manual gateways.

| Capability | Technical Mechanism | Benefit |
| :--- | :--- | :--- |
| **123+ Built-In Gateways** | Global cards (Stripe, Adyen, Braintree, Square, Checkout.com), wallets (Apple Pay, Google Pay, PayPal), mobile money (bKash, Nagad, M-Pesa), crypto (Coinbase, BTCPay), and regional gateways. | Accept payments globally with zero code changes. |
| **Manual / Offline Gateways** | Dynamic forms in `op_manual_gateways` (Bank Transfer, Cash, Agent Cash-in). | Collect manual payments with customizable proof-of-payment fields. |
| **`GatewayAdapterInterface`** | Standard contract: `initiate()`, `verify()`, `verifyWebhook()`, `refund()`. | Rapid development of new custom gateway adapters. |
| **Plugin Sandbox Verification** | AST token parser (`PluginSandbox`) blocks `eval`, `shell_exec`, backticks, and direct SQL. | Protects the host server from malicious or vulnerable plugins. |
| **AES-256-GCM Credential Vault** | Gateway API keys and secrets stored encrypted at rest via `FieldEncryptor`. | Full compliance with PCI-DSS storage requirements. |

---

## 4. Checkout Experiences & UI Modules

Conversion-optimized, mobile-first checkout screens designed to wow customers and maximize payment completion.

| Capability | Technical Mechanism | Benefit |
| :--- | :--- | :--- |
| **Hosted Checkout** | `/checkout/intent/{token}` standalone, branded checkout screen. | Frictionless payments with instant gateway selection. |
| **Popup / Modal Checkout** | Embeddable JavaScript modal (`admin.js` / `checkout.js`). | Seamless payment flow without navigating away from the merchant store. |
| **Sharable Payment Links** | Single-use or multi-use payment links (`op_payment_links`) with usage caps. | Collect payments via social media, chat, or direct messaging. |
| **WCAG 2.2 Accessibility** | Full ARIA dialog semantics, focus capture, focus restoration, and keyboard traps. | Fully compliant and accessible for all users and assistive devices. |
| **Mobile Zoom & Ergonomics** | Minimum 40px touch targets and full user pinch-to-zoom support (WCAG 1.4.4). | Flawless user experience across iOS and Android devices. |

---

## 5. Double-Entry Accounting & GAAP Ledger

Bank-grade financial bookkeeping guaranteeing that every single cent is mathematically accounted for.

| Capability | Technical Mechanism | Benefit |
| :--- | :--- | :--- |
| **Triple-Table Architecture** | Segregated `op_ledger_accounts`, `op_ledger_transactions`, `op_ledger_entries`. | Pure immutable ledger without overwriting balances. |
| **Debit === Credit Invariant** | Checked with BCMath (`bccomp()`) at 4-decimal precision on decimal strings. | Zero floating-point rounding errors and zero unbalanced books. |
| **GAAP Directionality** | Standard asset, liability, equity, expense, and revenue direction rules. | Direct compatibility with enterprise ERP and accounting standards. |
| **Transaction Mutex Locking** | `SELECT ... FOR UPDATE` row locks on `(merchant_id, reference_type, reference_id, description)`. | Completely eliminates double-posting on webhook retries. |
| **Automated Fee Proration** | Refunds calculate and reverse exact collected platform fee ratios. | Accurate fee reversals during partial or full refunds. |

---

## 6. Payment Intents & Atomic State Machine

Deterministic state transitions preventing payment race conditions and double charges.

| State | Transition Trigger | Verification Rule |
| :--- | :--- | :--- |
| **`pending`** | Initial payment request created. | Generates cryptographically unique 32-byte intent token. |
| **`processing`** | Customer redirects to selected gateway. | Locks intent to specific gateway adapter slug. |
| **`completed`** | Gateway confirms payment via webhook/return. | Verified via signature/checksum and books double-entry ledger entry. |
| **`failed`** | Gateway rejects payment. | Logs error reason and permits customer retry. |
| **`expired`** | Intent exceeds configured TTL window. | Automatically marked expired by background cron job. |
| **`refunded`** | Full or partial refund approved. | Reverses ledger balance and updates invoice status. |

---

## 7. Mobile Companion App & SMS Engine

Autonomous offline payment settlement via companion Android application and regex SMS parser.

| Capability | Technical Mechanism | Benefit |
| :--- | :--- | :--- |
| **Hardware-Pinned Pairing** | Cryptographic 6-digit OTP pairing with rate limits and UUID binding. | Secure mobile companion synchronization. |
| **Two-Tier JWT Auth** | 1-day `typ=access` and 30-day `typ=refresh` tokens with device binding. | Safe mobile communication without storing raw passwords. |
| **Autonomous SMS Parsing** | Regex pattern engine (`SmsParserService`) extracts amount, TrxID, and sender. | Automatic match and settlement of P2P mobile money transfers. |
| **Duplicate Deduplication** | Content fingerprinting and ±1s timestamp matching. | Rejects duplicate carrier SMS notifications instantly. |

---

## 8. Enterprise Security & Cryptography

Defense-in-depth system hardening meeting ISO-27001 and PCI-DSS static coding standards.

| Control | Implementation | Protection |
| :--- | :--- | :--- |
| **PII Encryption** | AES-256-GCM authenticated encryption (`FieldEncryptor.php`). | Protects customer phone numbers, addresses, and API credentials. |
| **Password Hashing** | Argon2id with automatic memory/time cost upgrades (`Authenticator.php`). | Resistance to GPU-based password cracking. |
| **Atomic Password Resets** | Single-transaction atomic consumption with SHA-256 token hashing. | Closes TOCTOU race conditions and revokes active API keys. |
| **Container Immutability** | Individual service locking followed by global `$container->freeze()`. | Blocks runtime service hijacking or malicious plugin injection. |
| **Replay-Proof Webhooks** | 300-second timestamp freshness window + SHA-256 body hash nonce caching. | Eliminates webhook replay attacks. |
| **Tamper-Evident Audit Trail** | HMAC-SHA256 forward hash chains (`prev_hash`) in `op_audit_logs`. | Immediate detection if an attacker alters or deletes audit rows. |

---

## 9. Staff Management & Granular RBAC

Role-based access control allowing super-administrators to delegate brand management safely.

| Capability | Technical Mechanism |
| :--- | :--- |
| **Granular Roles** | Custom roles stored in `op_roles` with fine-grained permission assignments (`op_role_permissions`). |
| **Brand Assignment** | Staff members are assigned to specific `merchant_id` contexts; blocked from unauthorized brands. |
| **Method Upgrading** | `PermissionMiddleware` automatically upgrades required permission from `.view` to `.manage` on state-changing HTTP verbs (`POST`, `PUT`, `PATCH`, `DELETE`). |
| **Cross-Brand Session Guard** | Resets session active brand to home brand if a staff user attempts cross-brand URL traversal. |

---

## 10. Developer REST APIs & Webhook Dispatcher

Developer-first API suite allowing seamless integration with any website, app, or backend service.

| Endpoint Group | Functionality |
| :--- | :--- |
| **Payment Intents API** | `POST /api/v1/payment-intents` (create, retrieve, cancel payment intents). |
| **Transactions API** | `GET /api/v1/transactions` (query, filter, and paginate transaction history). |
| **Invoices API** | `POST /api/v1/invoices` (create, update, send, and void customer invoices). |
| **Refunds API** | `POST /api/v1/refunds` (process partial and full refunds with ledger proration). |
| **Webhook Delivery** | Asynchronous HMAC-SHA256 signed event delivery (`payment.completed`, `refund.created`, etc.). |
| **Developer Console** | Admin testing tool to generate signatures, test payloads, and inspect logs. |

---

## 11. Customer Management & PII Data Lifecycle

Full customer relationship records scoped per brand with encrypted personal data.

- **Customer Profiles**: Name, email, phone, billing address, and transaction summary.
- **Payment History**: Real-time aggregation of lifetime value, successful payments, and refunds.
- **PII Lifecycle Protection**: Customer data fields are encrypted at rest; exports sanitize sensitive information.

---

## 12. Invoicing Engine & Dynamic Line Items

Built-in professional invoice generator and payment collection system.

- **Dynamic Line Items**: Dynamic subtotal, tax, discount, and grand total calculations via BCMath.
- **Hosted Invoice Views**: Direct customer view (`/invoice/{uuid}`) with instant payment buttons.
- **PDF Generation & Email**: Transactional email dispatch with branded HTML summaries.

---

## 13. Multi-Currency & Real-Time Exchange Rates

Native global currency engine with dynamic exchange rate conversions.

- **ISO-4217 Currency Support**: 160+ world currencies with configurable decimal places.
- **Dynamic Exchange Rate Sync**: Automated background cron updating exchange rates via live feeds.
- **Transparent Conversion Audit**: Logs original currency, conversion rate, and settled currency on transaction records.

---

## 14. Disaster Recovery, Backups & Self-Updater

Zero-downtime maintenance, automated backup generators, and signed system self-updaters.

- **Single-Click Backups**: Compresses database DDL/data and configuration into encrypted zip archives.
- **Cryptographically Signed Updates**: Self-updater validates packages against OwnPay official public RSA keys.
- **Instant Maintenance Mode**: Emits standard HTTP 503 responses with `Retry-After` headers and bypass allowances.

---

## 15. Internationalization (i18n) & Localization

Complete translation engine supporting multi-lingual admin panels and checkout screens.

- **Dynamic Translation Editor**: Manage language strings directly from the admin panel.
- **Fallback Hierarchy**: Custom brand translation -> Language pack -> English system default.
- **RTL & Unicode Ready**: Full UTF-8 support for Arabic, Bengali, Cyrillic, and Asian scripts.

---

## 16. Theme Customization & Brand Styling

Complete visual control over checkout screens and public pages.

- **CSS Design Tokens**: Centralized variables for colors, border radius, typography, and shadows.
- **Per-Brand Custom CSS / JS**: Inject custom styles or analytics tags per brand.
- **Theme Plugins**: Drop-in theme packages in `modules/themes/` extending default Twig templates.

---

## 17. Background Queue & Rate Limiting Engine

High-performance task queue and sliding-window protection.

- **Redis & File-Based Queues**: Robust job dispatching with automatic retries and dead-letter handling.
- **Sliding-Window Rate Limiter**: IP and route-specific limits protecting auth endpoints from brute-force attacks.
- **Fail-Closed Security**: Rate limiter fails closed on state-changing requests if Redis/DB is unavailable.

---

## 18. Diagnostic Telemetry, Audit Logs & Health Monitoring

Comprehensive developer tools and observability infrastructure.

- **System Health Monitor**: Real-time diagnostics checking PHP extensions, permissions, database, and Redis.
- **Tamper-Evident Audit Viewer**: Interactive audit trail viewer highlighting integrity status in real time.
- **Balance Verification Tool**: Real-time reconciliation comparing transaction sums against ledger balances.

---

## 💡 Summary

OwnPay combines the flexibility of an open-source self-hosted tool with the architectural rigor, financial integrity, and security standards of a multi-million-dollar fintech platform.
