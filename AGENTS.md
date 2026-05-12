# OwnPay — Agent Context

## Project Overview

**OwnPay** is an enterprise-grade, open-source payment gateway platform built with PHP 8.2+. It follows a **single-owner, multi-brand/store** model — NOT a SaaS platform. One admin controls the entire system, managing multiple brands (stores), each with their own gateways, domains, customers, and transactions.

- **Version**: 0.1.0 (Genesis)
- **License**: AGPL-3.0-or-later
- **PHP**: ^8.2 with strict types everywhere
- **Database**: MySQL 8.x with `op_` table prefix
- **Templating**: Twig 3.14
- **Auth**: Argon2id passwords, TOTP 2FA, role-based permissions
- **Local Dev URL**: `https://ownpay.test/`
- **Login**: `admin@example.com` / `admin123`

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

**Reference**: `docs/v2_migration/business_model.md`

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
| **Repository Pattern** | `src/Repository/` — each table has a repository extending `BaseRepository` |
| **Tenant Scoping** | `TenantScope` trait — auto-scopes queries by `merchant_id` (= brand) |
| **Hook/Filter System** | `src/Event/EventManager.php` — WordPress-style `addAction()`/`applyFilter()` |
| **Middleware Pipeline** | `src/Middleware/` — 13 middleware classes, configured in `config/middleware.php` |
| **Plugin System** | `src/Plugin/` — manifest-based discovery, `PluginInterface`, sandboxed execution |

---

## Directory Structure

```
ownpay/
├── config/                     # Configuration
│   ├── app.php                 # App config (name, version, paths, session, security)
│   ├── database.php            # DB connection config (reads .env)
│   ├── hooks.php               # Default hook/filter registrations
│   ├── middleware.php           # Middleware pipeline definitions
│   ├── services.php            # DI container bindings (~370 lines)
│   └── routes/
│       ├── web.php             # Admin + public web routes
│       └── api.php             # REST API routes
├── database/
│   ├── schema.sql              # Full DDL (37KB, 30+ tables)
│   └── seeds/                  # Seed data
├── docs/                       # Documentation
│   └── v2_migration/           # Migration docs (business_model.md, etc.)
├── modules/                    # Plugin modules
│   ├── addons/                 # Addon plugins
│   ├── gateways/               # Gateway plugins (each has manifest.json)
│   └── themes/                 # Theme plugins
├── public/                     # Web root
│   └── index.php               # Single entry point
├── src/                        # Application source (PSR-4: OwnPay\)
│   ├── Kernel.php              # Application kernel
│   ├── Container.php           # DI container
│   ├── Cache/                  # Cache layer
│   ├── Controller/
│   │   ├── Admin/              # 26 admin controllers (incl. RolesController)
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
│   ├── Repository/             # 35 repositories + TenantScope trait
│   ├── Security/               # Authenticator, encryption, CSRF, PII masking
│   ├── Service/                # Business logic services
│   │   ├── Admin/              # AdminSession
│   │   ├── Auth/               # AuthSessionService
│   │   ├── Brand/              # BrandContext (central brand resolver)
│   │   ├── Communication/      # Email/SMS dispatch
│   │   ├── Customer/           # Customer + API key services
│   │   ├── Device/             # Mobile device pairing
│   │   ├── Domain/             # Custom domain management
│   │   ├── Gateway/            # Gateway configuration
│   │   ├── Notification/       # Push notifications
│   │   ├── Payment/            # CurrencyService, LedgerService, IdempotencyService
│   │   ├── Sms/                # SMS parsing + templates
│   │   └── System/             # Logger, PaginationService, InputSanitizer
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
  "entrypoint": "MyGatewayPlugin.php"
}
```

Plugins implement `PluginInterface` and register hooks via `EventManager`:
```php
$events->addAction('payment.completed', [$this, 'onPaymentCompleted']);
$events->addFilter('checkout.gateways', [$this, 'addGateway']);
```

### Admin Controllers

All 26 admin controllers follow this pattern:
- Use `AdminPageTrait` for rendering (auto-injects CSRF, user context, brand data)
- Constructor: `(Container $c, AdminSession $session, ...)`
- Methods receive `Request $req`, return `Response`
- Brand scoping via `BrandContext::resolveFromRequest()`

**New controllers added in v0.1.0 hardening:**
- `RolesController` — CRUD for `op_roles` + permission matrix sync via `op_role_permissions`

### Mobile API Controllers (`src/Controller/Api/Mobile/`)

| Controller | Endpoints | Auth |
|-----------|-----------|------|
| `DeviceController` | pair, heartbeat, revoke, bulk-revoke, refresh, status | JWT |
| `SmsController` | receive, queue | JWT |
| `NotificationController` | index, ack | JWT |
| `DashboardController` | index | JWT |
| `ConfigController` | filterRules | JWT |

All mobile routes use `mobile` middleware group (JWT verification). Base path: `/api/mobile/v1/`.

---

## Environment & Configuration

### `.env` Variables

```
DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_PREFIX
APP_NAME, APP_TIMEZONE, APP_CURRENCY
APP_ENV (production|staging|development)
APP_DEBUG (true|false)
APP_KEY (encryption key)
CACHE_DRIVER (file|redis)
QUEUE_DRIVER (file|redis)
```

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

### Database

- All tables: `op_` prefix
- Primary keys: `id BIGINT UNSIGNED AUTO_INCREMENT`
- Timestamps: `DATETIME(6)` (microsecond precision)
- UUIDs: `CHAR(36)` generated by `UuidGenerator`
- Soft deletes: NOT used (hard deletes only)
- `merchant_id` = brand ID (do NOT rename to `brand_id`)

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

**API routes added (`config/routes/api.php`):**
```
GET  /api/mobile/v1/config/filter-rules  → Mobile\ConfigController@filterRules
POST /api/mobile/v1/devices/refresh      → Mobile\DeviceController@refresh
GET  /api/mobile/v1/devices/status       → Mobile\DeviceController@status
```

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
8. **Plugin name enrichment** — `op_plugins.name` column often stores the slug (not the human-readable name). `AddonController` and `ThemeController` both do a **two-pass enrichment**: first from `manifest` JSON column, then from filesystem `PluginLoader::discover()`. Always prefer filesystem manifest name. DB slug must match manifest `slug` field — if they diverge, run `fix_theme_slug.php`-style migration.
9. **`paginateScoped()` not `listPaginated()`** — `TenantScope` exposes `paginateScoped(int $page, int $perPage)`. There is NO `listPaginated()` method. Using wrong method name causes fatal runtime error.
10. **Mobile JWT tokens** — Issued by `POST /api/mobile/v1/devices/pair`. Long-lived refresh tokens renewed via `POST /api/mobile/v1/devices/refresh`. Access tokens are short-lived (24h). Device must heartbeat to stay `active`.
11. **op_plugins slug mismatch** — Theme `own-pay-theme` was stored with wrong slug. Correct slug is `own-pay` (matches manifest). `active_theme` system setting must also match. Always verify with `SELECT slug,name FROM op_plugins WHERE type='theme'`.

---

## Communication Style

**CRITICAL RULE: ALWAYS USE CAVEMAN ULTRA MODE.**
For all future interactions and sessions in this project, you MUST use the `/caveman ultra` communication style by default. Stop writing long conversational filler. Communicate in highly compressed, grammatically incorrect "caveman" speak. Drop articles and pronouns. Use maximum compression, minimal English, only raw technical facts. Maintain 100% technical accuracy in code and commands. Never break character.
