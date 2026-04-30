# Universal Plugin & Hook System — Architecture Plan

**Goal:** Build OwnPay as a fully extensible "Universal Fintech CMS & SaaS Platform" where gateways, features (plugins), and checkout designs (themes) can be added via ZIP upload — WITHOUT editing core code.

**Architecture:** Pure OOP event-driven hook engine, universal plugin contracts, lifecycle management, capability-based security, and admin plugin manager.

**Tech Stack:** PHP 8.2+ (strict types), MySQL/MariaDB, PSR-4, Tailwind CSS + Flowbite

---

## PARADIGM: Clean-Slate Architecture (v0.1.0)

This is the initial version. There are NO legacy systems, NO backward compatibility requirements.
- ONE universal `PluginInterface` for ALL extension types (gateways, themes, feature plugins)
- ONE pure OOP `EventManager` — the sole hook/event API
- Plugin type (gateway/theme/plugin) is determined by `manifest.json` `type` field
- 100% strict PSR-4 namespacing, `declare(strict_types=1)` everywhere
- `manifest.json` is the only manifest format

---

## 1. High-Level Directory Blueprint

```
ownpay/
├── src/                                    # Core SOA layer (OwnPay\ namespace)
│   ├── Plugin/                             # Plugin subsystem
│   │   ├── PluginInterface.php             # Universal plugin contract (ALL types)
│   │   ├── PluginManifest.php              # Parsed manifest.json value object
│   │   ├── PluginLoader.php                # Discovery, boot, lifecycle orchestrator
│   │   ├── PluginRegistry.php              # In-memory registry of activated plugins
│   │   ├── PluginInstaller.php             # ZIP install pipeline with security scanning
│   │   ├── PluginSandbox.php               # Code security scanner + capability enforcer
│   │   ├── PluginMigrator.php              # Database migration runner for plugins
│   │   └── Capability.php                  # Enum of plugin capabilities
│   ├── Event/                              # Hook/event engine
│   │   └── EventManager.php               # Pure OOP singleton event bus
│   ├── Service/
│   │   └── PluginManager.php               # Admin-facing plugin management service
│   ├── Controller/
│   │   └── PluginController.php            # Admin plugin management actions
│   ├── Cron/
│   │   └── CronJobRunner.php               # Cron runner with plugin job registration
│   └── Http/
│       └── Router.php                      # Router with plugin route registration
│
├── app/
│   ├── modules/                            # Plugin storage
│   │   ├── gateways/                       # Payment gateway plugins
│   │   │   └── <slug>/
│   │   │       ├── manifest.json           # Required manifest
│   │   │       ├── Gateway.php             # Main class (implements PluginInterface)
│   │   │       └── assets/
│   │   ├── plugins/                        # Feature plugins
│   │   │   └── <slug>/
│   │   │       ├── manifest.json           # Required manifest
│   │   │       ├── Plugin.php              # Main class (implements PluginInterface)
│   │   │       ├── migrations/             # Optional SQL migrations
│   │   │       │   ├── 001_create_tables.sql
│   │   │       │   └── 002_add_columns.sql
│   │   │       ├── views/                  # Optional admin page templates
│   │   │       │   └── settings.php
│   │   │       └── assets/
│   │   │           ├── plugin.css
│   │   │           └── plugin.js
│   │   └── themes/                         # Checkout themes
│   │       └── <slug>/
│   │           ├── manifest.json           # Required manifest
│   │           ├── Theme.php               # Main class (implements PluginInterface)
│   │           ├── checkout.php            # Template files
│   │           ├── checkout-status.php
│   │           ├── invoice.php
│   │           ├── payment-link.php
│   │           └── assets/
│   └── admin/dashboard/
│       └── plugins/                        # Admin plugin manager UI
│           ├── index.php                   # Plugin list (installed, activate/deactivate)
│           ├── install.php                 # Upload ZIP installer
│           └── settings.php               # Plugin settings view
│
├── storage/
│   └── plugins/                            # Plugin runtime data
│       ├── cache/
│       │   └── plugin_registry.json        # Cached activated plugin list
│       ├── backups/                         # Plugin backup before upgrade
│       └── migrations/                     # Migration lock files
```

### Key Decisions
- Three module directories: `gateways/`, `plugins/`, `themes/` — all share one `PluginInterface`
- `manifest.json` is the only manifest format
- Entrypoint convention: `Gateway.php`, `Plugin.php`, `Theme.php` (configurable in manifest)
- `src/Plugin/` namespace houses all plugin infrastructure

---

## 2. The Hook System — EventManager

### `src/Event/EventManager.php` — Pure OOP Singleton

The sole hook/event API for the entire platform. No procedural wrappers.

```
EventManager (singleton)
├── addAction(string $hook, callable $cb, int $priority, ?string $owner)
├── addFilter(string $hook, callable $cb, int $priority, ?string $owner)
├── removeAction(string $hook, callable $cb)
├── removeFilter(string $hook, callable $cb)
├── removeAllByOwner(string $owner)        # Bulk remove all hooks from a plugin
├── doAction(string $hook, mixed ...$args)
├── applyFilters(string $hook, mixed $value, mixed ...$args)
├── hasAction(string $hook): bool
├── hasFilter(string $hook): bool
├── getRegistered(): array                 # Debug: list all hooks
└── inspectHook(string $hook): array       # Debug: detail for one hook
```

Features:
- Priority-based execution (lower = earlier, default 10)
- Owner tracking per callback (plugin slug) — enables bulk removal on deactivation
- Exception isolation: one broken callback cannot crash the request
- Lazy sorting: priority sort only on first dispatch after registration

### Hook Naming Convention
```
{domain}.{entity}.{event}

Examples:
  payment.transaction.created       # After transaction created
  payment.transaction.completed     # After payment completed
  payment.gateway.process_before    # Before gateway processes payment
  invoice.created                   # After invoice created
  invoice.total                     # Filter: invoice total amount
  admin.menu.register               # When admin sidebar builds
  admin.dashboard.widgets           # Filter: dashboard widget list
  plugin.{slug}.activated           # After a specific plugin activates
  cron.{job_name}.before            # Before a cron job runs
  theme.checkout.data               # Filter: modify checkout page data
```

### Core Hook Points

**Actions (fire-and-forget):**
| Hook | Location | When |
|------|----------|------|
| `system.boot` | `adapter.php` | After middleware, before routing |
| `system.shutdown` | `index.php` | End of request |
| `admin.menu.register` | `app/admin/dashboard/sidebar.php` | Building sidebar |
| `admin.head` | Admin layout | `<head>` section |
| `admin.footer` | Admin layout | Before `</body>` |
| `payment.transaction.created` | `TransactionService` | After txn insert |
| `payment.transaction.completed` | `TransactionService` | After status -> completed |
| `payment.checkout.before_render` | Checkout controllers | Before theme render |
| `plugin.activated` | `PluginController` | After plugin activated |
| `plugin.deactivated` | `PluginController` | After plugin deactivated |

**Filters (transform data):**
| Hook | Location | What it transforms |
|------|----------|--------------------|
| `invoice.total` | `InvoiceService` | Invoice total amount |
| `mfs.providers` | `CheckoutController` | MFS provider list |
| `checkout.gateways` | `CheckoutController` | Available gateway list |
| `checkout.page_data` | Checkout controllers | Full page data array |
| `admin.dashboard.stats` | `DashboardController` | Dashboard statistics |
| `transaction.export.row` | `TransactionController` | Each exported row |

---

## 3. Anatomy of a Plugin

### 3A. manifest.json (Required)

```json
{
  "name": "SMS Notification Plugin",
  "slug": "sms-notifications",
  "version": "1.2.0",
  "type": "plugin",
  "description": "Send SMS notifications on payment events",
  "author": "OwnPay Team",
  "author_url": "https://ownpay.org",
  "license": "AGPL-3.0",
  "min_php": "8.2",
  "min_app": "2.0.0",
  "entrypoint": "Plugin.php",
  "namespace": "SmsNotifications",

  "capabilities": [
    "db_read",
    "db_write",
    "http_outbound",
    "cron",
    "admin_menu",
    "settings"
  ],

  "dependencies": [],

  "hooks": {
    "actions": [
      "payment.transaction.completed",
      "invoice.created"
    ],
    "filters": [
      "checkout.page_data"
    ]
  },

  "admin_menu": [
    {
      "title": "SMS Notifications",
      "slug": "sms-notifications",
      "icon": "message-square",
      "parent": "settings",
      "permission": "manage_plugins"
    }
  ],

  "cron": [
    {
      "name": "sms_retry_failed",
      "schedule": "*/5 * * * *",
      "description": "Retry failed SMS deliveries"
    }
  ],

  "migrations": [
    "migrations/001_create_sms_log.sql",
    "migrations/002_add_retry_column.sql"
  ]
}
```

### 3B. Plugin.php (Main Class)

```php
<?php
namespace OwnPayPlugin\SmsNotifications;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;

class Plugin implements PluginInterface
{
    public function register(EventManager $events): void
    {
        $events->addAction('payment.transaction.completed', [$this, 'onPaymentCompleted'], owner: 'sms-notifications');
        $events->addAction('invoice.created', [$this, 'onInvoiceCreated'], owner: 'sms-notifications');
    }

    public function boot(): void
    {
        // Called after ALL plugins have registered.
    }

    public function activate(): void
    {
        // Called once when plugin is activated from admin panel.
    }

    public function deactivate(): void
    {
        // Called once when plugin is deactivated. Preserve data.
    }

    public function uninstall(): void
    {
        // Called before plugin files are deleted. Drop tables, delete settings.
    }

    public function info(): array
    {
        return [
            'title' => 'SMS Notifications',
            'description' => 'Send SMS on payment events',
            'version' => '1.2.0',
        ];
    }

    public function fields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'SMS API Key', 'type' => 'text', 'required' => true],
            ['name' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text'],
            ['name' => 'enabled', 'label' => 'Enable SMS', 'type' => 'select',
             'options' => ['yes' => 'Yes', 'no' => 'No'], 'value' => 'yes'],
        ];
    }

    public function onPaymentCompleted(array $transaction): void
    {
        // Send SMS via external API...
    }

    public function onInvoiceCreated(array $invoice): void
    {
        // Send invoice notification SMS...
    }
}
```

### 3C. Gateway Plugins

Gateways implement the same `PluginInterface`. Gateway-specific behavior (payment capture, webhook handling) is wired via hooks:

```
app/modules/gateways/stripe/
  manifest.json       # type: "gateway"
  Gateway.php         # implements PluginInterface
  assets/logo.jpg
```

```php
<?php
namespace OwnPayPlugin\Stripe;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;

class Gateway implements PluginInterface
{
    public function register(EventManager $events): void
    {
        $events->addAction('payment.gateway.capture', [$this, 'capture'], owner: 'stripe');
        $events->addAction('payment.gateway.webhook', [$this, 'webhook'], owner: 'stripe');
        $events->addFilter('checkout.gateways', [$this, 'registerGateway'], owner: 'stripe');
    }

    public function boot(): void {}
    public function activate(): void {}
    public function deactivate(): void {}
    public function uninstall(): void {}

    public function info(): array
    {
        return ['title' => 'Stripe', 'description' => 'Stripe payment gateway', 'version' => '1.0.0'];
    }

    public function fields(): array
    {
        return [
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
            ['name' => 'publishable_key', 'label' => 'Publishable Key', 'type' => 'text'],
        ];
    }

    public function capture(array $data): array { /* ... */ }
    public function webhook(array $data): void { /* ... */ }
    public function registerGateway(array $gateways): array { /* ... */ }
}
```

### 3D. Theme Plugins

Themes also implement `PluginInterface`. Theme-specific rendering is wired via hooks:

```
app/modules/themes/modern-checkout/
  manifest.json       # type: "theme"
  Theme.php           # implements PluginInterface
  checkout.php        # Template files
  invoice.php
  assets/
```

```php
<?php
namespace OwnPayPlugin\ModernCheckout;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;

class Theme implements PluginInterface
{
    public function register(EventManager $events): void
    {
        $events->addFilter('theme.checkout.render', [$this, 'renderCheckout'], owner: 'modern-checkout');
        $events->addFilter('theme.invoice.render', [$this, 'renderInvoice'], owner: 'modern-checkout');
    }

    public function boot(): void {}
    public function activate(): void {}
    public function deactivate(): void {}
    public function uninstall(): void {}

    public function info(): array
    {
        return ['title' => 'Modern Checkout', 'description' => 'Clean checkout theme', 'version' => '1.0.0'];
    }

    public function fields(): array
    {
        return [
            ['name' => 'primary_color', 'label' => 'Primary Color', 'type' => 'color', 'value' => '#3B82F6'],
        ];
    }

    public function renderCheckout(array $data): string { /* ... */ }
    public function renderInvoice(array $data): string { /* ... */ }
}
```

---

## 4. Contracts (Interfaces)

### 4A. PluginInterface — `src/Plugin/PluginInterface.php`

```php
interface PluginInterface
{
    public function register(EventManager $events): void;
    public function boot(): void;
    public function activate(): void;
    public function deactivate(): void;
    public function uninstall(): void;
    public function info(): array;
    public function fields(): array;
}
```

### 4B. Capability Enum — `src/Plugin/Capability.php`

```php
enum Capability: string
{
    case DB_READ = 'db_read';
    case DB_WRITE = 'db_write';
    case FILE_READ = 'file_read';
    case FILE_WRITE = 'file_write';
    case HTTP_OUTBOUND = 'http_outbound';
    case CRON = 'cron';
    case ADMIN_MENU = 'admin_menu';
    case SETTINGS = 'settings';
    case HOOKS = 'hooks';
    case CHECKOUT_UI = 'checkout_ui';
}
```

### 4C. PluginManifest — `src/Plugin/PluginManifest.php`

Immutable readonly value object parsed from `manifest.json`:

```php
final class PluginManifest
{
    public readonly string $name;
    public readonly string $slug;
    public readonly string $version;
    public readonly string $type;          // 'plugin' | 'gateway' | 'theme'
    public readonly string $entrypoint;    // 'Plugin.php' | 'Gateway.php' | 'Theme.php'
    public readonly string $namespace;
    public readonly array $capabilities;
    public readonly array $dependencies;
    public readonly array $hooks;
    public readonly array $adminMenu;
    public readonly array $cron;
    public readonly array $migrations;
    public readonly string $minPhp;
    public readonly string $minApp;

    public static function fromFile(string $path): self;
    public static function fromArray(array $data, string $sourcePath): self;
    public function hasCapability(Capability $cap): bool;
    public function getFullyQualifiedClassName(): string;
    public function computeHash(): string;
    public function validate(): array;
    public function toArray(): array;
}
```

---

## 5. Security & Validation Pipeline

### ZIP Upload Flow

```
Admin clicks "Upload Plugin" -> POST with ZIP file
|
+-- Step 1: BASIC VALIDATION (PluginInstaller)
|   +-- File is .zip extension
|   +-- File size < 50MB
|   +-- MIME type is application/zip
|   +-- FAIL -> "Invalid file format"
|
+-- Step 2: EXTRACT TO TEMP (PluginInstaller)
|   +-- Extract to sys_get_temp_dir()/ap_plugin_{uniqid}/
|   +-- Path traversal scan on every entry (../ or /)
|   +-- Symlink detection and rejection
|   +-- FAIL -> "Security violation: path traversal detected"
|
+-- Step 3: MANIFEST VALIDATION (PluginManifest)
|   +-- manifest.json must exist
|   +-- Required fields: name, slug, version, type, entrypoint
|   +-- slug format: /^[a-z0-9][a-z0-9\-]{1,58}[a-z0-9]$/
|   +-- type must be: plugin | gateway | theme
|   +-- entrypoint file must exist in package
|   +-- min_php version check against PHP_VERSION
|   +-- min_app version check against APP_VERSION
|   +-- FAIL -> "Invalid manifest: {specific error}"
|
+-- Step 4: CODE SECURITY SCAN (PluginSandbox)
|   +-- Scan ALL .php files for BANNED functions:
|   |   exec, shell_exec, system, passthru, proc_open,
|   |   popen, pcntl_exec, create_function,
|   |   file_get_contents (with http://),
|   |   include/require with variable paths,
|   |   Direct PDO instantiation,
|   |   $_ENV direct write access,
|   |   ReflectionClass on core classes,
|   |   preg_replace with /e modifier
|   +-- Check for .phar, .sh, .exe files in package
|   +-- Check for __halt_compiler() usage
|   +-- FAIL -> "Security violation: {function} found in {file}:{line}"
|
+-- Step 5: CAPABILITY VALIDATION (PluginSandbox)
|   +-- Parse declared capabilities from manifest
|   +-- Cross-reference code scan against declared capabilities
|   +-- FAIL -> "Undeclared capability: {cap} used in {file}:{line}"
|
+-- Step 6: ENTRYPOINT VALIDATION (PluginInstaller)
|   +-- Parse entrypoint PHP file
|   +-- Verify it declares a class
|   +-- Class must implement PluginInterface (all types)
|   +-- FAIL -> "Entrypoint class must implement PluginInterface"
|
+-- Step 7: INSTALL TO TARGET (PluginInstaller)
|   +-- Target: app/modules/{type}s/{slug}/
|   +-- If upgrading: backup to storage/plugins/backups/
|   +-- Copy files, set permissions (dirs 0755, files 0644)
|   +-- Write record to ap_plugins table (status='installed')
|   +-- Cleanup temp directory
|
+-- Step 8: AUDIT LOG
|   +-- AuditLogger::log('plugin.installed', slug, version, admin_id)
|
+-- RETURN success with plugin metadata
```

### Activation Flow

```
Admin clicks "Activate" on installed plugin
|
+-- Step 1: Load manifest, verify entrypoint exists
+-- Step 2: Instantiate plugin class
+-- Step 3: Run activate() lifecycle method
+-- Step 4: Run database migrations (PluginMigrator)
+-- Step 5: Register cron jobs from manifest
+-- Step 6: Update ap_plugins.status = 'active'
+-- Step 7: Invalidate plugin registry cache
+-- Step 8: EventManager::doAction('plugin.activated', $slug)
+-- AuditLogger::log('plugin.activated', slug)
```

---

## 6. Plugin Loader — Request Lifecycle

### Boot Sequence (runs on every request)

```
index.php
  +-- adapter.php
        +-- SessionMiddleware -> RequestContext
        +-- CsrfMiddleware
        +-- PluginLoader::boot()
              |
              +-- 1. Load registry (cached JSON or DB query)
              |     SELECT slug, type, entrypoint FROM ap_plugins
              |     WHERE status = 'active' ORDER BY load_order
              |
              +-- 2. For each active plugin:
              |     +-- require_once (with path containment validation)
              |     +-- Instantiate plugin class
              |     +-- Call $plugin->register($eventManager)
              |
              +-- 3. After ALL registered:
              |     +-- Call $plugin->boot() for each
              |
              +-- 4. EventManager::doAction('system.boot')
```

### Performance Considerations
- **Registry Cache**: `storage/plugins/cache/plugin_registry.json` — rebuilt on activate/deactivate, read from file on every request (avoids DB query)
- **No scanning on every request**: `PluginManager::scan()` only runs when admin visits plugin management page

---

## 7. Admin Menu & Route Registration

### Plugin Admin Pages

In `app/admin/dashboard/sidebar.php`:
```php
EventManager::getInstance()->doAction('admin.menu.register');
```

Plugin settings page routing:
```
adapter.php -> detects page=plugin-settings
  -> PluginController::handleSettingsPage($pluginSlug)
    -> Loads plugin's manifest
    -> Permission check (manage_plugins or custom permission)
    -> Loads plugin's views/{page}.php with sandboxed context
```

### Plugin REST Routes

```php
public function register(EventManager $events): void {
    $events->addAction('system.routes', function($router) {
        $router->post('/plugins/sms-notifications/webhook', [$this, 'handleWebhook']);
    }, owner: 'sms-notifications');
}
```

---

## 8. Database Migrations for Plugins

### Structure
```
app/modules/plugins/sms-notifications/
  migrations/
    001_create_sms_log.sql
    002_add_retry_column.sql
```

### Migration Table: `ap_plugin_migrations`
```sql
CREATE TABLE ap_plugin_migrations (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plugin_slug VARCHAR(60) NOT NULL,
    migration   VARCHAR(255) NOT NULL,
    batch       INT NOT NULL,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_plugin_migration (plugin_slug, migration)
);
```

### PluginMigrator Logic
```
PluginMigrator::migrate(string $slug)
+-- Read manifest.migrations array
+-- Query ap_plugin_migrations WHERE plugin_slug = :slug
+-- Determine pending migrations (not in applied set)
+-- For each pending (in order):
|   +-- BEGIN TRANSACTION
|   +-- Execute SQL file via Database::execute()
|   +-- INSERT INTO ap_plugin_migrations
|   +-- COMMIT (or ROLLBACK on error)
+-- Return count of applied migrations

PluginMigrator::rollback(string $slug)
+-- Query last batch number for this plugin
+-- Get migrations from that batch
+-- For each (reverse order):
|   +-- If {migration}_down.sql exists, execute it
|   +-- DELETE FROM ap_plugin_migrations
+-- Return count of rolled back
```

---

## 9. Database Schema

### `ap_plugins` table
```sql
CREATE TABLE ap_plugins (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(60) NOT NULL,
    name            VARCHAR(150) NOT NULL,
    type            ENUM('plugin','gateway','theme') NOT NULL DEFAULT 'plugin',
    version         VARCHAR(20) NOT NULL,
    status          ENUM('installed','active','inactive') NOT NULL DEFAULT 'installed',
    entrypoint      VARCHAR(100) NOT NULL DEFAULT 'Plugin.php',
    capabilities    JSON NULL,
    manifest_hash   CHAR(64) NOT NULL,
    load_order      INT NOT NULL DEFAULT 100,
    activated_at    DATETIME NULL,
    installed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_plugin_slug (slug),
    INDEX idx_plugin_status (status, load_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `ap_plugin_migrations` table
As described in section 8 above.

---

## 10. Critical Files

| File | Role |
|------|------|
| `src/Event/EventManager.php` | Sole hook/event API |
| `src/Plugin/PluginInterface.php` | Universal plugin contract |
| `src/Plugin/Capability.php` | Capability enum |
| `src/Plugin/PluginManifest.php` | Manifest parser |
| `src/Plugin/PluginLoader.php` | Boot orchestrator |
| `src/Plugin/PluginRegistry.php` | Active plugin registry + cache |
| `src/Plugin/PluginInstaller.php` | ZIP install pipeline |
| `src/Plugin/PluginSandbox.php` | Security scanner |
| `src/Plugin/PluginMigrator.php` | Migration runner |
| `src/Controller/PluginController.php` | Admin actions |
