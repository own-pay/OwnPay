# OwnPay Feature Reference

Complete reference of every capability built into OwnPay. For architectural deep-dives, see [ARCHITECTURE.md](ARCHITECTURE.md). For the REST API schema, see [docs.ownpay.org](https://docs.ownpay.org). For guides and tutorials, see [learn.ownpay.org](https://learn.ownpay.org).

---

## 🏆 Flagship — Sovereign White-Label Architecture

> **OwnPay is the first self-hosted payment platform to implement a full white-label, multi-brand, custom-domain checkout architecture on a single installation.**
>
> No other self-hosted payment gateway does this.

Here is exactly what it means:

- **One server, one installation** — owned by one super-admin.
- **Multiple brands** — create unlimited stores (brands). Each is a fully isolated entity with its own custom domain, logo, name, color scheme, gateways, customers, ledgers, and staff.
- **Custom domain per brand** — map `pay.yourbrand.com` to a brand. OwnPay verifies ownership via a DNS TXT record (`_ownpay-verification.{domain}`) and confirms routing via A-record check, then activates the domain.
- **Invisible infrastructure** — when a customer reaches checkout, they land on `pay.yourbrand.com`. They see the brand's logo, name, and colors. There is no mention of OwnPay, no shared domain, no "powered by" indicator unless the brand explicitly adds one.
- **Admin panel stays private** — the admin dashboard is only accessible on the master installation domain. Custom brand domains return 404 for all `/admin/*` paths.
- **Complete data isolation** — every customer record, transaction, ledger entry, gateway credential, and webhook secret is scoped per brand via `merchant_id` in the database. Zero data bleed between brands.
- **Brand-aware URLs** — all checkout URLs, gateway callback URLs, and webhook endpoints are automatically built using the brand's custom domain via `DomainUrlService`. The installation domain never leaks into customer-facing flows.

**The result:** One server. Multiple brands. Each brand is a complete, independent, fully custom-branded payment gateway — invisible to each other and invisible to OwnPay.

---

## 1. Multi-Brand Management

| Feature | Description |
|:---|:---|
| Unlimited brands | Create any number of brands, each with name, slug, email, logo, timezone, and currency |
| Brand switching | Super-admin switches brand context from a single dashboard |
| Brand isolation | All data strictly scoped per brand by `merchant_id`; no cross-brand access possible |
| Per-brand settings | Brand-specific config overrides system defaults (JSON key-value) |
| Brand deactivation | Suspend a brand without data loss |

---

## 2. Custom Domain & White-Label Pipeline

| Feature | Description |
|:---|:---|
| Custom domain mapping | Register any domain against a brand in `op_domains` |
| DNS TXT verification | `_ownpay-verification.{domain}` TXT record proves domain ownership |
| A-record check | Confirms domain routes to the OwnPay server before activation |
| Automated DNS re-check | `DnsVerificationJob` cron re-checks pending/broken domains on a schedule |
| Domain middleware | Every request resolves `HTTP_HOST` against active domains, injects brand context |
| Admin path blocking | `/admin/*` paths return 404 on all custom brand domains |
| Brand URL builder | `DomainUrlService` builds all checkout, callback, and redirect URLs using the brand's domain |
| Domain cache | In-memory cache avoids repeated DB lookups within a request cycle |

---

## 3. Brand Theming & Customization

| Feature | Description |
|:---|:---|
| Logo & favicon | Per-brand logo and favicon (uploaded via admin) |
| Primary & accent colors | Per-brand hex color values applied to the checkout UI |
| Custom CSS | Inject arbitrary CSS into checkout pages per brand |
| Custom JavaScript | Inject arbitrary JS into checkout pages per brand |
| Support email | Per-brand customer-facing support address on checkout pages |
| Footer text | Custom footer text on all public-facing brand pages |
| Theme plugins | Install visual theme plugins to replace the default checkout skin per brand |
| Hierarchical fallback | Brand value → merchant value → system default |

---

## 4. Payment Gateway System

| Feature | Description |
|:---|:---|
| 123 built-in gateways | Covers global cards (Stripe, Adyen, Braintree, Authorize.net, Square, Worldpay, Cybersource, Checkout.com, Shift4, NMI, Moneris), wallets (Apple Pay, Google Pay, Alipay, PayPal), mobile financial services (bKash, Nagad, M-Pesa, MTN MoMo, GCash, Dana, GrabPay, PhonePe), buy-now-pay-later (Klarna), crypto (Coinbase Commerce, BTCPay, Bitpay), regional platforms (Razorpay, Cashfree, CCAvenue, PayU, Flutterwave, Paystack, MyFatoorah, Midtrans, Xendit, Rapyd, dLocal, Mercado Pago, Tap, Toss, Trustly, Przelewy24, Neteller, Skrill, Wise, GoCardless, 2checkout, Amazon Pay, and more) |
| Plugin-based architecture | Every gateway lives in `modules/gateways/{slug}/`; add providers without touching core |
| Manual gateways | Custom offline methods (bank transfer, cash, mobile money) with configurable fields |
| Per-brand activation | Enable different gateways per brand with separate credential sets |
| Encrypted credentials | Gateway API keys stored AES-256-GCM encrypted at rest |
| `GatewayAdapterInterface` | Standard contract: `initiate`, `verify`, `verifyWebhook`, `refund`, `supports`, `supportedCurrencies` |
| `GatewayDefaults` trait | No-op defaults so adapters only implement what they support |
| Express checkout | Pre-filled quick payment submission |

---

## 5. Payment Processing

| Feature | Description |
|:---|:---|
| Payment intents | Atomic payment requests with a unique token, amount, currency, expiry, and metadata |
| Intent lifecycle | `pending → processing → completed / failed / cancelled / expired` |
| Token-based checkout | Checkout URL contains only a secure token; amount and gateway details never exposed in URL |
| Intent expiry | Cron auto-marks stale intents as expired and fires hooks |
| Multi-currency checkout | Customer can pay in any enabled currency |
| Auto currency conversion | If a gateway supports only specific currencies, the intent amount is converted automatically |
| Conversion audit trail | Original amount, converted amount, and exchange rate logged on the transaction record |
| Status polling | `GET /checkout/{token}/status` AJAX endpoint for real-time status updates |
| Manual verification | SMS or offline gateway transactions verified manually by admin |

---

## 6. Transaction Management

| Feature | Description |
|:---|:---|
| Immutable transaction record | Every payment creates a permanent record with OwnPay trx_id, gateway trx_id, amounts, fees, and status |
| 13 statuses | `pending`, `created`, `processing`, `callback_processing`, `completed`, `failed`, `cancelled`, `expired`, `refunded`, `disputed`, `awaiting_verification`, `pending_review` |
| Admin status override | Super-admin can manually adjust transaction status with full audit trail |
| Transaction filters | Filter by status, gateway, date range, amount, customer |
| CSV export | Export filtered transaction sets for accounting |
| Fee tracking | `amount`, `fee`, and `net_amount` all stored separately per transaction |

---

## 7. Refunds

| Feature | Description |
|:---|:---|
| Partial refunds | Issue any amount up to the original transaction value |
| Full refunds | One-click full refund routed to the original gateway |
| Refund lifecycle | `pending → completed / failed` |
| Gateway routing | Refund sent to the same gateway that processed the original payment |
| Refund reconciliation | `RefundReconciliationJob` cron syncs refund status from gateway APIs |
| Audit trail | Reason, actor, and timestamps stored per refund |
| Ledger posting | Refund auto-posted as balanced debit/credit in the double-entry ledger |

---

## 8. Invoicing

| Feature | Description |
|:---|:---|
| Numbered invoices | Line-item invoices (description, quantity, unit price) with auto-calculated subtotal, tax, discount, and total |
| Customer linking | Optional customer reference on each invoice |
| Invoice checkout | Customer pays via public URL at `/invoice/{token}` |
| Status tracking | `draft → sent → paid / overdue / cancelled` |
| Due date & notes | Configurable due date, internal notes, and unique invoice number per merchant |

---

## 9. Payment Links

| Feature | Description |
|:---|:---|
| Reusable shareable links | Create URLs for recurring or product payments |
| Custom slug | Memorable URL path (e.g., `/pay/annual-subscription`) |
| Fixed or variable amount | Set exact amount or let customer choose within min/max bounds |
| Custom fields | Collect name, email, account ID, or any data before payment |
| Usage limits | Cap total accepted payments (link auto-expires after N uses) |
| Expiration date | Optional date after which the link no longer accepts payment |
| Per-link redirect | Custom success and cancellation redirect URLs per link |

---

## 10. Customer Management

| Feature | Description |
|:---|:---|
| Customer records | UUID, encrypted name, email, phone, metadata |
| PII encryption | Customer fields encrypted AES-256-GCM at rest; email and phone stored as hash for lookup |
| Brand isolation | Each customer belongs to exactly one brand; no cross-brand access |
| Customer history | View all transactions, invoices, and payment links for a customer |
| REST API access | Create, fetch, and list customers via the Merchant API |
| Deduplication | Email/phone hash prevents creating duplicate customer records |

---

## 11. Double-Entry Ledger & Accounting

| Feature | Description |
|:---|:---|
| GAAP double-entry | Every financial event posts balanced debit/credit pairs |
| Standard directionality | Assets & Expenses debit (+); Liabilities, Equity & Revenue credit (+) |
| Account types | Asset, Liability, Equity, Revenue, Expense |
| Multi-currency accounts | Separate ledger accounts per currency per brand |
| Automatic posting | Payments, refunds, and fees auto-posted on completion |
| Trial balance | Verify total debits equal total credits at any point |
| Ledger history | Query all entries per account with date-range filters |
| Balance verification | `BalanceVerificationJob` cron cross-checks ledger vs gateway balances |
| bcmath precision | All monetary math uses bcmath strings — never floats |

---

## 12. Fee Rules

| Feature | Description |
|:---|:---|
| Flat fees | Fixed amount per transaction |
| Percentage fees | Percentage of transaction value |
| Tiered fees | Different rates per amount bracket |
| Min/max caps | Set a minimum or maximum fee amount |
| Scoping | Per brand, per gateway, or system-wide fallback |
| Automatic application | `FeeService` applies rules during payment before ledger posting |

---

## 13. Currency & Exchange Rates

| Feature | Description |
|:---|:---|
| 180+ currencies | Enable/disable world currencies per brand |
| Exchange rate table | `op_exchange_rates`: base, target, rate, source, updated_at |
| Manual rate entry | Admin sets rates manually |
| Auto-sync | `CurrencyUpdateJob` fetches live rates on a schedule |

---

## 14. Webhooks

| Feature | Description |
|:---|:---|
| Merchant webhook endpoints | Register URLs to receive real-time payment events |
| Event subscriptions | Subscribe each webhook endpoint to specific event types |
| HMAC-SHA256 signing | Each delivery signed with merchant's secret; receiver validates |
| Delivery tracking | Status, response code, duration, and error per delivery attempt |
| Retry with backoff | Failed webhooks retried with exponential backoff by `WebhookRetryJob` |
| Dead letter queue | Permanently failed webhooks queued for manual replay |
| Manual replay | Admin can replay any failed webhook delivery |
| Inbound deduplication | Gateway callbacks deduplicated by payload hash (idempotent processing) |
| Unified inbound handler | Single `POST /webhook/{gateway}` endpoint handles all 123 gateway callbacks |

---

## 15. Disputes & Chargebacks

| Feature | Description |
|:---|:---|
| Dispute lifecycle | `open → under_review → won / lost → closed` |
| Evidence upload | Attach documents and screenshots as dispute evidence |
| Resolution tracking | Who resolved it, when, and the outcome |
| Transaction link | Every dispute tied to the originating transaction |
| Ledger adjustment | Financial impact posted on resolution |

---

## 16. Audit & Compliance

| Feature | Description |
|:---|:---|
| Immutable audit log | Every action logged with actor, entity, old/new values, IP, and timestamp |
| Cryptographic signing | Each log entry signed to detect post-creation tampering |
| Integrity scanner | Admin can run a scan to detect any modified log entries |
| Activity tracking | Login/logout history, permission changes, config updates |
| PII masking | Emails and phone numbers masked in all log output |

---

## 17. Authentication & Access Control

| Feature | Description |
|:---|:---|
| Email/password login | Standard credential-based admin login |
| Configurable login slug | Admin login URL slug configurable for obscurity |
| Two-factor authentication | TOTP (RFC 6238) via authenticator app; per-staff enable/disable |
| Password reset | Token-based email link for forgotten passwords |
| Login attempt tracking | Failed logins logged per IP for rate-limiting and audits |
| Session management | Sessions in `op_sessions` with IP, user ID, last-activity |
| Bearer API keys | Scoped keys (read / write / admin) with last-used tracking and revocation |
| JWT (mobile) | JSON Web Tokens issued after device pairing, with refresh support |
| RBAC | Custom roles per brand with granular permission assignment |
| Staff management | Create staff with role assignment per brand; suspend/activate accounts |
| IP allowlist | Optional IP-based access restriction for admin routes |

---

## 18. Mobile Companion App API

| Feature | Description |
|:---|:---|
| OTP device pairing | Admin generates OTP; device exchanges it for JWT + AES session key |
| Multiple devices | Staff can pair phone and tablet simultaneously |
| Device management | View, revoke, or bulk-revoke paired devices from admin |
| Heartbeat | Devices send periodic heartbeat; server tracks online/offline status |
| Mobile dashboard | Today's revenue, pending transactions, recent payment summary |
| Push notifications | Server pushes `transaction.completed`, `refund.issued`, `dispute.filed` events to devices |
| Notification acknowledgement | Device marks notifications read; server tracks unread count |
| Token refresh | JWT refresh endpoint extends sessions without re-pairing |
| SMS privacy filter | Config rules tell the app which SMS senders/keywords to ignore |

---

## 19. SMS-Based Payment Verification

| Feature | Description |
|:---|:---|
| Mobile SMS forwarding | Paired mobile device forwards incoming SMS to `POST /api/mobile/v1/sms` |
| Regex parser | Per-gateway regex template extracts amount, sender, and trx ID from SMS body |
| Heuristic parser | Fallback AI-pattern parser when regex doesn't match |
| Smart analyzer | Combines regex + heuristic with confidence scoring |
| Transaction matching | Parsed SMS auto-linked to pending transaction by amount and timestamp |
| Payment confirmation | Matched SMS marks the pending manual gateway payment as complete |
| SMS log | `op_sms_data` stores all parsed SMS with match status |
| SMS template editor | Admin UI to create, test, and manage per-gateway regex templates |
| Regex tester | Test a regex pattern against a sample SMS body without processing a real payment |

---

## 20. Plugin System

| Feature | Description |
|:---|:---|
| Plugin types | Gateway, Theme, Addon |
| Manifest-based discovery | Each plugin declares metadata, capabilities, and hooks in `manifest.json` |
| Static code audit | Source scanned for dangerous calls (`exec`, `shell_exec`, `eval`, `passthru`, raw PDO) before loading |
| Sandboxed execution | Plugin runtime isolated; exceptions caught and logged without crashing the system |
| Manual installation | Admin uploads ZIP; system extracts, validates, and installs |
| Per-brand activation | Enable plugins globally or only for specific brands |
| Plugin migrations | Plugins can include database migrations run on install |
| Settings UI | Each plugin registers its own settings page under `/admin/plugins/{slug}/settings` |
| Hook integration | Plugins register `doAction` / `applyFilter` callbacks via manifest |
| Uninstall with cleanup | Removes plugin files, data, and migrations on uninstall |

---

## 21. Event Hook System

| Feature | Description |
|:---|:---|
| Actions (`doAction`) | Fire events; plugins and addons listen and react |
| Filters (`applyFilter`) | Transform values in-flight (e.g., modify payment amount, alter checkout output) |
| Built-in events | `system.boot`, `system.shutdown`, `payment.intent.created`, `payment.intent.expired`, `payment.completed`, `payment.failed`, `refund.created`, `dispute.created`, `customer.created`, `domain.mapped`, `domain.verified`, `domain.removed` |
| Plugin registration | Hooks declared in plugin manifest or registered programmatically |

Full hook reference: [learn.ownpay.org/hooks-reference](https://learn.ownpay.org/hooks-reference)

---

## 22. Scheduled Jobs (Cron)

| Job | Purpose |
|:---|:---|
| `WebhookRetryJob` | Retry failed outbound webhook deliveries with exponential backoff |
| `QueueWorkerJob` | Process background job queue (emails, exports, bulk operations) |
| `BalanceVerificationJob` | Cross-check ledger balances against gateway-reported balances |
| `CurrencyUpdateJob` | Fetch and update live exchange rates |
| `SmsVerificationJob` | Process pending SMS verifications for manual gateways |
| `RefundReconciliationJob` | Sync refund status from gateway APIs |
| `DnsVerificationJob` | Re-check DNS records for pending custom domains |
| `UpdateCheckJob` | Poll for new OwnPay releases |
| `SystemUpdateJob` | Auto-apply update if configured |

Triggered by `GET /cron/{secret}`. Individual jobs can also be run manually from the admin settings panel.

---

## 23. Background Job Queue

| Feature | Description |
|:---|:---|
| Async processing | Push long-running tasks to queue (emails, exports, webhook sends) |
| Storage backends | File-based (default) or Redis |
| Retry with backoff | Configurable max attempts with exponential delay between retries |
| Scheduled jobs | Set `available_at` to delay job execution |
| Error logging | Exceptions logged per attempt without dropping the job |

---

## 24. Internationalization (i18n)

| Feature | Description |
|:---|:---|
| Full admin panel i18n | Every admin UI string is translatable |
| Full checkout i18n | Customer-facing checkout pages fully translatable |
| Dot-notation keys | Flat JSON format (`menu.dashboard`, `common.actions.save`) |
| Placeholder support | Dynamic values via `:name`, `:amount` syntax |
| Per-staff language | Staff set their own language preference |
| System default language | Admin sets the fallback language for all sessions |
| Translation import | Upload flat or nested JSON file; system auto-flattens nested keys |
| Translation editor | Inline admin UI to edit strings key-by-key |
| Filesystem cache | Compiled to `storage/languages/{code}.json` for fast loads |
| English fallback | Missing keys fall back to `config/languages/en.json` |

---

## 25. Security

| Feature | Description |
|:---|:---|
| AES-256-GCM encryption | Customer PII and gateway credentials encrypted at rest |
| Argon2id passwords | Staff and admin passwords hashed with Argon2id |
| bcrypt fallback | Legacy bcrypt support for upgrade compatibility |
| CSRF protection | Per-session CSRF tokens; all POST/PUT/DELETE require a valid token |
| Content Security Policy | Per-request nonce; strict policy blocks unauthorized inline scripts |
| HSTS | HTTP Strict Transport Security header enforced |
| X-Frame-Options | `DENY` — prevents clickjacking |
| X-Content-Type-Options | `nosniff` — prevents MIME sniffing |
| Referrer-Policy | Limits referrer information leakage |
| SSRF protection | `UrlValidator` resolves and pins webhook URLs to validated public IPs; redirect-following blocked |
| Rate limiting | Per-route, per-IP sliding window; 429 with `Retry-After` header |
| Login throttling | Strict bucket for login, password reset, and OTP endpoints |
| Hash-based PII search | Email/phone stored as hash; lookups never require decryption |
| Log sanitization | `LogSanitizer` strips passwords, tokens, and card data from all log output |
| SQL injection prevention | Prepared statements with parameter binding throughout |
| XSS prevention | Twig autoescaping always on; `|raw` only on trusted content |

---

## 26. Self-Update Engine

| Feature | Description |
|:---|:---|
| Version tracking | Current version in config; `op_update_history` logs all applied updates |
| Update manifest | Polls update server for available release metadata |
| Pre-update backup | Automatic database + file backup before applying any update |
| SHA-256 + RSA verification | Download verified by checksum and RSA signature before extraction |
| Zip-slip guard | Extraction blocks path traversal attacks in release archives |
| Schema migrations | DB changes in the release applied automatically |
| Rollback | Restores from backup if verification fails |
| Maintenance mode | System enters maintenance during update; exits automatically on success |
| Manual + auto modes | Admin triggers manually or enables auto-update via cron |

---

## 27. System Settings

| Category | Settings |
|:---|:---|
| General | App name, timezone, base currency, maintenance mode |
| Branding | Logo, favicon, company name, footer text, support email |
| Theme | Primary color, accent color, custom CSS, custom JS |
| Mail | SMTP driver, host, port, from address, reply-to, templates |
| SMS | SMS provider, sender ID |
| Payment | Default gateway, payment timeout, fee defaults |
| Developer | API rate limits, webhook limits, test key generation |
| Language | Default language, translation management |
| Cache | Clear all caches |
| Database | Optimize tables |
| Logs | Archive and clean old logs |

---

## 28. Developer Tools

| Feature | Description |
|:---|:---|
| Developer hub | Admin UI at `/admin/developer` for API and webhook tooling |
| Webhook tester | Send test payloads to merchant webhook endpoints from admin |
| API key management | Generate, list, and revoke API keys |
| Rate limit display | View current API rate limit configuration |
| Webhook delivery logs | Inspect delivery attempts, response codes, and errors |
| Health check endpoint | `GET /api/v1/health` — confirms database, cache, and queue status |
| CSP violation reporting | Collect and log Content Security Policy violations |
| Custom login slug | Set admin login URL to any custom slug |

---

## 29. Installation

| Feature | Description |
|:---|:---|
| Web-based installer | Multi-step `/install` wizard — no CLI or SSH required |
| Database connection test | Tests connection before importing schema |
| Schema import | Creates all 48 tables automatically |
| Admin account creation | Creates super-admin during install |
| Key generation | Generates `APP_KEY`, `ENCRYPTION_KEY`, and `JWT_SECRET` |
| Install lock | Writes `storage/.installed` to prevent re-running the wizard |
| Shared hosting support | No SSH or Composer required — `vendor/` bundled in release archives |

---

## 30. REST API Surface

Three independent API layers, each with distinct authentication:

| Layer | Prefix | Auth |
|:---|:---|:---|
| Merchant API | `/api/v1/*` | Bearer API key (read / write scopes) |
| Mobile Companion API | `/api/mobile/v1/*` | JWT (after device pairing) |
| Admin API | `/api/admin/v1/*` | Bearer API key (admin scope) |

Full API schema and request/response examples: **[docs.ownpay.org](https://docs.ownpay.org)**

---

## Platform at a Glance

| Metric | Value |
|:---|:---|
| Built-in payment gateways | 123 |
| Database tables | 48 |
| REST API endpoints | 37+ |
| Web routes | 120+ |
| Scheduled jobs | 9 |
| Middleware components | 16 |
| Supported currencies | 180+ |
| PHP required | 8.3+ |
| License | AGPL-3.0 |

---

*For contribution guidelines, see [CONTRIBUTING.md](../CONTRIBUTING.md). For security reporting, see [SECURITY.md](../SECURITY.md). For architecture details, see [ARCHITECTURE.md](ARCHITECTURE.md).*
