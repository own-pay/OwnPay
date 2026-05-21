# Own Pay v0.1.0 — Scorched-Earth Architecture Plan (Rev 3)

> **Version:** Rev 3 | **Date:** 2026-04-30 | **Status:** DRAFT — Awaiting Final Approval

---

## 1. Mandate Summary

Complete ground-up rewrite. Zero legacy code. Zero backward compat. Fresh `v0.1.0`.

**Pivots integrated (Rev 2 + Rev 3):**

| # | Pivot | Rev |
|---|-------|-----|
| 1 | Drop 46 gateways → 3 API refs + Dynamic Manual Gateway builder | Rev 2 |
| 2 | WordPress-paradigm plugin/theme ecosystem (hook/filter everywhere) | Rev 2 |
| 3 | Built-in SMS/Mail gateways, expandable via Addon plugins | Rev 2 |
| 4 | Telegram Bot Addon as reference POC | Rev 2 |
| 5 | Shared-hosting first, Redis graceful fallback | Rev 2 |
| 6 | Custom landing page, hideable login URL, responsive | Rev 2 |
| 7 | Feature parity + mobile API gap filling | Rev 2 |
| 8 | **Limitless plugin extensibility — hooks injected EVERYWHERE** | Rev 3 |
| 9 | **Checkout UI v2 HTML template → default Twig theme** | Rev 3 |
| 10 | **Enterprise fault-tolerant auto-updater with rollback** | Rev 3 |
| 11 | **Multi-brand custom domain / white-labeling** | Rev 3 |

---

## 2. Technology Decisions

| Component | Choice | Rationale |
|-----------|--------|-----------|
| Templates | Twig 3.x | XSS-safe, separation of concerns |
| DI Container | Custom lightweight (~200 lines) | Shared-hosting friendly |
| Admin SPA | Keep AJAX-loads | Proven; serve Twig fragments |
| API prefix | `/v1/` | Keep convention |
| Config | `.env` only (phpdotenv) | Drop `op-config.php` |
| Cache/Queue | File default, Redis optional | Shared-hosting first |
| CSS | Tailwind CLI (self-hosted) | No CDN |
| Plugin views | UI-agnostic (Twig OR PHP) | Dev choice |
| Update server | `https://update.ownpay.org/update.json` | Custom update API |

---

## 3. Directory Structure

```
ownpay/
├── public/                          # Web root
│   ├── index.php                    # Single front controller
│   ├── assets/                      # Compiled CSS/JS/images/fonts
│   └── .htaccess
├── config/
│   ├── app.php                      # Constants, version, timezone
│   ├── database.php                 # DB from .env
│   ├── routes/
│   │   ├── web.php                  # Admin + checkout + landing
│   │   └── api.php                  # REST API v1
│   ├── services.php                 # DI bindings
│   ├── hooks.php                    # 80+ core hook registration points
│   └── middleware.php               # Pipeline order
├── src/
│   ├── Kernel.php                   # Boot: DI → middleware → plugins → dispatch
│   ├── Container.php                # PSR-11 DI
│   ├── Controller/
│   │   ├── Admin/                   # Admin controllers
│   │   ├── Api/                     # REST API v1
│   │   ├── Checkout/                # Payment flow
│   │   ├── Webhook/                 # IPN receivers
│   │   └── Page/                    # Landing, login, error
│   ├── Middleware/
│   ├── Model/                       # Value objects, DTOs
│   ├── Repository/
│   ├── Service/
│   │   ├── Auth/
│   │   ├── Payment/
│   │   ├── Gateway/                 # Manual gateway + bridge
│   │   ├── Sms/
│   │   ├── Communication/           # SMS/Mail abstraction
│   │   ├── Device/
│   │   ├── Notification/
│   │   ├── Customer/
│   │   ├── Domain/                  # Custom domain resolver (NEW)
│   │   └── System/                  # Logger, cache, updater, cron
│   ├── Security/
│   ├── Plugin/                      # Universal plugin system
│   ├── Event/EventManager.php       # Hook/filter engine (80+ hook points)
│   ├── Cron/
│   ├── Cache/                       # FileCache + RedisCache
│   ├── Queue/                       # FileQueue + RedisQueue
│   ├── Update/                      # Fault-tolerant auto-updater (NEW)
│   └── View/                        # Twig extensions
├── templates/                       # Twig templates
│   ├── admin/
│   ├── checkout/                    # Based on Own_pay_checkout_ui_v2.html
│   ├── page/                        # Landing, login, forgot, 2FA
│   ├── email/
│   ├── error/
│   └── install/
├── database/
│   ├── schema.sql
│   └── seeds/
├── modules/                         # Plugin ecosystem
│   ├── gateways/                    # 3 API reference gateways
│   ├── themes/own-pay/              # Default theme (from checkout v2 HTML)
│   └── addons/                      # sms-gateway, mail-gateway, telegram-bot
├── storage/                         # Runtime
│   ├── logs/, cache/, queue/, sessions/, plugins/
│   ├── backups/                     # Auto-updater backups (NEW)
│   └── temp/                        # Temp extraction (NEW)
├── tests/
├── .env.example
└── composer.json
```

---

## 4. Limitless Plugin Extensibility (Pivot #8)

### 4.1 Philosophy

Hooks/filters injected at **every meaningful point** in application lifecycle. Third-party devs build ANY plugin (analytics, subscriptions, invoicing addons, fraud detection, custom dashboards, notification channels, report generators, etc.) without touching core.

### 4.2 Complete Hook Registry (80+ hooks)

**System Lifecycle:**
| Hook | Type | Location |
|------|------|----------|
| `system.boot` | Action | Kernel — after plugin load |
| `system.shutdown` | Action | Kernel — end of request |
| `system.maintenance.enter` | Action | MaintenanceMiddleware |
| `system.maintenance.exit` | Action | UpdateService |
| `system.config.loaded` | Filter | Config loader |
| `system.routes.register` | Action | Router — allows plugin routes |
| `system.middleware.pipeline` | Filter | Kernel — modify pipeline |
| `system.cron.before` | Action | CronJobRunner |
| `system.cron.after` | Action | CronJobRunner |

**Auth & Session:**
| Hook | Type | Location |
|------|------|----------|
| `auth.login.before` | Filter | AuthController |
| `auth.login.success` | Action | AuthController |
| `auth.login.failed` | Action | AuthController |
| `auth.logout` | Action | AuthController |
| `auth.session.started` | Action | SessionMiddleware |
| `auth.2fa.required` | Filter | TwoFactorMiddleware |
| `auth.permission.check` | Filter | PermissionMiddleware |

**Admin Panel:**
| Hook | Type | Location |
|------|------|----------|
| `admin.menu.register` | Action | Sidebar template |
| `admin.head` | Action | Admin `<head>` |
| `admin.footer` | Action | Before `</body>` |
| `admin.dashboard.widgets` | Filter | DashboardController |
| `admin.dashboard.stats` | Filter | DashboardController |
| `admin.page.before_render` | Filter | BaseController |
| `admin.page.after_render` | Filter | BaseController |
| `admin.settings.tabs` | Filter | SettingsController |
| `admin.settings.save` | Action | SettingsController |
| `admin.landing.render` | Filter | LandingController |
| `admin.login.render` | Filter | AuthController |

**Payment & Transaction:**
| Hook | Type | Location |
|------|------|----------|
| `payment.intent.created` | Action | PaymentService |
| `payment.intent.expired` | Action | PaymentService |
| `payment.transaction.before_create` | Filter | TransactionService |
| `payment.transaction.created` | Action | TransactionService |
| `payment.transaction.completed` | Action | TransactionService |
| `payment.transaction.failed` | Action | TransactionService |
| `payment.transaction.cancelled` | Action | TransactionService |
| `payment.transaction.refunded` | Action | RefundService |
| `payment.amount.calculate` | Filter | PaymentService |
| `payment.fee.calculate` | Filter | FeeService |

**Gateway & Checkout:**
| Hook | Type | Location |
|------|------|----------|
| `gateway.list` | Filter | CheckoutController |
| `gateway.manual.render` | Filter | ManualGatewayService |
| `gateway.manual.verify` | Filter | ManualGatewayService |
| `gateway.capture.before` | Filter | GatewayBridge |
| `gateway.capture.after` | Action | GatewayBridge |
| `gateway.webhook.received` | Action | WebhookController |
| `checkout.page.data` | Filter | CheckoutController |
| `checkout.before_render` | Filter | CheckoutController |
| `checkout.after_render` | Action | CheckoutController |
| `checkout.expired` | Action | CheckoutController |

**Invoice & Payment Link:**
| Hook | Type | Location |
|------|------|----------|
| `invoice.created` | Action | InvoiceService |
| `invoice.total` | Filter | InvoiceService |
| `invoice.paid` | Action | InvoiceService |
| `payment_link.created` | Action | PaymentLinkService |
| `payment_link.used` | Action | PaymentLinkService |

**Customer:**
| Hook | Type | Location |
|------|------|----------|
| `customer.created` | Action | CustomerService |
| `customer.updated` | Action | CustomerService |
| `customer.deleted` | Action | CustomerService |

**Communication:**
| Hook | Type | Location |
|------|------|----------|
| `communication.sms.send` | Action | CommunicationService |
| `communication.mail.send` | Action | CommunicationService |
| `communication.channels` | Filter | CommunicationService |
| `communication.template.render` | Filter | CommunicationService |

**Mobile/SMS:**
| Hook | Type | Location |
|------|------|----------|
| `mobile.device.paired` | Action | DevicePairingService |
| `mobile.device.revoked` | Action | DevicePairingService |
| `mobile.sms.received` | Action | SmsParserService |
| `mobile.sms.parsed` | Action | SmsParserService |
| `mobile.sms.matched` | Action | SmsVerificationJob |
| `mfs.templates` | Filter | SmsRegexParser |

**Webhook:**
| Hook | Type | Location |
|------|------|----------|
| `webhook.created` | Action | WebhookService |
| `webhook.delivery.success` | Action | WebhookService |
| `webhook.delivery.failed` | Action | WebhookService |

**Plugin System (Meta):**
| Hook | Type | Location |
|------|------|----------|
| `plugin.installed` | Action | PluginInstaller |
| `plugin.activated` | Action | PluginManager |
| `plugin.deactivated` | Action | PluginManager |
| `plugin.uninstalled` | Action | PluginManager |
| `plugin.settings.saved` | Action | PluginController |

**Update System:**
| Hook | Type | Location |
|------|------|----------|
| `update.available` | Action | UpdateService |
| `update.before` | Action | UpdateService |
| `update.after` | Action | UpdateService |
| `update.failed` | Action | UpdateService |
| `update.rollback` | Action | UpdateService |

**Domain:**
| Hook | Type | Location |
|------|------|----------|
| `domain.mapped` | Action | DomainService |
| `domain.verified` | Action | DomainService |
| `domain.removed` | Action | DomainService |
| `domain.resolve` | Filter | DomainMiddleware |

**Ledger & Audit:**
| Hook | Type | Location |
|------|------|----------|
| `ledger.entry.created` | Action | LedgerService |
| `audit.log.created` | Action | AuditLogger |
| `report.data` | Filter | ReportController |
| `export.row` | Filter | ExportService |

### 4.3 Security Boundaries

Plugins execute with strict sandbox:
- **No raw SQL** — Must use Repository pattern via DI or provided helpers
- **No direct `$_POST`/`$_GET`** — Must use `Request` object
- **No `global`** — DI container only
- **Code scanner** — `PluginSandbox` blocks `exec`, `shell_exec`, `system`, `eval`, direct PDO construction, `file_get_contents` with URLs
- **Capability enforcement** — Manifest declares capabilities; code scanner cross-validates
- **Hook isolation** — Each hook callback wrapped in try/catch; one broken plugin cannot crash system
- **Core auth untouchable** — Plugins can add to auth (2FA, SSO) via hooks, cannot bypass core auth pipeline

---

## 5. Default Checkout Theme — `Own_pay_checkout_ui_v2.html` (Pivot #9)

### 5.1 Analysis of Source HTML

768-line single-file checkout with:
- **Left panel** (desktop): Order summary, merchant info, totals, security badges
- **Right panel**: Timer, gateway tabs (Cards/MFS/Net Banking), gateway grid, manual payment popup
- **Post-transaction states**: Success, failed, cancelled, pending, expired — each with unique gradient, icon, card, countdown redirect
- **Modals**: Cancel, Info, FAQ, Support, Language
- **Manual payment flow**: Full-screen overlay with step-by-step instructions, QR, copy buttons, TxnID input + verify
- **Design system**: CSS custom properties (--ink, --teal, etc.), Outfit + JetBrains Mono fonts, glassmorphism, micro-animations

### 5.2 Twig Decomposition Plan

Split into composable Twig partials:

```
modules/themes/own-pay/
├── manifest.json
├── Theme.php                          # implements PluginInterface
├── templates/
│   ├── checkout.twig                  # Main checkout page
│   ├── checkout-status.twig           # Post-transaction (success/failed/etc.)
│   ├── partials/
│   │   ├── _left-panel.twig           # Order summary (desktop only)
│   │   ├── _mobile-summary.twig       # Compact mobile summary
│   │   ├── _top-bar.twig             # Timer + action buttons
│   │   ├── _gateway-tabs.twig        # Cards/MFS/Bank tab switcher
│   │   ├── _gateway-grid.twig        # Gateway card grid (dynamic from DB)
│   │   ├── _manual-popup.twig        # Manual payment full-screen overlay
│   │   ├── _express-checkout.twig    # Apple/Google Pay buttons
│   │   ├── _modals.twig             # Cancel/Info/FAQ/Support/Language
│   │   └── _footer.twig             # Security badge footer
│   └── status/
│       ├── _success.twig
│       ├── _failed.twig
│       ├── _cancelled.twig
│       ├── _pending.twig
│       └── _expired.twig
├── assets/
│   ├── checkout.css                   # Extracted CSS (custom props preserved)
│   └── checkout.js                    # Timer, gateway picker, manual flow, state engine
└── README.md
```

### 5.3 Dynamic Data Binding

All hardcoded values → Twig variables:
- `TechVenture Ltd.` → `{{ merchant.business_name }}`
- `৳13,125.00` → `{{ amount|money(currency) }}`
- Gateway grid → `{% for gw in gateways %}` loop from DB
- Manual steps → Generated from `op_manual_gateways.instructions` JSON
- FAQ items → From `op_system_settings` or merchant FAQ table
- Timer duration → From system settings
- Post-transaction data → From `op_transactions` row

### 5.4 Theme Hook Points

Theme registers filters so plugins can modify checkout:
```php
$events->addFilter('checkout.page.data', [$this, 'prepareData']);
$events->addFilter('theme.checkout.render', [$this, 'render']);
$events->addFilter('theme.checkout_status.render', [$this, 'renderStatus']);
```

---

## 6. Enterprise Auto-Updater with Rollback (Pivot #10)

### 6.1 Architecture: `src/Update/UpdateService.php`

**Update server:** `https://update.ownpay.org/update.json`

Expected response:
```json
{
  "latest_version": "0.2.0",
  "download_url": "https://update.ownpay.org/releases/0.2.0.zip",
  "checksum_sha256": "abc123...",
  "min_php": "8.2",
  "changelog": "...",
  "release_date": "2026-05-15",
  "critical": false
}
```

### 6.2 Update Flow

```
1. CHECK — Daily cron or admin "Check Now" button
   → GET https://update.ownpay.org/update.json
   → Compare version_compare(current, latest)
   → If newer: store in op_system_settings, fire 'update.available' hook
   → Show notification badge in admin panel

2. TRIGGER — Conditions:
   a) Admin clicks "Update Now" button (manual), OR
   b) Scheduled night window (configurable, e.g., 2-4 AM server time)
   c) Safety: No API requests in last 15 minutes (check op_rate_limits / request log)
   → If active users detected: delay and retry in 15 min (max 3 retries then abort)

3. MAINTENANCE MODE ON
   → Write storage/maintenance.php with timestamp
   → All requests return 503:
     HTTP/1.1 503 Service Unavailable
     Retry-After: 600
     Content-Type: application/json
     {"status": 503, "message": "System Under maintenance, please try after sometime or contact support."}
   → Fire 'system.maintenance.enter' hook

4. BACKUP (Pre-Update)
   a) Database: mysqldump → storage/backups/{timestamp}_db.sql
   b) Codebase: ZIP entire project → storage/backups/{timestamp}_code.zip
   c) Exclude: storage/backups/, storage/temp/, vendor/
   → If backup fails: abort, maintenance off, log error

5. DOWNLOAD (Max 3 retries)
   → Download {version}.zip to storage/temp/
   → Verify SHA-256 checksum against update.json
   → If checksum mismatch after 3 retries: abort, maintenance off, delete temp

6. EXTRACT
   → Extract to storage/temp/extracted/
   → If extraction fails: HALT → goto ROLLBACK

7. APPLY
   → Smart copy (skip .env, storage/, vendor/)
   → Run composer install --no-dev
   → Run database migrations (if updates/upgrade.php exists)
   → Clear OPcache
   → Rebuild Twig cache
   → Fire 'update.before' hook

8. HEALTH CHECK
   → Internal HTTP ping to /api/v1/health
   → Check PHP syntax on critical files (Kernel, Bootstrap, Database)
   → If ANY fatal error detected: goto ROLLBACK

9. SUCCESS
   → Fire 'update.after' hook
   → Maintenance mode OFF
   → Fire 'system.maintenance.exit' hook
   → Log: "Update Successfully Completed to version {X.X}" in op_audit_logs
   → Show success notification in admin panel
   → Delete: storage/temp/, storage/backups/{this-update}*, downloaded zip

ROLLBACK (on any error in steps 6-8):
   1. HALT execution
   2. Restore database from .sql backup (import)
   3. Restore codebase from .zip backup (extract over files)
   4. Maintenance mode OFF
   5. Delete storage/temp/ and downloaded zip
   6. Fire 'update.rollback' hook
   7. Log full stack trace to storage/logs/update_error_{timestamp}.log
   8. Show error notification in admin panel
```

### 6.3 DB Table: `op_update_history`

| Column | Type |
|--------|------|
| id | BIGINT PK |
| from_version | VARCHAR(20) |
| to_version | VARCHAR(20) |
| status | ENUM('checking','downloading','backing_up','extracting','applying','health_check','success','failed','rolled_back') |
| backup_db_path | VARCHAR(500) |
| backup_code_path | VARCHAR(500) |
| error_message | TEXT |
| error_trace | LONGTEXT |
| started_at | DATETIME(6) |
| completed_at | DATETIME(6) |

### 6.4 Admin UI

- Settings → System Update page
- Current version display
- "Check for Updates" button
- Available update card (version, changelog, release date, critical badge)
- "Update Now" button with confirmation modal
- Schedule config (night window hours)
- Update history table (version, status, date)

---

## 7. Multi-Brand Custom Domain / White-Labeling (Pivot #11)

### 7.1 Architecture

**Scope:** Custom domains apply ONLY to customer-facing pages (checkout, invoice view, payment link). Core admin panel, API, webhooks, IPN → always use root OwnPay domain.

### 7.2 Domain Resolution Flow

```
Request arrives → DomainMiddleware
  → Check HTTP Host header
  → Is it the primary app domain? → Normal routing
  → Is it a mapped custom domain?
    → Query op_domains WHERE domain = :host AND status = 'active' AND dns_verified = 1
    → Set merchant context from domain mapping
    → Is it a checkout/invoice/payment-link URL? → Render under custom domain
    → Is it a direct visit (no checkout context)? → Redirect to merchant's configured redirect_url
  → Unknown domain? → 404
```

### 7.3 DB: `op_domains` table (enhanced)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | |
| merchant_id | BIGINT FK | → op_merchants |
| domain | VARCHAR(255) UNIQUE | e.g., `pay.merchant.com` |
| is_primary | TINYINT(1) | Default domain for this merchant |
| dns_verified | TINYINT(1) | DNS A record verified |
| dns_verified_at | DATETIME | Last verification timestamp |
| ssl_status | ENUM('pending','active','expired') | If managed SSL |
| redirect_url | VARCHAR(2048) | Where to redirect direct visits |
| status | ENUM('active','inactive','pending_verification') | |
| created_at | DATETIME(6) | |
| updated_at | DATETIME(6) | |

### 7.4 DNS Verification

Admin UI shows: "Point your DNS A record to `{server_ip}`"

Verification checker:
```php
// DNS A record check
$records = dns_get_record($domain, DNS_A);
foreach ($records as $r) {
    if ($r['ip'] === $serverIp) return true;
}
```

Auto-recheck via cron job (every 6 hours for pending domains).

### 7.5 Checkout URL Generation

When creating a payment intent / invoice / payment link:
```php
$domain = $this->domainService->getPrimaryDomain($merchantId);
if ($domain) {
    $checkoutUrl = "https://{$domain->domain}/checkout/{$token}";
} else {
    $checkoutUrl = "https://{$appDomain}/checkout/{$token}";
}
```

Root OwnPay domain is NEVER exposed in customer-facing checkout URLs when custom domain is configured.

### 7.6 Admin UI

- Merchants → Edit Merchant → "Custom Domain" tab
- Domain input + "Verify DNS" button
- DNS instruction card (A record, value = server IP)
- Verification status badge (pending → verified → active)
- "Redirect URL" field for direct visits
- SSL status indicator

---

## 8. Gateway Architecture

### 8.1 Strategy: 2-3 Reference Gateways + Dynamic Manual Builder

**DO NOT port 46 legacy gateways.** Instead:

| Gateway | Type | Purpose |
|---------|------|---------|
| `stripe` | API-based (redirect) | Reference for API-integrated gateways |
| `sslcommerz` | API-based (hosted) | Reference for hosted payment page flow |
| `bkash-api` | MFS API (tokenized) | Reference for mobile financial service API |

### 8.2 Dynamic Manual Gateway Builder (Admin Feature)

Admin can create unlimited manual gateways (bKash Personal, Nagad, bank transfer, etc.) from UI:

**Configurable fields per manual gateway:**
- Gateway Name, Slug (auto-generated)
- Logo upload, QR Code image upload
- Primary/Secondary colors
- Custom input fields (array): label, type (text/number/image/select), required flag
- Payment instructions (multi-step, multi-language)
- Admin notes (internal)
- SMS Verification toggle (enable/disable)
- SMS Sender regex template (when verification enabled)
- Currency, Min/Max amount
- Status: Active/Inactive

**DB table: `op_manual_gateways`**
| Column | Type |
|--------|------|
| id | BIGINT PK |
| merchant_id | BIGINT FK |
| slug | VARCHAR(60) UNIQUE |
| name | VARCHAR(150) |
| logo_path | VARCHAR(500) |
| qr_code_path | VARCHAR(500) |
| colors | JSON |
| input_fields | JSON |
| instructions | JSON (multi-step, multi-lang) |
| admin_notes | TEXT |
| sms_verification | TINYINT(1) DEFAULT 0 |
| sms_sender_pattern | VARCHAR(100) |
| sms_regex_template | TEXT |
| currency | CHAR(3) DEFAULT 'BDT' |
| min_amount | DECIMAL(15,2) |
| max_amount | DECIMAL(15,2) |
| sort_order | INT DEFAULT 0 |
| status | ENUM('active','inactive') |
| created_at | DATETIME(6) |
| updated_at | DATETIME(6) |

Manual gateways rendered dynamically by checkout theme using JSON config — no PHP code per gateway.

### 8.3 Request Lifecycle

```
Request → public/index.php
  → .env loaded (phpdotenv)
  → Container built (config/services.php)
  → Kernel::boot()
    → Middleware pipeline (config/middleware.php):
       CORS → SecurityHeaders → Maintenance → Domain → Session → CSRF → Auth → RateLimit → PermissionGuard
    → PluginLoader::boot() (register → boot → fire 'system.boot')
    → Router::dispatch()
      → Route matched → Controller::action($request)
      → Response returned (HTML via Twig OR JSON)
```

---

## 9. Plugin Ecosystem — WordPress Paradigm

### 9.1 Architecture (Preserving Existing Design)

The existing `EventManager` + `PluginInterface` architecture in `docs/architecture/Universal_Extensibility_Plan.md` is sound. We keep it and enhance with 80+ hooks (see §4 above).

**Plugin types:**
- `gateway` — Payment gateways (API-based, requires code)
- `theme` — Checkout UI themes
- `addon` — Feature extensions (SMS gateways, mail gateways, Telegram bot, etc.)

**Enhancements over existing design:**
1. **UI Agnostic rendering** — Plugin views can be Twig templates OR raw PHP. Core checks `.twig` first, falls back to `.php`
2. **Communication capability** — New `Capability::COMMUNICATION` for SMS/mail/notification addons
3. **Admin menu injection** — WordPress-style sidebar menu registration via `admin.menu.register` hook
4. **Settings page auto-generation** — `fields()` array auto-renders settings form (no custom view needed)
5. **Addon marketplace prep** — `manifest.json` includes `homepage`, `support_url`, `changelog_url`

### 9.2 Admin UI (WordPress-Mirror)

**Plugin Manager pages:**
1. **Installed Plugins** — List all with: name, type badge, version, status toggle, settings link, delete
2. **Add New** — ZIP upload form + drag-and-drop area
3. **Plugin Settings** — Auto-generated from `fields()` OR custom view from plugin's `views/settings.php`

**Theme Manager pages:**
1. **Installed Themes** — Grid view with preview screenshots, activate button
2. **Upload Theme** — ZIP upload

---

## 10. Communications System

### 10.1 Architecture

Communication abstracted via hooks. Core fires `communication.sms.send` / `communication.mail.send`. Addon plugins listen and deliver.

```php
// Core fires:
EventManager::doAction('communication.sms.send', [
    'to' => '+8801712345678',
    'message' => 'Payment of 500 BDT received. TrxID: ABC123',
    'type' => 'transaction_alert',
]);

// SMS Gateway Addon listens:
$events->addAction('communication.sms.send', [$this, 'sendSms'], owner: 'sms-gateway');
```

### 10.2 Built-in Addons

| Addon | Type | Features |
|-------|------|----------|
| `sms-gateway` | addon | Default SMS sending. Supports: Twilio, custom HTTP API, configurable templates |
| `mail-gateway` | addon | Default email. Supports: SMTP, Mailgun, SendGrid. Twig-based email templates |

### 10.3 Extensibility

Third-party developers create new comm addons (WhatsApp, Viber, etc.) by:
1. Creating addon with `communication` capability
2. Listening to `communication.sms.send` or `communication.mail.send` hooks
3. Registering channel via `communication.channels` filter
4. Uploading as ZIP — zero core code changes

---

## 11. Telegram Bot Addon — Reference Architecture

### 11.1 Purpose

Proof-of-concept addon demonstrating full addon lifecycle: install, configure, hook into events, send external API calls, receive webhooks.

### 11.2 Features

- **Transaction alerts** → Send Telegram message on payment received/completed
- **Admin commands** → `/status` (system health), `/today` (today's summary), `/recent` (last 5 transactions)
- **Configurable** via admin settings: Bot Token, Chat ID, alert types toggle
- **Webhook receiver** → Addon registers route `/plugins/telegram-bot/webhook` for Telegram updates

### 11.3 manifest.json

```json
{
  "name": "Telegram Bot",
  "slug": "telegram-bot",
  "type": "addon",
  "version": "1.0.0",
  "entrypoint": "Plugin.php",
  "capabilities": ["http_outbound", "settings", "hooks", "admin_menu"],
  "hooks": {
    "actions": ["payment.transaction.completed", "system.health.check"],
    "filters": []
  },
  "admin_menu": [{
    "title": "Telegram Bot",
    "slug": "telegram-bot",
    "icon": "message-circle",
    "parent": "addons"
  }]
}
```

---

## 12. Performance & Hosting

### 12.1 Shared Hosting Compatibility

| Feature | Shared Hosting | VPS (Redis/Supervisor) |
|---------|---------------|----------------------|
| Cache | `FileCache` (storage/cache/) | `RedisCache` |
| Queue | `FileQueue` (storage/queue/) | `RedisQueue` + Supervisor worker |
| Session | File-based (storage/sessions/) | Redis sessions |
| Rate limiting | DB-based (`op_rate_limits`) | Redis-based |
| Cron | URL-based (cPanel cron hits `/cron/{secret}`) | System crontab |

### 12.2 Performance Targets

- Page load: < 200ms (admin), < 100ms (checkout)
- API response: < 50ms (simple), < 200ms (complex)
- Plugin boot: < 10ms (cached registry)
- Memory: < 32MB per request
- DB queries per page: < 15

### 12.3 Optimization Strategies

- Plugin registry JSON cache (no DB query on every request)
- Twig template caching (compiled PHP)
- Config caching (parsed .env → PHP array cache)
- Minimal Composer dependencies
- No ORM — hand-optimized PDO queries
- Lazy service loading via DI container

---

## 13. UI/UX & Security

### 13.1 Custom Landing Page

- Root domain (`/`) → Customizable landing page (NOT login)
- Landing page template editable via admin panel
- Login URL configurable: default `/login`, changeable to `/my-secret-login` etc.
- Admin path configurable: default `/admin`, changeable to `/dashboard-xyz` etc.
- Landing page disabled? → Redirect to login

### 13.2 Responsive Design

- Mobile-first Tailwind CSS
- Admin panel: fully responsive (sidebar collapses on mobile)
- Checkout: mobile-optimized (single-column flow)
- Landing page: responsive hero/features/footer layout

### 13.3 Security Architecture

- **CSRF** — Per-request token rotation
- **XSS** — Twig auto-escaping + CSP nonce
- **SQL Injection** — Parameterized queries only (no string interpolation)
- **Rate Limiting** — Per-IP + per-key sliding window
- **API Auth** — Bearer token (API keys) + JWT (mobile devices)
- **Webhook Security** — HMAC-SHA256 signature verification
- **Session** — Secure flags, regeneration on login, IP binding optional
- **PII** — AES-256-GCM encryption at rest, PII masking in logs
- **Password** — Argon2id hashing
- **2FA** — TOTP-based (existing)
- **Memory** — No persistent data in globals; DI container manages lifecycle

---

## 14. Feature Parity & Mobile API

### 14.1 All Existing Features Preserved

| Feature | Current Status | v0.1.0 Status |
|---------|---------------|---------------|
| Multi-brand (merchant) | Working | Port as-is |
| Staff management + RBAC | Working | Port with DI |
| Transaction management | Working (dual-table) | Unified to single `op_transactions` |
| Invoice system | Working | Port as-is |
| Payment links | Working | Port as-is |
| Customer management | Working | Port as-is |
| 46 gateway modules | Working | Replaced by dynamic manual + 3 API reference |
| Checkout themes | Working | Port own-pay theme to Twig (from v2 HTML) |
| SMS parsing (2-tier) | Working | Port as-is |
| Device pairing | Working | Port as-is |
| Plugin system | Working | Enhanced with WordPress paradigm + 80 hooks |
| Webhook system | Working | Port as-is |
| Balance verification | Working | Port as-is |
| Currency management | Working | Port as-is |
| FAQ system | Working | Port as-is |
| Domain management | Working | Enhanced with custom domain / white-labeling |
| System updater | Working | Replaced with fault-tolerant auto-updater |
| Audit logging | Working | Port as-is |
| Reports/analytics | Working | Port as-is |

### 14.2 Mobile API Gaps Identified & Planned

| Gap | Description | Resolution |
|-----|-------------|------------|
| Admin SMS Template UI | Backend exists, no admin views | Build Twig views for template CRUD |
| SMS Queue admin UI | Backend exists, no admin views | Build Twig views for queue management |
| Push notification config | No admin config for poll interval | Add to system settings |
| Device management admin | Backend exists, minimal UI | Full Twig-based device management page |
| Transaction matching feedback | SMS parsed but no match feedback to mobile | Add `match_status` field to notification payload |
| Bulk device revocation | Only single-device revoke | Add bulk revoke endpoint |
| API versioning header | No `X-API-Version` header | Add to all API responses |

---

## 15. Database Schema (Unified, ~48 tables)

### Drop all 21 legacy Section 11 tables. Final: ~48 tables.

| Section | Tables |
|---------|--------|
| 1. Merchants & RBAC | `op_merchants`, `op_roles`, `op_permissions`, `op_role_permissions`, `op_merchant_users`, `op_api_keys`, `op_domains` (enhanced) |
| 2. Gateways | `op_gateways`, `op_gateway_configs`, `op_manual_gateways` (NEW), `op_currencies`, `op_exchange_rates`, `op_system_settings` |
| 3. Customers | `op_customers` |
| 4. Payments | `op_payment_intents`, `op_transactions` (partitioned), `op_idempotency_keys`, `op_refunds`, `op_payment_links`, `op_payment_link_fields`, `op_invoices`, `op_invoice_items` |
| 5. Webhooks | `op_webhooks`, `op_webhook_events`, `op_webhook_delivery_logs` |
| 6. Ledger | `op_ledger_accounts`, `op_ledger_transactions`, `op_ledger_entries` (partitioned) |
| 7. Settlement | `op_settlements`, `op_settlement_items`, `op_disputes` |
| 8. Fees | `op_fee_rules` |
| 9. Audit | `op_audit_logs` (partitioned), `op_login_attempts` |
| 10. Devices & SMS | `op_device_pairing_tokens`, `op_paired_devices`, `op_mobile_notifications`, `op_sms_templates`, `op_sms_parsed` |
| 11. Plugins | `op_plugins`, `op_plugin_migrations`, `op_plugin_settings` |
| 12. System | `op_rate_limits`, `op_sessions`, `op_queue_jobs` (NEW), `op_cache` (NEW) |
| 13. Communication | `op_comm_log` (NEW — SMS/email delivery log) |
| 14. Auto-Updater | `op_update_history` (NEW Rev 3), `op_maintenance_locks` (NEW Rev 3) |

---

## 16. Implementation Phases

| Phase | Scope | Tasks |
|-------|-------|-------|
| A | Foundation (DI, Kernel, Router, Twig, Cache, Queue) | ~28 |
| B | Database & Repositories | ~25 |
| C | Security & Middleware (+DomainMiddleware, +MaintenanceMiddleware) | ~20 |
| D | Plugin Ecosystem (80+ hooks, WordPress-style admin) | ~22 |
| E | Service Layer (all services + Communication + Domain + Update) | ~65 |
| F | Gateway System (manual builder + 3 refs) | ~9 |
| G | Admin Panel (all Twig templates + controllers + JS + CSS) | ~55 |
| H | API Layer (unified routes, merchant + mobile + admin) | ~22 |
| I | Checkout & Theme (v2 HTML → Twig decomposition) | ~10 |
| J | Built-in Addons (SMS, Mail, Telegram) | ~16 |
| K | Installer (enterprise Twig wizard) | ~10 |
| L | Testing & QA | ~22 |
| M | Branding Sweep & Cleanup | ~15 |
| **Total** | | **~320** |

---

## 17. Verification Plan

### Automated
- PHPUnit full suite, PHPStan level 6, `grep -r` branding scan, `php -l` lint

### Manual
- Fresh install wizard
- Every admin page responsive (mobile/tablet/desktop)
- Manual gateway → checkout → SMS verify → complete
- Plugin lifecycle: install ZIP → activate → hooks fire → deactivate → uninstall
- Telegram Bot: receives alert, responds to `/status`
- Custom domain: map → DNS verify → checkout renders on custom domain
- Auto-update: trigger → backup → download → apply → health check → success
- Auto-update rollback: corrupt package → rollback → previous version restored
- Mobile API: pair → SMS → poll → dashboard

---

**Awaiting Final Approval to begin Phase 2 (Implementation).**

---

## 18. Unified Dynamic Webhook/IPN Architecture (Phase N)

> **CRITICAL ARCHITECTURE RULE:** Zero core modification for new gateways. ONE single dynamic endpoint. Domain-aware.

### 18.1 Problem Statement

The current `IpnController` (lines 31-33 of `api.php`) has **hardcoded per-gateway routes** (`/webhook/stripe`, `/webhook/sslcommerz`, `/webhook/bkash`) with per-gateway methods containing baked-in verification logic. This violates the Zero Core Modification principle — a third-party developer building an "UPay" plugin would need to edit core route files and the controller.

### 18.2 Design: Single Dynamic Endpoint

**ONE route handles all inbound webhooks:**

```
POST /webhook/{gateway}   →   UnifiedWebhookController@handle
```

No `/ipn/*` alias. No legacy routes. Clean.

**Example URLs generated by Own Pay when initiating payments:**

| Merchant Config | Generated Callback URL |
|----------------|----------------------|
| Custom domain `pay.merchant.com` | `https://pay.merchant.com/webhook/stripe` |
| Custom domain `checkout.acme.io` | `https://checkout.acme.io/webhook/bkash` |
| No custom domain (system default) | `https://ownpay.example.com/webhook/sslcommerz` |
| Third-party plugin "UPay" | `https://pay.merchant.com/webhook/upay` |

### 18.3 Inbound Flow (Gateway → Own Pay)

```
Bank/Gateway (e.g., bKash)
  │
  │  POST https://pay.merchant.com/webhook/bkash
  │
  ▼
┌──────────────────────────────────────────────┐
│ 1. DomainResolverMiddleware                  │
│    • Reads Host header (pay.merchant.com)    │
│    • Queries op_domains for merchant_id      │
│    • Injects merchant context into Request   │
│    • Falls back to system domain if no match │
└──────────────────────────────────────────────┘
  │
  ▼
┌──────────────────────────────────────────────┐
│ 2. UnifiedWebhookController@handle           │
│    • Extracts {gateway} slug from URL        │
│    • Creates WebhookPayload value object:    │
│      - raw body, headers, IP, merchant_id    │
│    • Checks: does any plugin listen to       │
│      webhook.incoming.{gateway}?             │
│    • If NO listener → log + return 404       │
│    • Fires: webhook.incoming.{gateway}       │
│    • Logs delivery to op_webhook_deliveries  │
│    • Returns 200                             │
└──────────────────────────────────────────────┘
  │
  ▼
┌──────────────────────────────────────────────┐
│ 3. Gateway Plugin (e.g., bKash Plugin)       │
│    • Registered at boot via EventManager:    │
│      addAction('webhook.incoming.bkash', ..) │
│    • Receives WebhookPayload                 │
│    • Performs gateway-specific verification:  │
│      - HMAC signature (Stripe)               │
│      - Validation API call (SSLCommerz)      │
│      - IP whitelist check (bKash)            │
│    • Parses response → extracts txn ref      │
│    • Calls PaymentService::processIpn()      │
└──────────────────────────────────────────────┘
```

### 18.4 Merchant ID Resolution (Approach D — Hybrid)

1. **Primary**: `Host: pay.merchant.com` → lookup `op_domains` → `merchant_id`
2. **Fallback**: Extract transaction reference from payload → lookup `op_transactions` → `merchant_id`
3. **Last resort**: If neither resolves → log attempt with raw payload hash, return 400

### 18.5 Outbound Flow (Own Pay → Merchant Website)

When **any** transaction changes status (API gateway, manual gateway, bank account — all types), Own Pay sends a signed webhook to the merchant's configured endpoint.

```
Transaction status changes (completed/failed/refunded)
  │
  ▼
┌──────────────────────────────────────────────┐
│ PaymentService fires hook:                   │
│   payment.transaction.{status}               │
└──────────────────────────────────────────────┘
  │
  ▼
┌──────────────────────────────────────────────┐
│ WebhookDispatcher (listens to all status     │
│ hooks — universal for ALL gateway types)     │
│                                              │
│ 1. Loads merchant's webhook_url + secret     │
│ 2. Builds standardized JSON payload:         │
│    {                                         │
│      "event": "payment.completed",           │
│      "transaction_id": "TXN-...",            │
│      "amount": "500.00",                     │
│      "currency": "BDT",                      │
│      "gateway": "bkash",                     │
│      "gateway_type": "manual|api|bank",      │
│      "status": "completed",                  │
│      "timestamp": "2026-04-30T..."           │
│    }                                         │
│ 3. Signs with HMAC-SHA256 using merchant     │
│    webhook_secret                            │
│ 4. POST to merchant's webhook_url            │
│ 5. Logs delivery (status, response_time)     │
│ 6. On failure: retry with exponential        │
│    backoff (3 attempts, 1m/5m/30m)           │
└──────────────────────────────────────────────┘
```

### 18.6 Callback URL Generation (Domain-Aware)

When Own Pay initiates a payment with a provider, it generates the callback URL using the merchant's custom domain:

```php
// In gateway plugins / PaymentService:
$callbackUrl = $domainService->merchantUrl($merchantId, "/webhook/{$gatewaySlug}");
// Result: https://pay.merchant.com/webhook/stripe
```

### 18.7 Zero-Core-Modification Example

A third-party developer builds an "UPay" gateway plugin:

```php
// modules/gateways/upay/Plugin.php
class Plugin implements PluginInterface
{
    public function register(EventManager $events): void
    {
        // This SINGLE line enables POST /webhook/upay automatically
        $events->addAction('webhook.incoming.upay', [$this, 'handleWebhook']);
    }

    public function handleWebhook(WebhookPayload $payload): void
    {
        // 1. Verify UPay's signature
        $signature = $payload->header('X-UPay-Signature');
        // ... verify HMAC ...

        // 2. Extract transaction reference
        $data = $payload->json();
        $txnRef = $data['order_id'] ?? '';

        // 3. Update transaction via PaymentService
        $this->paymentService->processIpn($txnRef, [
            'gateway' => 'upay',
            'status' => $data['status'],
            'gateway_txn_id' => $data['txn_id'],
        ]);
    }
}
```

**No route files touched. No controller edited. No core code modified.**

### 18.8 WebhookPayload Value Object

```php
final readonly class WebhookPayload
{
    public function __construct(
        public string $gateway,           // From URL {gateway} segment
        public int $merchantId,           // Resolved from domain or txn lookup
        public string $rawBody,           // Unparsed body for HMAC verification
        public array $headers,            // All HTTP headers
        public string $ip,                // Remote IP for whitelist checks
        public string $method,            // HTTP method (POST/GET)
    ) {}

    public function json(): array { ... }
    public function header(string $key): ?string { ... }
    public function formData(): array { ... }
}
```

### 18.9 Files to Create / Modify

| Action | File | Description |
|--------|------|-------------|
| **CREATE** | `src/Model/WebhookPayload.php` | Immutable value object |
| **CREATE** | `src/Controller/Webhook/UnifiedWebhookController.php` | Single handler for all inbound webhooks |
| **CREATE** | `src/Service/Notification/WebhookDispatcher.php` | Outbound webhook sender (HMAC-signed, retry, logging) |
| **MODIFY** | `config/routes/web.php` | Replace `/ipn/{gateway}` with single `/webhook/{gateway}` |
| **MODIFY** | `config/routes/api.php` | Remove hardcoded `/webhook/stripe`, `/webhook/sslcommerz`, `/webhook/bkash` |
| **REFACTOR** | `src/Controller/Webhook/IpnController.php` | Delete — replaced by UnifiedWebhookController |
| **REFACTOR** | Gateway plugins (stripe, sslcommerz, bkash) | Move verification logic into plugin `handleWebhook()` |
| **MODIFY** | `src/Service/Domain/DomainService.php` | Ensure `merchantUrl()` is used for callback URL generation |
| **ADD TESTS** | `tests/Unit/WebhookPayloadTest.php` | Payload creation, header access, JSON parsing |
| **ADD TESTS** | `tests/Unit/WebhookDispatcherTest.php` | HMAC signing, retry logic, delivery logging |

