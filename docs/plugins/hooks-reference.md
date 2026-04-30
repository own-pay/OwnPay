# Own Pay — Hooks Reference

> Complete list of action and filter hooks available in v0.1.0

## Lifecycle Hooks

| Hook | Type | Fired From | Description |
|------|------|------------|-------------|
| `system.boot` | Action | Bootstrap | System initialized |
| `system.routes.register` | Action | Router | Plugin route injection point |
| `system.shutdown` | Action | Bootstrap | Graceful shutdown |

## Authentication

| Hook | Type | Description |
|------|------|-------------|
| `auth.login.before` | Action | Before login attempt |
| `auth.login.success` | Action | After successful login |
| `auth.login.failed` | Action | After failed login |
| `auth.logout` | Action | User logged out |
| `auth.password.reset` | Action | Password reset requested |

## Payment

| Hook | Type | Description |
|------|------|-------------|
| `payment.transaction.created` | Action | New transaction created |
| `payment.transaction.completed` | Action | Payment completed |
| `payment.transaction.failed` | Action | Payment failed |
| `payment.transaction.cancelled` | Action | Payment cancelled |
| `payment.transaction.expired` | Action | Payment expired |
| `payment.transaction.refunded` | Action | Refund processed |
| `payment.gateway.before` | Filter | Before gateway redirect |
| `payment.gateway.after` | Action | After gateway return |
| `payment.manual.verified` | Action | Manual payment verified |

## Checkout

| Hook | Type | Description |
|------|------|-------------|
| `checkout.data` | Filter | Modify checkout template data |
| `checkout.template` | Filter | Override checkout template path |
| `checkout.status.template` | Filter | Override status template path |
| `checkout.head` | Action | Inject into checkout `<head>` |
| `checkout.footer` | Action | Inject into checkout footer |
| `checkout.gateways` | Filter | Modify available gateways |

## Invoice

| Hook | Type | Description |
|------|------|-------------|
| `invoice.created` | Action | Invoice created |
| `invoice.paid` | Action | Invoice paid |
| `invoice.overdue` | Action | Invoice overdue |

## Communication

| Hook | Type | Description |
|------|------|-------------|
| `sms.send` | Action | Send SMS |
| `sms.before_send` | Filter | Modify SMS before sending |
| `sms.after_send` | Action | After SMS sent |
| `sms.template.render` | Filter | Render SMS template |
| `mail.send` | Action | Send email |
| `mail.before_send` | Filter | Modify email before sending |
| `mail.after_send` | Action | After email sent |

## Admin

| Hook | Type | Description |
|------|------|-------------|
| `admin.dashboard.widgets` | Filter | Register dashboard widgets |
| `admin.menu` | Filter | Modify admin menu items |
| `admin.settings.save` | Action | Settings saved |

## Webhook / IPN (Unified Dynamic Architecture)

> **Architecture Rule:** ONE endpoint `POST /webhook/{gateway}` — zero core modification for new gateways.

### Inbound (Gateway → Own Pay)

| Hook | Type | Description |
|------|------|-------------|
| `webhook.incoming.{gateway}` | Action | Fired when `POST /webhook/{gateway}` received. Plugin handles verification + processing. `{gateway}` is dynamic — e.g., `webhook.incoming.stripe`, `webhook.incoming.upay` |

**How gateway plugins register:**
```php
// In your Plugin.php — this single line enables POST /webhook/upay
$events->addAction('webhook.incoming.upay', [$this, 'handleWebhook']);
```

The `WebhookPayload` value object is passed as argument:
```php
public function handleWebhook(WebhookPayload $payload): void
{
    $payload->gateway;      // "upay"
    $payload->merchantId;   // Resolved from domain or transaction
    $payload->rawBody;      // Raw body for HMAC verification
    $payload->header('X-Signature');  // Case-insensitive header access
    $payload->json();       // Parsed JSON body
    $payload->formData();   // Parsed form-urlencoded body
    $payload->ip;           // Remote IP for whitelist checks
}
```

### Outbound (Own Pay → Merchant Website)

| Hook | Type | Description |
|------|------|-------------|
| `webhook.delivery.success` | Action | Outbound webhook delivered successfully |
| `webhook.delivery.failed` | Action | Outbound webhook failed after 3 retry attempts |

Outbound webhooks fire automatically on ALL `payment.transaction.*` status changes — no manual registration needed. Works for api, manual, and bank gateway types.

**Outbound payload (HMAC-SHA256 signed):**
```json
{
    "event": "payment.completed",
    "transaction_id": "TXN-...",
    "amount": "500.00",
    "currency": "BDT",
    "gateway": "bkash",
    "gateway_type": "manual",
    "status": "completed",
    "customer": { "name": "", "email": "", "phone": "" },
    "metadata": {},
    "timestamp": "2026-04-30T..."
}
```

**Verification headers sent to merchant:**
- `X-OwnPay-Signature` — HMAC-SHA256 of JSON body using merchant's webhook_secret
- `X-OwnPay-Timestamp` — Unix timestamp

## Domain

| Hook | Type | Description |
|------|------|-------------|
| `domain.mapped` | Action | Custom domain mapped to merchant |
| `domain.verified` | Action | DNS verification passed |
| `domain.removed` | Action | Custom domain removed |
| `domain.resolve` | Filter | Modify domain resolution logic |

## Plugin

| Hook | Type | Description |
|------|------|-------------|
| `plugin.activated` | Action | Plugin activated |
| `plugin.deactivated` | Action | Plugin deactivated |
| `plugin.installed` | Action | Plugin installed |
| `plugin.uninstalled` | Action | Plugin uninstalled |

## Landing Page

| Hook | Type | Description |
|------|------|-------------|
| `landing.features` | Filter | Modify landing page features |
| `landing.data` | Filter | Modify landing page data |

