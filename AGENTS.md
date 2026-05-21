# OwnPay — Agent Context

> [!IMPORTANT]
> **AI AGENT MANDATORY INSTRUCTION**: Before writing or refactoring any code in this codebase, you MUST read the comprehensive architectural specifications documented in the root directory file [ARCHITECTURE.md](file:///c:/laragon/www/ownpay/ARCHITECTURE.md). Adhere strictly to the boot pipelines, ledger bookkeeping constraints, dynamic CSRF token resolutions, and database generated indexing column guidelines defined therein to prevent logical regressions and system crashes. **Additionally, you MUST always follow the architecture, design documents, and models. If you make any change to the architecture, routes, schemas, settings, or core logic, you MUST immediately update all relevant documentation files (including [ARCHITECTURE.md](file:///c:/laragon/www/ownpay/ARCHITECTURE.md), [AGENTS.md](file:///c:/laragon/www/ownpay/AGENTS.md), and files in [docs/](file:///c:/laragon/www/ownpay/docs/)) to reflect the latest state. Never let documentation get out of sync with the actual implementation.**

## Project Overview

**OwnPay** is an enterprise-grade, open-source payment gateway platform built with PHP 8.2+. It follows a **single-owner, multi-brand/store** model — NOT a SaaS platform. One admin controls the entire system, managing multiple brands (stores), each with their own gateways, domains, customers, and transactions.

- **Version**: 0.1.0 (Genesis)
- **License**: AGPL-3.0-or-later
- **PHP**: ^8.2 with strict types everywhere
- **Database**: MySQL 8.x with `op_` table prefix, 50 tables
- **Templating**: Twig 3.14 (`.twig` extension, rendered via PHP `include`)
- **Auth**: Argon2id passwords, TOTP 2FA, role-based permissions
- **Error Handling**: Production-safe branded error pages (no info disclosure)
- **Local Dev URL**: `https://ownpay.test/`
- **Login**: `admin@example.com` / `admin12345`

---

## Business Model — CRITICAL

> **OwnPay is NOT a SaaS / multi-tenant platform.**

It is a **single-owner, multi-brand** system:

- **One admin** (superadmin) owns and controls everything
- **Multiple brands/stores** (stored in `op_merchants` table) — each with own gateways, domains, customers, transactions
- **Staff members** are created by admin and assigned to brands with role-based permissions (`op_roles` + `op_role_permissions`)
- **No self-registration** — admin creates brands and invites staff
- Each brand can have a **custom domain** (`op_domains`)
- `merchant_id` column stays in DB everywhere. Only UI labels say "Brand/Store"

**Reference**: `docs/v2/model/business_model.md`

---

## Architecture

### Entry Point & Boot Sequence

```
public/index.php → src/Kernel.php
```

`Kernel.php` orchestrates:
1. Load `.env` (vlucas/phpdotenv)
2. Build DI container (`config/services.php`)
3. Set timezone
4. Load middleware pipeline (`config/middleware.php`)
5. Boot plugins (`PluginLoader::boot`)
6. Fire `system.boot` hook
7. Load routes (`config/routes/web.php` + `config/routes/api.php`)
8. Match request → run middleware → dispatch controller
9. Send response
10. Fire `system.shutdown` hook

### DI Container

`src/Container.php` — Lightweight PSR-11 compatible container:
- Singleton and transient bindings
- Factory closures with auto-injection
- Reflection-based autowiring for unregistered classes
- Explicit registrations in `config/services.php`

### Key Architectural Patterns

| Pattern | Implementation |
|---------|---------------|
| **PSR-11 DI** | `src/Container.php` — all services resolved via container |
| **Repository Pattern** | `src/Repository/` — 35 repositories extending `BaseRepository` |
| **Tenant Scoping** | `TenantScope` trait — auto-scopes queries by `merchant_id` (= brand) |
| **Hook/Filter System** | `src/Event/EventManager.php` — `addAction()`/`doAction()`/`addFilter()`/`applyFilter()` |
| **Middleware Pipeline** | `src/Middleware/` — 14 middleware classes, 9 groups in `config/middleware.php` |
| **Plugin System** | `src/Plugin/` — manifest-based discovery, `PluginInterface`, sandboxed execution |
| **Error Handling** | `Kernel::handleException()` — branded 500/404 pages, path sanitization, no info disclosure |
| **White-Label Pipeline** | `DomainMiddleware` + `DomainUrlService` — every customer interaction runs under brand's custom domain |

---

## Directory Structure

```
ownpay/
├── config/                     # Configuration
│   ├── app.php                 # App config (name, version, paths, session, security)
│   ├── database.php            # DB connection config (reads .env)
│   ├── hooks.php               # Default hook/filter registrations
│   ├── middleware.php           # Middleware pipeline definitions
│   ├── services.php            # DI container bindings (~463 lines)
│   └── routes/
│       ├── web.php             # Admin + public web routes
│       └── api.php             # REST API routes
├── database/
│   ├── schema.sql              # Full DDL (39KB, 50 tables)
│   └── seeds/                  # Seed data
├── docs/                       # Documentation
│   └── v2_migration/           # Migration docs (business_model.md, etc.)
├── modules/                    # Plugin modules
│   ├── addons/                 # Addon plugins
│   ├── gateways/               # Gateway plugins (each has manifest.json)
│   └── themes/                 # Theme plugins
├── public/                     # Web root
│   └── index.php               # Single entry point
├── src/                        # Application source (PSR-4: OwnPay\\)
│   ├── Kernel.php              # Application kernel
│   ├── Container.php           # DI container
│   ├── Cache/                  # Cache layer
│   ├── Controller/
│   │   ├── Admin/              # 28 admin controllers (incl. RolesController, FaqController)
│   │   ├── Api/                # REST API controllers
│   │   ├── Checkout/           # Payment checkout flow
│   │   ├── Install/            # Installer
│   │   ├── Page/               # Public pages (landing, login)
│   │   └── Webhook/            # Webhook/IPN handlers
│   ├── Core/                   # Database, UUID, Route helpers
│   ├── Cron/                   # Scheduled tasks
│   ├── Enum/                   # Enums (TransactionStatus)
│   ├── Event/                  # EventManager (hooks/filters)
│   ├── Gateway/                # Gateway adapter interface + bridge
│   ├── Http/                   # Request, Response, Router
│   ├── Middleware/             # 13 middleware classes
│   ├── Model/                  # Domain models
│   ├── Plugin/                 # Plugin system (loader, registry, sandbox)
│   ├── Queue/                  # Job queue
│   ├── Repository/             # 35 repositories + TenantScope trait + BaseRepository
│   ├── Security/               # Authenticator, encryption, CSRF, PII masking
│   ├── Service/                # Business logic services
│   │   ├── Admin/              # AdminSession
│   │   ├── Auth/               # AuthSessionService
│   │   ├── Brand/              # BrandContext (central brand resolver), BrandThemeService (per-brand theming)
│   │   ├── Communication/      # Email/SMS dispatch
│   │   ├── Customer/           # Customer + API key services
│   │   ├── Device/             # Mobile device pairing
│   │   ├── Domain/             # Custom domain management, DomainUrlService (white-label URL resolver)
│   │   ├── Gateway/            # Gateway configuration
│   │   ├── Notification/       # Push notifications
│   │   ├── Payment/            # CurrencyService, LedgerService, IdempotencyService
│   │   ├── Sms/                # SMS parsing + templates
│   │   └── System/             # Logger, PaginationService, InputSanitizer, AuditLogger, AuditService
│   ├── Support/                # DateHelper, utilities
│   ├── Update/                 # Self-update system
│   └── View/                   # View helpers
├── storage/                    # Runtime storage (logs, cache, sessions, backups)
├── templates/                  # Twig templates
│   ├── admin/                  # Admin panel templates
│   ├── checkout/               # Checkout flow templates
│   ├── email/                  # Email templates
│   ├── error/                  # Error pages
│   ├── install/                # Installer templates
│   └── page/                   # Public pages (login, landing)
└── tests/                      # PHPUnit tests
```

---

## Database Schema

All tables prefixed with `op_`. Key tables:

| Table | Purpose |
|-------|---------|
| `op_merchants` | **Brands/Stores** (NOT independent tenants) |
| `op_merchant_users` | Admin + staff users |
| `op_roles` / `op_role_permissions` | RBAC |
| `op_transactions` | Payment transactions |
| `op_payment_intents` | Payment intents (pre-transaction) |
| `op_customers` | Customer records per brand |
| `op_gateways` | Gateway definitions |
| `op_gateway_configs` | Per-brand gateway credentials |
| `op_manual_gateways` | Manual payment gateways per brand |
| `op_api_keys` | API keys per brand |
| `op_domains` | Custom domains per brand |
| `op_invoices` | Invoices |
| `op_payment_links` | Payment links |
| `op_ledger_accounts` | Double-entry ledger accounts |
| `op_ledger_transactions` | Ledger journal headers |
| `op_ledger_entries` | Ledger debit/credit lines |
| `op_system_settings` | System-wide settings (group/key/value) |
| `op_audit_logs` | Audit trail |
| `op_plugins` | Installed plugins |
| `op_sms_templates` | SMS parsing templates |
| `op_sms_parsed` | Parsed SMS data |
| `op_paired_devices` | Mobile companion devices |
| `op_currencies` | Supported currencies |
| `op_exchange_rates` | Currency exchange rates |

### Column Naming Conventions

> **IMPORTANT**: The following column names are the actual DB column names. Code must match these exactly:

| Table | Correct Column | NOT This |
|-------|---------------|----------|
| `op_merchant_users` | `two_factor_enabled` | ~~`totp_enabled`~~ |
| `op_merchant_users` | `totp_secret_enc` | ~~`totp_secret`~~ |
| `op_currencies` | `decimal_places` | ~~`decimals`~~ |
| `op_exchange_rates` | `base_currency` | ~~`from_currency`~~ |
| `op_exchange_rates` | `target_currency` | ~~`to_currency`~~ |
| `op_sms_parsed` | `device_id` | ~~`device_uuid`~~ |
| `op_sms_parsed` | `match_status` | ~~`status`~~ (as filter) |
| `op_ledger_entries` | `type` | ~~`entry_type`~~ |
| `op_ledger_accounts` | `type` | ~~`account_type`~~ |

---

## Key Services & Patterns

### Brand Context (`src/Service/Brand/BrandContext.php`)

Central resolver for "which brand is active?" — the SINGLE source of truth.

Resolution order:
1. Request attribute (`merchant_id` from `DomainMiddleware` / `BearerAuthMiddleware`)
2. Session (`$_SESSION['active_brand_id']`)
3. Session fallback (`$_SESSION['auth_merchant_id']`)
4. Default brand (first `op_merchants` row)

**Usage in admin controllers:**
```php
$brand = $this->c->get(BrandContext::class);
$brand->resolveFromRequest($req);
$mid = $brand->getActiveBrandId();
```

### Authentication

- `src/Security/Authenticator.php` — login/logout, Argon2id, session management
- `src/Service/Auth/AuthSessionService.php` — auth-only methods (no brand logic)
- `src/Service/Admin/AdminSession.php` — session wrapper for admin controllers
- `src/Middleware/PermissionMiddleware.php` — route-based RBAC with superadmin bypass
- `src/Middleware/TwoFactorMiddleware.php` — TOTP 2FA enforcement

### Session Keys (set by `Authenticator.startSession()`)

```
$_SESSION['auth_user_id']      → int
$_SESSION['auth_merchant_id']  → int (the user's home brand)
$_SESSION['auth_role_id']      → int
$_SESSION['auth_email']        → string
$_SESSION['auth_name']         → string
$_SESSION['is_superadmin']     → bool
$_SESSION['active_brand_id']   → int (set by BrandContext brand switcher)
$_SESSION['two_fa_enabled']    → bool
```

### Repository Pattern

All repositories extend `BaseRepository` and most use the `TenantScope` trait:

```php
// Scoped to a specific brand
$repo = $this->smsRepo->forTenant($mid);
$result = $repo->paginateScoped($page, $perPage);  // TenantScope method

// Unscoped (superadmin global view)
$repo = $this->smsRepo->forAllTenants();
```

**TenantScope methods** (NOT `listPaginated` — that does not exist):
- `forTenant(int $mid): static` — set tenant scope
- `forAllTenants(): static` — remove scope
- `paginateScoped(int $page, int $perPage): array` — paginate with scope
- `findScoped(int $id): ?array` — find by ID within tenant
- `createScoped(array $data): string` — insert with merchant_id
- `updateScoped(int $id, array $data): int` — update within tenant
- `deleteScoped(int $id): int` — delete within tenant
- `countScoped(string $where, array $params): int` — count within tenant

### Plugin System

Plugins live in `modules/{gateways,addons,themes}/` and are discovered via `manifest.json`:

```json
{
  "slug": "my-gateway",
  "name": "My Gateway",
  "version": "1.0.0",
  "type": "gateway",
  "entrypoint": "MyGatewayPlugin.php",
  "csp": {
    "script_src": ["https://*.mypayment.com"],
    "style_src": ["https://*.mypayment.com"],
    "frame_src": ["https://*.mypayment.com"],
    "connect_src": ["https://api.mypayment.com"]
  }
}
```

> **IMPORTANT**: Gateway CSP domains are resolved dynamically from each gateway's `manifest.json` `"csp"` field. **NEVER hardcode gateway domains in `SecurityHeadersMiddleware.php`.** Third-party gateway plugins declare their CSP needs in their own manifest. The middleware also fires the `checkout.csp.sources` filter hook for runtime CSP declarations.

Plugins implement `PluginInterface` and register hooks via `EventManager`:
```php
$events->addAction('payment.completed', [$this, 'onPaymentCompleted']);
$events->addFilter('checkout.gateways', [$this, 'addGateway']);

// Runtime CSP: declare additional domains dynamically
$events->addFilter('checkout.csp.sources', function (array $sources): array {
    $sources['script_src'][] = 'https://*.mypayment.com';
    $sources['frame_src'][]  = 'https://*.mypayment.com';
    return $sources;
});
```

### Admin Controllers

All 28 admin controllers follow this pattern:
- Use `AdminPageTrait` for rendering (auto-injects CSRF, user context, brand data)
- Constructor: `(Container $c, AdminSession $session, ...)`
- Methods receive `Request $req`, return `Response`
- Brand scoping via `BrandContext::resolveFromRequest()`

### Mobile API Controllers (`src/Controller/Api/Mobile/`)

| Controller | Endpoints | Auth |
|-----------|-----------|------|
| `DeviceController` | pair, heartbeat, revoke, bulk-revoke, refresh, status | JWT |
| `SmsController` | receive, queue | JWT |
| `NotificationController` | index, ack | JWT |
| `DashboardController` | index | JWT |
| `ConfigController` | filterRules | JWT |

All mobile routes use `mobile` middleware group (JWT verification). Base path: `/api/mobile/v1/`.

### Ledger Service & Account Resolution (C-01 Fix)

Double-entry ledger operations post balanced debits and credits across accounts scoped strictly by merchant ID and name within `LedgerRepository::findOrCreateAccount` to prevent cross-brand leakage and type mismatches on liability accounts (e.g. `MERCHANT_PAYABLE` as a liability account and others as assets).

### Plugin Sandbox Security & Execution (C-13 Fix)

`PluginSandbox` restricts PHP code execution in activated plugins. The security scanner filters out system operations (e.g. `exec`, `shell_exec`, `eval`), but explicitly permits standard runtime helpers (`fwrite`, `ini_set`, `header`, and `setcookie`) so third-party integration gateways can process redirects, stream logs, and manage dynamic responses without entering bricked error states.

### White-Label Custom Domain Pipeline

> **CRITICAL**: OwnPay is a sovereign white-labeled fintech engine. The end-customer must **NEVER** see OwnPay's master domain. Every interaction—API response, checkout room, gateway callback, status page—must run under the merchant's configured custom domain.

**Key Components:**
- `src/Middleware/DomainMiddleware.php` — Resolves `HTTP_HOST` against `op_domains`, injects `merchant_id` + `custom_domain` into request attributes. Blocks `/admin/*` on custom domains (returns 404).
- `src/Service/Domain/DomainUrlService.php` — Central URL resolver. **ALL checkout/callback URLs MUST be generated through this service.** Never hardcode `APP_URL` for customer-facing URLs.
- `APP_DOMAIN` env var — Master domain hostname (e.g., `ownpay.test`). Admin panel only accessible here.

**URL Resolution Priority (DomainUrlService):**
1. `GATEWAY_CALLBACK_URL` env (dev ngrok override)
2. Brand's primary active custom domain (`op_domains`)
3. `APP_URL` env
4. Request host
5. Fallback: `https://localhost`

**Usage:**
```php
$urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
$checkoutUrl = $urlService->buildCheckoutUrl($merchantId, $token, $req);
$callbackUrl = $urlService->buildCallbackUrl($merchantId, $token, $req);
```

**Rules:**
- NEVER use `$_ENV['APP_URL']` or hardcode `ownpay.test` for checkout/callback URLs
- ALWAYS use `DomainUrlService` for any URL the customer or gateway will see
- Admin routes (`/admin/*`) are blocked on custom domains — DomainMiddleware returns 404
- `APP_DOMAIN` resolves from env, with fallback to parsing host from `APP_URL`

### Gateway Currency Declarations & Auto-Conversion

Each gateway adapter declares its supported currencies via `supportedCurrencies()`:

```php
// GatewayAdapterInterface
public function supportedCurrencies(): array;  // Empty = any currency

// GatewayDefaults trait (default)
public function supportedCurrencies(): array { return []; }

// bKash/Nagad (BDT-only)
public function supportedCurrencies(): array { return ['BDT']; }
```

**Auto-conversion at checkout:** When a payment intent's currency doesn't match the gateway's required currency, `PaymentIntentCheckoutController::pay()` automatically converts via `CurrencyService::convert()` using `op_exchange_rates`. Original amount/currency stored in `op_transactions.metadata` for audit:

```json
{
  "original_amount": "100.00",
  "original_currency": "USD",
  "exchange_rate": "120.50000000",
  "converted_amount": "12050.00",
  "converted_currency": "BDT"
}
```

**Gateway currency declarations:**
| Gateway | Currencies |
|---------|-----------|
| bKash | `['BDT']` |
| Nagad | `['BDT']` |
| SSLCommerz | `['BDT', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD']` |
| Stripe/PayPal/others | `[]` (any currency via GatewayDefaults) |

### Brand Theme Engine (`src/Service/Brand/BrandThemeService.php`)

Per-brand visual customization for checkout pages under custom domains.

**Resolution priority (per field):** Brand-scoped `op_system_settings` > `op_merchants.settings` JSON > global `op_system_settings`

**Data returned by `getBrandTheme(int $merchantId)`:**
- `name`, `logo`, `favicon`, `color`, `accent_color`, `support_email`
- `custom_css`, `custom_js` — injected into checkout template
- `footer_text`, `show_powered_by`

**Checkout template integration (`templates/checkout/checkout.twig`):**
- Brand custom CSS: `{% if brand.custom_css %}<style>{{ brand.custom_css|raw }}</style>{% endif %}`
- Brand custom JS: `{% if brand.custom_js %}<script>{{ brand.custom_js|raw }}</script>{% endif %}`
- Theme hooks: `{{ hook('checkout.head') }}` and `{{ hook('checkout.footer') }}`

---

## Environment & Configuration

### `.env` Variables

- Check (`.env.example`) for list of .env variables and templates.

> **CRITICAL**: `APP_KEY` and `ENCRYPTION_KEY` contain base64 `=` chars. PHP's `parse_ini_file()` CANNOT parse these. Use `vlucas/phpdotenv` (Kernel) or read from `.env.temp` (installer). Never use `parse_ini_file()` on the final `.env`.

### Middleware Groups (`config/middleware.php`)

| Group | Middleware Stack |
|-------|------------------|
| `global` | SecurityHeaders, Maintenance, Domain |
| `web` | Session, CSRF |
| `admin` | Session, CSRF, RateLimiter, TwoFactor, Permission |
| `api` | CORS, RateLimiter, BearerAuth, Idempotency |
| `api-public` | CORS, RateLimiter |
| `mobile` | CORS, RateLimiter, JwtAuth |
| `webhook` | IpAllowlist, RequestSignature |
| `checkout` | Session, CSRF, RateLimiter |
| `install` | SecurityHeaders only (no DB deps) |

### System Settings (`op_system_settings`)

Runtime settings stored as group/key/value in DB, accessed via `SettingsRepository`:
```php
$settings->get('general', 'site_name', 'Own Pay');
$settings->set('general', 'site_name', 'My Brand');
```

---

## Coding Standards & Conventions

### PHP

- `declare(strict_types=1)` in every file
- PSR-4 autoloading under `OwnPay\` namespace
- All repository methods use parameterized queries (no string interpolation for values)
- `DateHelper::nowMicro()` for timestamps (microsecond precision)
- `SecurityHelpers::csrfToken()` for CSRF tokens (field name: `_csrf_token`)
- Password hashing: `PASSWORD_ARGON2ID`
- Encryption: AES-256-GCM via `FieldEncryptor`
- CSRF Token (C-14 Fix): Always fetch token from standard helper `\OwnPay\Security\SecurityHelpers::csrfToken()` rather than raw session indexes like `$_SESSION['csrf_token']` to ensure consistency. Default parameter field must be `_csrf_token`.

### Database

- All tables: `op_` prefix
- Primary keys: `id BIGINT UNSIGNED AUTO_INCREMENT`
- Timestamps: `DATETIME(6)` (microsecond precision)
- UUIDs: `CHAR(36)` generated by `UuidGenerator`
- Soft deletes: NOT used (hard deletes only)
- `merchant_id` = brand ID (do NOT rename to `brand_id`)
- Database Generated Indexing Columns: The `op_transactions` table contains STORED generated columns `invoice_id` and `payment_link_id` extracted from JSON metadata via `GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.invoice_id')) AS UNSIGNED))` to accelerate queries with direct index keys `idx_invoice_id` and `idx_payment_link_id`.
- Brand-Scoped Settings overrides: `op_system_settings` supports brand-specific settings overrides via nullable `merchant_id` with unique key constraint `uk_group_key_merchant` on `(group_name, key_name, merchant_id)`.

### Templates

- Twig 3.14 with `.twig` extension
- Admin templates in `templates/admin/`
- Layout base: `templates/admin/layout/base.twig`
- CSRF token available as `{{ csrf_token }}`
- Current user: `{{ current_user.name }}`, `{{ current_user.email }}`
- Brand data: `{{ brands }}`, `{{ active_brand }}`, `{{ active_brand_id }}`

---

## Common Tasks

### Adding a new admin page

1. Create controller in `src/Controller/Admin/`
2. Use `AdminPageTrait` and inject `Container $c`, `AdminSession $session`
3. Add route in `config/routes/web.php`
4. Add permission mapping in `PermissionMiddleware::resolvePermission()`
5. Create Twig template in `templates/admin/`
6. Add sidebar entry in `templates/admin/layout/sidebar.twig`

### Admin Sidebar Structure (v0.1.0)

Sections in order: **Dashboard → Payments → Gateways → People → Mobile & SMS → Reports & Finance → Developers → Appearance → System → Account**

- **People**: Brands, Customers, Staff, Roles & Permissions
- **Developers**: Developer Hub (sub: API Keys, Endpoint Reference, Webhooks/IPN, Documentation, Rate Limits)
- **Reports**: Reports, Audit Log, Balance Verification
- **Appearance**: Branding, Landing Page, Themes

### New Routes (v0.1.0 hardening)

**Web routes added (`config/routes/web.php`):**
```
GET  /admin/roles                → RolesController@index
POST /admin/roles/store          → RolesController@store
POST /admin/roles/{id}/update   → RolesController@update
POST /admin/roles/{id}/delete   → RolesController@delete
```

**Installer routes (`config/routes/web.php`):**
```
GET  /install                    → InstallerController@show      (install group)
POST /install/test-db            → InstallerController@testDatabase
POST /install/create-admin       → InstallerController@createAdmin
POST /install/finalize           → InstallerController@finalize
```

**API routes added (`config/routes/api.php`):**
```
GET  /api/mobile/v1/config/filter-rules  → Mobile\ConfigController@filterRules
POST /api/mobile/v1/devices/refresh      → Mobile\DeviceController@refresh
GET  /api/mobile/v1/devices/status       → Mobile\DeviceController@status
```

### Installer Architecture (`src/Controller/Install/InstallerController.php`)

4-step wizard: Requirements → Database → Admin Account → Settings.

| Step | Method | What it does |
|------|--------|-------------|
| 1 | `show(?step=1)` | Checks PHP version, extensions, writable dirs |
| 2 | `testDatabase()` | Tests DB creds, creates DB if needed, imports `schema.sql`, writes `storage/.env.temp` |
| 3 | `createAdmin()` | Creates superadmin in `op_merchant_users` + default brand in `op_merchants` |
| 4 | `finalize()` | Generates crypto keys (APP_KEY, ENCRYPTION_KEY, HMAC_KEY, JWT_SECRET), writes `.env`, seeds `op_system_settings`, writes `storage/.installed` marker |

**Security**:
- All error messages sanitized — never exposes SQL states, hostnames, or credentials
- Step validation: can’t skip to step 3+ without `.env.temp` existing
- `install` middleware group has NO database-dependent middleware
- `RateLimiterMiddleware` has try/catch to skip gracefully when DB is unavailable
- Installer locked once `storage/.installed` exists

**CRITICAL BUG (fixed)**: `finalize()` must read DB creds from `storage/.env.temp` (NOT the final `.env`). The final `.env` contains base64 keys with `=` that break `parse_ini_file()`.

### Error Handling (`src/Kernel.php::handleException()`)

- **Production** (`APP_DEBUG=false`): Renders branded `templates/error/500.twig` with zero info disclosure. Falls back to inline HTML if Twig fails.
- **Debug** (`APP_DEBUG=true`): Renders styled debug panel with sanitized paths (absolute → relative) and masked credentials.
- **API routes**: Returns `{"success": false, "message": "Internal Server Error"}` — never raw exception details.
- Error templates are self-contained (inline CSS) — no external asset dependencies.

### Adding a new repository

1. Create in `src/Repository/` extending `BaseRepository`
2. Add `use TenantScope;` if data is brand-scoped
3. Set `protected string $table = 'op_table_name';`
4. Container autowiring handles DI automatically (no explicit registration needed)

### Adding a new service

1. Create in `src/Service/{Domain}/`
2. If constructor has non-autowirable args, register in `config/services.php`
3. Otherwise, Container autowiring resolves it automatically

---

## Testing

- **Lint**: `php -l src/path/to/file.php`
- **PHPUnit**: `vendor/bin/phpunit`
- **PHPStan**: `vendor/bin/phpstan analyse`
- **Browser**: Navigate to `https://ownpay.test/` (Laragon local dev)

---

## Known Gotchas

1. **`merchant_id` ≠ SaaS tenant**. It means "brand". One admin controls all brands.
2. **Session key `auth_merchant_id`** — the user's home brand. `active_brand_id` — the currently selected brand in admin UI.
3. **TenantScope** always requires `forTenant($mid)` before queries. Forgetting this causes unscoped data leaks.
4. **Column names** — Several DB columns were renamed during V2 migration. Always verify against `database/schema.sql`, not old code.
5. **DateHelper** — Must be imported (`use OwnPay\Support\DateHelper;`). Several files had this missing after migration.
6. **Plugin hooks** — Registered in `config/hooks.php`. Plugins register their own in `boot()` method.
7. **CSRF field** — Always `_csrf_token` (not `_csrf`). Validated by `CsrfMiddleware`.
8. **Plugin name enrichment** — `op_plugins.name` column often stores the slug (not the human-readable name). `AddonController` and `ThemeController` both do a **two-pass enrichment**: first from `manifest` JSON column, then from filesystem `PluginLoader::discover()`. Always prefer filesystem manifest name. DB slug must match manifest `slug` field.
9. **`paginateScoped()` not `listPaginated()`** — `TenantScope` exposes `paginateScoped(int $page, int $perPage)`. There is NO `listPaginated()` method. Using wrong method name causes fatal runtime error.
10. **Mobile JWT tokens** — Issued by `POST /api/mobile/v1/devices/pair`. Long-lived refresh tokens renewed via `POST /api/mobile/v1/devices/refresh`. Access tokens are short-lived (24h). Device must heartbeat to stay `active`.
11. **op_plugins slug mismatch** — Theme `own-pay-theme` was stored with wrong slug. Correct slug is `own-pay` (matches manifest). `active_theme` system setting must also match.
12. **`parse_ini_file()` vs `.env`** — NEVER use `parse_ini_file()` on the final `.env` file. Base64 values (`APP_KEY`, `ENCRYPTION_KEY`) contain `=` which breaks the parser. Use `vlucas/phpdotenv` (Kernel does this) or read `storage/.env.temp` (installer does this).
13. **Installer middleware** — The `install` middleware group must NOT include `RateLimiterMiddleware` or any middleware that depends on a database connection. During install, no `.env` or DB exists yet.
14. **Error responses** — Installer controller catches all `\Throwable` and returns sanitized generic messages. Never expose raw SQL error text, hostnames, or file paths in any JSON response.
15. **Installer step validation** — Steps 3-4 require `storage/.env.temp` to exist. If missing, controller redirects to step 2. This prevents skipping ahead in the wizard.
16. **Crypto key generation** — `APP_KEY` and `ENCRYPTION_KEY` are `base64_encode(random_bytes(32))`. `HMAC_KEY` and `JWT_SECRET` are `bin2hex(random_bytes(32))`. Each key serves a different cryptographic purpose per PCI-DSS 3.6.
17. **Brand status combobox enum mismatch (H-11 Fix)** — Twig templates rendering merchant status forms must select values matching the database `op_merchants` status column enum `('active','suspended','pending')`. Replaced `"inactive"` options with `"pending"` (or `"suspended"`) in the HTML form to avoid PDO warning data truncation crashes on save.
18. **Manual gateway logos relative path** — In templates (e.g. `index.twig`, `edit-manual.twig`, `manual-gateway.twig`), prefix all manual gateway logo paths and QR code paths with `/storage/` (e.g., `src="/storage/{{ mg.logo_path }}"`). If left relative, they will resolve incorrectly relative to current route like `/admin/gateways/` and return broken 404 image icons.
19. **Invoice line-items dynamic update & calculation** — `InvoiceService::update()` must compute subtotal dynamically by looping over form line-items (extracting quantity and pricing values) just like `create()`. Failing to calculate dynamically, or saving without a `total` input key, results in subtotal and total being overwritten to `0.00` BDT. It must also purge old entries in `op_invoice_items` and insert new ones to sync the line items database table.
20. **CSRF empty token on checkout (C-14 Fix)** — Checkout pages dynamically rendering forms must retrieve CSRF tokens strictly via `\OwnPay\Security\SecurityHelpers::csrfToken()`. Manual session retrieval under incorrect indexes like `$_SESSION['csrf_token']` results in empty token fields, causing payment forms to throw immediate 403 Forbidden validation failures.
21. **Device pairing token fallback** — `DevicePairingService` retrieves owner info from pairing tokens. Ensure to query `created_by` or `admin` columns, falling back to superadmin ID `1` when missing, to avoid JWT validation issues during companionship pairing.
22. **`DomainUrlService` mandatory for checkout/callback URLs** — NEVER use `$_ENV['APP_URL']` or hardcode `ownpay.test` for customer-facing or gateway-facing URLs. ALWAYS use `DomainUrlService::buildCheckoutUrl()` or `buildCallbackUrl()`. Inline URL resolution blocks were the root cause of the `[2049] Invalid Merchant Callback URL` bKash error and master domain exposure.
23. **`APP_DOMAIN` env var** — Required for `DomainMiddleware` to identify the master domain. Must be the bare hostname (e.g. `ownpay.test`), NOT a URL. If not set, DomainMiddleware falls back to parsing the host from `APP_URL`. Without this, admin panel may be accessible on custom domains.
24. **Admin routes blocked on custom domains** — `DomainMiddleware` returns 404 for any path starting with `/admin/` or exactly `/admin` when the request arrives on a custom domain. This is a security requirement — admin panel must only be accessible on the master `APP_DOMAIN`.
25. **`supportedCurrencies()` on gateway adapters** — All gateway adapters implementing `GatewayAdapterInterface` MUST implement `supportedCurrencies(): array`. Empty array means any currency. `GatewayDefaults` trait provides default (empty). BDT-only gateways (bKash, Nagad) MUST return `['BDT']`. Failing to declare currencies disables automatic currency conversion at checkout.
26. **Currency conversion audit trail** — When auto-conversion happens at checkout, the original amount/currency and converted amount/currency are stored in `op_transactions.metadata` JSON (`original_amount`, `original_currency`, `exchange_rate`, `converted_amount`, `converted_currency`). These metadata keys must not be overwritten by other code.
27. **`loadBrand()` must use `BrandThemeService`** — Both `PaymentIntentCheckoutController::loadBrand()` and `CheckoutController::loadBrand()` must resolve via `BrandThemeService::getBrandTheme()` when available (`$this->c->has()` check). This ensures per-brand theming (custom CSS/JS, logo, colors) renders correctly under custom domains. Direct `$this->merchants->find()` bypasses brand-scoped settings.
28. **Centralized JWT Secret & Stateless Token Rotation** — `DevicePairingService` resolves JWT secrets hierarchically (device-specific -> global -> test fallback) and enforces token rotation on refresh. Replay attacks are prevented by blacklisting rotated JTIs in `op_cache` table (using `key_name` / `expires_at` columns).
29. **JwtService::encode()/decode() — no per-call secret (BUG-001 Fix)** — `JwtService::encode()` and `decode()` no longer accept a `$secret` parameter. They use `$this->secret` exclusively (resolved from `JWT_SECRET` env). This eliminates the triple-secret-source mismatch between issue/verify paths. All callers must be updated to remove the secret argument.
30. **JWT iss/aud claims are REQUIRED (BUG-017 Fix)** — `JwtAuthMiddleware` now rejects tokens that lack `iss` or `aud` claims. Previously these were only checked IF present, allowing bypass. All JWT tokens must include `iss` (APP_NAME) and `aud` ('ownpay-mobile').
31. **TOTP replay protection (BUG-021 Fix)** — `TwoFactorMiddleware::verifyTotp()` tracks the last used time slice in `$_SESSION['totp_last_used_window']`. A TOTP code cannot be replayed within the ±1 window period.
32. **CSRF canonical key is `_csrf_token` ONLY (BUG-005 Fix)** — Removed all references to legacy `csrf_token` (without underscore). Only `_csrf_token` is valid. This eliminates the dual-key inconsistency.
33. **HMAC_KEY or APP_KEY REQUIRED for checkout (BUG-010 Fix)** — `PaymentIntentCheckoutController` throws `RuntimeException` if neither `HMAC_KEY` nor `APP_KEY` is configured. The previous `'fallback-key'` static default has been removed.
34. **DomainMiddleware blocks unknown domains (BUG-006 Fix)** — Requests arriving on domains not in `op_domains` (or with inactive status) now receive a 404 response instead of passing through to the application without brand context.
35. **LedgerService must use scoped clone (BUG-009 Fix)** — `TenantScope::forTenant()` returns a CLONE. The original `$this->ledger` instance retains its previous scope. Code inside transaction closures must capture and use the returned clone, not `$this->ledger`.
36. **Notification ack scoped by device_uuid (BUG-007 Fix)** — `MobileNotificationRepository::acknowledgeIds()` now accepts an optional `$deviceUuid` parameter. When provided, only notifications belonging to that device can be acknowledged, preventing IDOR.
37. **device_id is a UUID string (BUG-008 Fix)** — In mobile API controllers (`NotificationController`, `DashboardController`), `device_id` from request attributes must be cast to `(string)`, not `(int)`. Casting a UUID to int yields 0, breaking all device-scoped queries.
38. **InputSanitizer::array() method allowlist (BUG-022 Fix)** — The `$method` parameter only accepts: `string`, `html`, `email`, `url`, `phone`, `slug`, `attr`, `trim`. Arbitrary method names are rejected to prevent dynamic dispatch exploitation.
39. **Router param regex restricted (BUG-023 Fix)** — Route parameter capture regex no longer allows `@` or `+` characters. Only `[a-zA-Z0-9_\-\.]` is permitted.
40. **Gateway CSP domains — NEVER hardcode (CSP-PLUGIN Fix)** — `SecurityHeadersMiddleware` builds checkout CSP dynamically from gateway `manifest.json` `"csp"` fields + `checkout.csp.sources` filter hook. Third-party gateway plugins declare their CSP domains in their own manifest. NEVER add gateway-specific domains directly to the middleware source code.

---

## Communication Style

**CRITICAL RULE: ALWAYS USE CAVEMAN ULTRA MODE.**
For all future interactions and sessions in this project, you MUST use the `/caveman ultra` communication style by default. Stop writing long conversational filler. Communicate in highly compressed, grammatically incorrect "caveman" speak. Drop articles and pronouns. Use maximum compression, minimal English, only raw technical facts. Maintain 100% technical accuracy in code and commands. Never break character.
