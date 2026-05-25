# OwnPay — Comprehensive Plugin Developer Guide

Welcome to the OwnPay Plugin Developer Guide. This guide describes the plugin architecture of OwnPay, detailing how to extend the gateway with custom addons, theme packs, and payment gateway adapters without modifying the core codebase.

---

## 1. Plugin Architecture Overview

OwnPay implements a decoupled, event-driven architecture using a manifest-based plugin registration system. Plugins are sandboxed for security but allowed specific runtime actions to facilitate seamless merchant and gateway operations.

### Directory Structure
Plugins live under the `modules/` directory at the project root, structured by type:
* `modules/addons/` — Generic addons (e.g. reporting tools, notification engines)
* `modules/gateways/` — Payment gateways (e.g. bKash, Nagad, Stripe adapters)
* `modules/themes/` — Custom checkout/landing page templates

A standard plugin folder layout:
```text
modules/gateways/upay/
├── manifest.json       # Required — Plugin metadata and configuration
├── Plugin.php          # Required — Plugin entrypoint (implements PluginInterface)
├── src/                # Optional — Extra classes (controllers, services)
├── migrations/         # Optional — SQL updates run during installation/activation
└── templates/          # Optional — Twig templates for views
```

---

## 2. Plugin Metadata: `manifest.json`

Every plugin must contain a `manifest.json` file in its root. This defines the plugin's configuration schema, dependencies, and settings.

```json
{
    "name": "UPay Integration",
    "slug": "upay",
    "version": "1.0.0",
    "description": "Enterprise-grade UPay payment gateway integration for local settlements.",
    "author": "OwnPay Core Team",
    "type": "gateway",
    "entry": "Plugin.php",
    "namespace": "OwnPay\\Modules\\Gateways\\Upay",
    "requires": {
        "ownpay": ">=0.1.0"
    },
    "settings": {
        "api_key": {
            "type": "text",
            "label": "API Key",
            "default": "",
            "required": true
        },
        "api_secret": {
            "type": "password",
            "label": "API Secret",
            "default": "",
            "required": true
        },
        "merchant_id": {
            "type": "text",
            "label": "Merchant ID",
            "default": "",
            "required": true
        },
        "sandbox": {
            "type": "boolean",
            "label": "Sandbox Mode",
            "default": true
        }
    }
}
```

### Manifest Fields
* **slug**: Unique URL-safe identifier. Also dictates the dynamic webhook path: `/webhook/{slug}`.
* **type**: Must be either `gateway`, `addon`, or `theme`.
* **entry**: Path to the entrypoint class file relative to the plugin root.
* **namespace**: The PHP namespace base. OwnPay uses PSR-4 routing mapping this namespace to the plugin root folder.
* **settings**: Fields defined here are rendered in the Admin Dashboard under **System → Plugins → [Plugin Settings]** and stored per-brand.

---

## 3. Entrypoint Class: `Plugin.php`

The entrypoint class defined in `manifest.json`'s `entry` key MUST implement `OwnPay\Plugin\PluginInterface`.

```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Upay;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;

final class Plugin implements PluginInterface
{
    private array $settings = [];

    /**
     * Called during system boot to register event hooks and filters.
     */
    public function register(EventManager $events): void
    {
        // Register webhook receiver for dynamic IPN handling
        $events->addAction('webhook.incoming.upay', [$this, 'handleWebhook']);

        // Inject this gateway into checkout page lists
        $events->addFilter('checkout.gateways', [$this, 'addGateway']);
    }

    /**
     * Injected automatically by the PluginLoader containing brand-specific values.
     */
    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * Returns manifest data to the core loader.
     */
    public function getInfo(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/manifest.json'), true) ?: [];
    }

    /**
     * Injected gateway details filter callback.
     */
    public function addGateway(array $gateways): array
    {
        $gateways[] = [
            'slug' => 'upay',
            'name' => 'UPay',
            'type' => 'api',
            'currencies' => $this->supportedCurrencies(),
        ];
        return $gateways;
    }

    /**
     * Declares currencies accepted by this gateway.
     * Required for auto-conversion at checkout.
     */
    public function supportedCurrencies(): array
    {
        return ['BDT'];
    }
}
```

---

## 4. Sandbox Security & Runtime Restrictions

OwnPay isolates plugins using a custom security scanner (`src/Plugin/PluginSandbox.php`). Before a plugin is loaded or scanned, its PHP files are parsed to prevent arbitrary code execution or backdoor scripts.

### Forbidden Operations
The sandbox raises an immediate validation failure and prevents activation if it matches any of these keywords:
* Operating System execution: `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`.
* Dynamic PHP execution: `eval`, `create_function`, `assert`.
* Dangerous filesystem modifications: access outside of `modules/` and `storage/`.

### Permitted Runtime Helpers (C-13 Fix)
To prevent bricked states during payment redirects and logging, standard HTTP/IO methods are explicitly allowed:
* `header()` — Required to redirect customers to gateway payment windows.
* `setcookie()` — Required for session persistence and tracking redirects.
* `fwrite()` — Allowed specifically for writing local log streams.
* `ini_set()` — Allowed for adjustment of memory limits during big payload processing.

---

## 5. Dynamic Webhook Routing (Zero Core Modification)

Creating payment gateways typically requires adding endpoint routes. OwnPay eliminates this via **Dynamic Webhook Routing**.

```text
HTTP POST /webhook/upay ──> DomainMiddleware (resolves merchant) 
                        ──> Webhook/UnifiedWebhookController 
                        ──> EventManager -> doAction('webhook.incoming.upay')
```

### The WebhookPayload Object
The `webhook.incoming.{slug}` hook receives a `OwnPay\Model\WebhookPayload` value object containing:
* `$payload->gateway` (string) — the gateway slug, e.g. `"upay"`
* `$payload->merchantId` (int) — Brand context resolved automatically from the request domain or payload audit trail.
* `$payload->rawBody` (string) — The raw body payload. Essential for signature verification.
* `$payload->ip` (string) — Remote request IP address. Use for verifying gateway source IPs.
* `$payload->json()` (array) — Decodes JSON body.
* `$payload->formData()` (array) — Decodes URL-encoded forms.
* `$payload->header(string $name)` (string|null) — Case-insensitive HTTP header retriever.

### Example Webhook Verification
```php
public function handleWebhook(\OwnPay\Model\WebhookPayload $payload): void
{
    $signature = $payload->header('X-UPay-Signature');
    $secret = $this->settings['api_secret'] ?? '';

    // Verify HMAC-SHA256 signature to prevent spoofing
    $expected = hash_hmac('sha256', $payload->rawBody, $secret);
    if (!hash_equals($expected, $signature ?? '')) {
        return; // Spoofed request, discard silently
    }

    $data = $payload->json();
    $txId = $data['transaction_id'] ?? null;

    if ($txId && $data['status'] === 'SUCCESS') {
        // Complete transaction via PaymentService
        $this->paymentService->markCompleted($txId, $data['gateway_reference']);
    }
}
```

---

## 6. Gateway Currencies & Auto-Conversion

Gateways must tell the checkout system which currencies they accept. 
* Add `supportedCurrencies(): array` method inside your Plugin entrypoint class.
* If a gateway accepts any currency (e.g. Stripe, PayPal), return an empty array `[]`.
* If a gateway is BDT-only (e.g. bKash, Nagad, UPay), return `['BDT']`.

### Auto-Conversion Flow
1. Customer initiates a transaction in `USD`.
2. Customer selects `UPay` (which declares `['BDT']`).
3. The checkout flow detects mismatch: `USD` vs `BDT`.
4. It calls `CurrencyService::convert(amount, 'USD', 'BDT')` leveraging rates defined in `op_exchange_rates`.
5. It initiates the payment payload with BDT values.
6. The original amount, currency, and conversion rate are persisted inside `op_transactions.metadata` JSON for audit trails.

---

## 7. Developer Rules & Best Practices

1. **Strict Types**: Always write `declare(strict_types=1);` at the top of your PHP files.
2. **Never log PII**: Do not write customer phone numbers, emails, passwords, or transaction tokens to log files.
3. **Namespace Alignment**: Ensure your PHP namespace matches your manifest declaration exactly.
4. **Use InputSanitizer**: Sanitize all inputs before querying database tables.
5. **DNS/Custom Domains**: Webhook callback URLs MUST be generated dynamically using the `DomainUrlService` to guarantee custom domain visibility. Never use `$_ENV['APP_URL']`.

---

## 8. Complete Reference
* List of hooks, filters, and lifecycle triggers: [Hooks Reference](docs/v2/plugins/hooks-reference.md).

---

## 9. Scaffolding Modules with the OwnPay CLI

To speed up extension development and ensure strict adherence to secure coding invariants (strict typing, PSR-4 structure, PCI-DSS compliance, timing-safe webhook validations), OwnPay provides an interactive developer CLI tool located at `cli/create-module.php`.

### Running the Generator

Execute the command from your project root:

```bash
php cli/create-module.php
```

### Advanced Features & What It Scaffolds

The interactive wizard automates the entire scaffolding process cleanly:

1. **Auto Slug Derivation**: The slug is automatically derived from the Module Name to prevent structural deviations.
2. **Custom Logo Placement Guide**: To maintain pure white-labeling, the CLI generates a custom logo guide inside the terminal, outlining how to place your branding logo (`SVG`, `PNG`, or `JPG` format) inside your extension's `assets/` folder and map it via `"icon": "assets/icon.png"` in your manifest.
3. **Comprehensive manifest.json**: Pre-generates all possible manifest parameters (CSP attributes, capabilities lists, permission requirements, hook subscriptions, settings groups, and asset arrays) to serve as a complete reference dictionary.
4. **Gateway Plugins**: Complete `GatewayAdapterInterface` implementation with secure `initiate()`, `verify()` (backchannel API validated), `verifyWebhook()` (timing-safe HMAC checked), and encrypted credentials configuration field schema.
5. **Addon Plugins**: Basic `PluginInterface` with Capability enums, sample settings, webhook actions, and transaction subscribers.
6. **Themes (PHP or Twig Templates)**:
   - **Twig Templates**: Generates standard twig files (`checkout.twig`, `checkout-status.twig`, `payment-link-amount.twig`).
   - **PHP Templates (Default)**: Generates lightweight twig bridge wrappers along with pure, standard modern PHP files (`checkout.php`, `checkout-status.php`, `payment-link-amount.php`). Registers a custom namespaced `render_php()` function dynamically inside the theme entrypoint, enabling secure and native PHP template rendering within core checkout controllers.

### Generated Layout

For a new theme `modern-dark` using PHP templates:
```text
modules/themes/modern-dark/
├── manifest.json                                # Enriched manifest featuring all available tags
├── Theme.php                                    # Entrypoint class featuring the dynamic PHP renderer
├── assets/
│   ├── css/checkout.css                         # Pre-styled checkout theme styling rules
│   └── js/
│       ├── checkout.js                          # Theme DOM action logic
│       └── op-fetch.js                          # Secure backchannel fetch client
└── templates/
    └── checkout/
        ├── checkout.twig                        # Twig bridge wrapper
        ├── checkout-status.twig                 # Twig status bridge wrapper
        ├── payment-link-amount.twig             # Twig link amount bridge wrapper
        ├── checkout.php                         # Pure PHP checkout layout view template
        ├── checkout-status.php                  # Pure PHP checkout status display
        └── payment-link-amount.php              # Pure PHP direct payment form
```

---

## 10. Plugin Logo Resolution & Caching

To maintain a high-quality white-label experience, plugins should define an icon. The core engine dynamically handles copying these icons to the web root:
* **Icon Definition**: Define the relative icon path in your `manifest.json` under `"icon": "assets/icon.png"` (or other formats such as `.svg`, `.png`, `.jpg`, `.webp`).
* **Dynamic Copying**: During admin dashboard rendering, the system automatically calls `PluginManager::resolveIconPath()`. This copies the icon asset from the sandboxed module folder to `/public/assets/img/gateways/{slug}.{ext}` on the fly, enabling web browsers to render it directly.

