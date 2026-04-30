# Own Pay — Plugin Developer Guide

> Build addons and themes for Own Pay v0.1.0

## Plugin Structure

```
modules/addons/my-plugin/
├── manifest.json     # Required — plugin metadata
├── Plugin.php        # Required — entry point (implements PluginInterface)
├── migrations/       # Optional — SQL migrations
└── templates/        # Optional — Twig templates
```

## manifest.json

```json
{
    "name": "my-plugin",
    "version": "1.0.0",
    "description": "Description of your plugin.",
    "author": "Your Name",
    "type": "addon",
    "entry": "Plugin.php",
    "namespace": "OwnPay\\Modules\\Addons\\MyPlugin",
    "requires": { "ownpay": ">=0.1.0" },
    "settings": {
        "api_key": "",
        "enabled": true
    }
}
```

## Plugin.php

```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\MyPlugin;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;

final class Plugin implements PluginInterface
{
    public function register(EventManager $events): void
    {
        // Listen to hooks
        $events->addAction('payment.transaction.completed', [$this, 'onPayment']);
        
        // Modify data with filters
        $events->addFilter('checkout.data', [$this, 'modifyCheckout']);
    }

    public function onPayment(array $txn): void
    {
        // Handle completed payment
    }

    public function modifyCheckout(array $data): array
    {
        $data['custom_field'] = 'value';
        return $data;
    }

    public function getInfo(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/manifest.json'), true) ?: [];
    }
}
```

## Available Hooks

See [hooks-reference.md](hooks-reference.md) for the complete list of 50+ hooks.

## Actions vs Filters

- **Actions** — fire-and-forget, no return value expected
- **Filters** — must return the modified value

```php
// Action: side effect only
$events->addAction('payment.transaction.completed', function (array $txn) {
    // Send notification, log, etc.
});

// Filter: modify and return
$events->addFilter('checkout.gateways', function (array $gateways): array {
    $gateways[] = ['slug' => 'my-gw', 'name' => 'My Gateway'];
    return $gateways;
});
```

## Priority

Lower numbers fire first. Default priority is 10.

```php
$events->addAction('hook', $callback, 5);   // Fires early
$events->addAction('hook', $callback, 20);  // Fires later
```

## Settings

Plugin settings from `manifest.json` are loaded automatically and injected via `setSettings()`:

```php
public function setSettings(array $settings): void
{
    $this->apiKey = $settings['api_key'] ?? '';
}
```

## Building a Gateway Plugin (Webhook/IPN)

> **Zero core modification.** Register one hook — your gateway's webhook endpoint is live.

**Endpoint:** `POST /webhook/{your-gateway-slug}`

```
modules/gateways/upay/
├── manifest.json
└── Plugin.php
```

**manifest.json:**
```json
{
    "name": "upay",
    "version": "1.0.0",
    "description": "UPay payment gateway.",
    "author": "Your Name",
    "type": "gateway",
    "entry": "Plugin.php",
    "namespace": "OwnPay\\Modules\\Gateways\\Upay",
    "requires": { "ownpay": ">=0.1.0" }
}
```

**Plugin.php:**
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Upay;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;
use OwnPay\Model\WebhookPayload;

final class Plugin implements PluginInterface
{
    public function register(EventManager $events): void
    {
        // This single line enables POST /webhook/upay automatically
        $events->addAction('webhook.incoming.upay', [$this, 'handleWebhook']);

        // Also register in gateway list
        $events->addFilter('checkout.gateways', [$this, 'addGateway']);
    }

    public function handleWebhook(WebhookPayload $payload): void
    {
        // 1. Verify UPay's signature
        $signature = $payload->header('X-UPay-Signature');
        $expected = hash_hmac('sha256', $payload->rawBody, $this->apiSecret);
        if (!hash_equals($expected, $signature ?? '')) {
            return; // Invalid — silently reject
        }

        // 2. Parse gateway-specific data
        $data = $payload->json();

        // 3. Update transaction via PaymentService
        // $this->paymentService->processIpn($data['order_id'], [...]);
    }

    public function addGateway(array $gateways): array
    {
        $gateways[] = ['slug' => 'upay', 'name' => 'UPay', 'type' => 'api'];
        return $gateways;
    }

    public function getInfo(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/manifest.json'), true) ?: [];
    }
}
```

**No route files touched. No core code modified.** The URL `https://pay.merchant.com/webhook/upay` works immediately.

### WebhookPayload API

| Property/Method | Type | Description |
|----------------|------|-------------|
| `$payload->gateway` | `string` | Gateway slug from URL segment |
| `$payload->merchantId` | `int` | Resolved from domain or transaction lookup |
| `$payload->rawBody` | `string` | Raw HTTP body (for HMAC verification) |
| `$payload->ip` | `string` | Remote IP (for whitelist checks) |
| `$payload->method` | `string` | HTTP method |
| `$payload->json()` | `array` | Parse body as JSON |
| `$payload->formData()` | `array` | Parse body as form-urlencoded |
| `$payload->header('key')` | `?string` | Case-insensitive header access |
| `$payload->bodyHash()` | `string` | SHA-256 hash for dedup |

## Domain-Aware Callback URLs

Webhook URLs automatically use the merchant's custom domain:

```php
// Own Pay generates callback URL when initiating payment
$callbackUrl = $domainService->merchantUrl($merchantId, '/webhook/upay');
// Result: https://pay.merchant.com/webhook/upay
```

**Merchant ID resolution (automatic):**
1. Custom domain `Host` header → `op_domains` table → `merchant_id`
2. Fallback: transaction reference in payload → `op_transactions` → `merchant_id`

## Routes

Plugins can register custom routes via the `system.routes.register` hook:

```php
$events->addAction('system.routes.register', function (Router $router) {
    $router->get('/plugins/my-plugin/dashboard', 'MyPlugin\\DashboardController@index', 'admin');
});
```

> **Note:** For webhook/IPN endpoints, do NOT register custom routes. Use `webhook.incoming.{slug}` hook instead — it's automatic and domain-aware.

## Security Rules

1. **Never log PII** — card numbers, passwords, tokens
2. **Validate all input** — use `InputSanitizer` helpers
3. **HTTPS only** — for external API calls
4. **No eval/exec** — CSP-safe, no dynamic code execution
5. **Tenant isolation** — always scope queries by merchant_id
6. **HMAC verification** — always verify webhook signatures in your `handleWebhook()` method
7. **IP whitelisting** — use `$payload->ip` when gateway provides known IP ranges

