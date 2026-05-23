# OwnPay — Complete Hooks, Filters, & Capabilities Reference

This is the comprehensive, production-accurate developer index mapping all hooks, filters, domains, capabilities, and system lifecycles in OwnPay.

---

## 1. System Capabilities Reference

In OwnPay, plugins must declare their capabilities inside their `manifest.json` file. The core engine registers, scopes, and sandboxes plugins using the **`OwnPay\Plugin\Capability`** backed enum:

| Capability Enum Value | Code Case | Description & Security Scope | Required Permissions |
| :--- | :--- | :--- | :--- |
| `gateway` | `Capability::GATEWAY` | Payment gateway processor adapters. | `gateway.process`, `gateway.config` |
| `theme` | `Capability::THEME` | Checkout visual theme customization skins. | `theme.render` |
| `addon` | `Capability::ADDON` | General application platform extensions. | None (base access) |
| `communication` | `Capability::COMMUNICATION` | SMS, email, or chat delivery providers. | `comm.send` |
| `analytics` | `Capability::ANALYTICS` | Financial reporting and visual analytics widgets. | `analytics.read` |
| `webhook` | `Capability::WEBHOOK` | Custom inbound IPN callback handlers. | None |
| `notification` | `Capability::NOTIFICATION` | Admin notification panel integrations. | None |
| `export` | `Capability::EXPORT` | Export formats and data formatting grids. | None |
| `authentication` | `Capability::AUTHENTICATION` | Custom OAuth and Single Sign-On (SSO) layers. | None |
| `storage` | `Capability::STORAGE` | Outbound external storage bucket engines. | `storage.read`, `storage.write` |
| `cron` | `Capability::CRON` | Background automation cron jobs. | None |
| `dashboard` | `Capability::DASHBOARD` | Custom admin home widgets components. | None |
| `db_read` | `Capability::DB_READ` | Read access to system data tables. | None |
| `db_write` | `Capability::DB_WRITE` | Write access to system data tables. | None |
| `file_read` | `Capability::FILE_READ` | Read access to local filesystem paths. | None |
| `file_write` | `Capability::FILE_WRITE` | Write access to local filesystem paths. | None |
| `http_outbound` | `Capability::HTTP_OUTBOUND` | Outbound network connection access. | None |
| `hooks` | `Capability::HOOKS` | Registering action/filter event hook managers. | None |
| `checkout_ui` | `Capability::CHECKOUT_UI` | Modifying/injecting checkout front-end layouts. | None |

---

## 2. Core Framework & Lifecycle Hooks

### Actions

| Hook Name | Fired From | Parameter Signature | Description |
| :--- | :--- | :--- | :--- |
| `system.boot` | `Kernel::boot()` | None | Fired during early application bootstrap. |
| `system.shutdown` | `Kernel::terminate()` | None | Fired on graceful request shut down. |
| `plugin.before_install` | `PluginManager::install()` | `string $zipPath` | Fired before a plugin package is decompressed. |
| `plugin.installed` | `PluginManager::install()` | `string $slug, array $manifest` | Fired after successful installation. |
| `plugin.before_activate` | `PluginManager::activate()` | `string $slug, int $brandId` | Fired before plugin activation scripts run. |
| `plugin.activated` | `PluginManager::activate()` | `string $slug, int $ran, int $brandId` | Fired when activation completes successfully. |
| `plugin.before_deactivate` | `PluginManager::deactivate()`| `string $slug, int $brandId` | Fired before a plugin is suspended. |
| `plugin.deactivated` | `PluginManager::deactivate()`| `string $slug, int $brandId` | Fired after suspension completes. |
| `plugin.before_uninstall` | `PluginManager::uninstall()` | `string $slug` | Fired before destructive deletion starts. |
| `plugin.uninstalled` | `PluginManager::uninstall()` | `string $slug` | Fired after all settings are completely purged. |
| `mobile.device.paired` | `DevicePairingService` | `string $deviceUuid, int $mid, int $userId` | Fired when a mobile app pairs with a brand. |
| `mobile.device.revoked` | `DevicePairingService` | `string $deviceUuid, int $mid` | Fired when a paired device token is revoked. |

### Filters

| Hook Name | Fired From | Parameter Signature | Expected Return Type | Description |
| :--- | :--- | :--- | :--- | :--- |
| `system.request` | `Kernel::handle()` | `Request $request` | `Request` | Incepts HTTP requests on entry. |
| `system.response` | `Kernel::handle()` | `Response $response, Request $request` | `Response` | Filters final response payloads. |
| `system.middleware_pipeline` | `Kernel::boot()` | `array $middleware` | `array` | Filters global middleware layers. |
| `db.query.before` | `Database::query()` | `array $queryData` | `array` | Filters SQL statement parameters before PDO bindings execute. |

---

## 3. Authentication & Security

### Actions

| Hook Name | Fired From | Parameter Signature | Description |
| :--- | :--- | :--- | :--- |
| `auth.login.success` | `Authenticator::verify()` | `array $user, string $ip` | Fired when user logs in successfully. |
| `auth.login.failed` | `Authenticator::verify()` | `string $email, string $ip` | Fired on login mismatch or locked state. |
| `auth.logout` | `AuthSessionService::clear()` | `int $userId` | Fired when user session is terminated. |

### Filters

| Hook Name | Fired From | Parameter Signature | Expected Return Type | Description |
| :--- | :--- | :--- | :--- | :--- |
| `auth.login.before` | `AuthSessionService` | `bool $allowed, string $email, string $ip` | `bool` | Filters access parameters before credentials validation. |

---

## 4. Payment Processing Engine

### Actions

| Hook Name | Fired From | Parameter Signature | Description |
| :--- | :--- | :--- | :--- |
| `payment.transaction.created` | `TransactionService` | `array $transaction` | Fired when a transaction is entered in DB. |
| `payment.transaction.completed` | `TransactionService` | `array $transaction` | Fired when a transaction resolves successfully. |
| `payment.transaction.failed` | `TransactionService` | `array $transaction` | Fired on transaction decline or timeout. |
| `payment.transaction.cancelled` | `TransactionService` | `array $transaction` | Fired when user cancels transaction. |
| `payment.intent.created` | `PaymentService` | `array $intent` | Fired when a checkout intent is generated. |
| `payment.intent.expired` | `PaymentService` | `array $intent` | Fired when checkout intent times out. |
| `ledger.entry.created` | `LedgerService` | `array $entry` | Fired when a ledger booking entry registers. |
| `dispute.opened` | `DisputeService` | `array $dispute` | Fired when a chargeback dispute registers. |
| `dispute.resolved` | `DisputeService` | `array $dispute` | Fired when dispute outcome resolves. |

### Filters

| Hook Name | Fired From | Parameter Signature | Expected Return Type | Description |
| :--- | :--- | :--- | :--- | :--- |
| `payment.transaction.before_create` | `TransactionService` | `array $data, int $merchantId` | `array` | Filters columns prior to DB insertion. |
| `payment.amount.calculate` | `PaymentService` | `string $amount, array $context` | `string` | Filters currency decimals conversion. |
| `payment.fee.calculate` | `FeeService` | `string $fee, array $context` | `string` | Filters dynamic merchant settlement fees. |
| `gateway.capture.before` | `GatewayBridge` | `array $params, string $slug, int $mid` | `array` | Filters transaction parameters sent to payment adapters. |

---

## 5. Checkout User Interface

### Actions

| Hook Name | Fired From | Parameter Signature | Description |
| :--- | :--- | :--- | :--- |
| `checkout.before` | `CheckoutController` | `array $txn` | Fired before checkout layout begins parsing. |
| `checkout.head` | `Base checkout page template` | None | Action anchor inside `<head>` to inject custom CSS links. |
| `checkout.footer` | `Base checkout page template` | None | Action anchor in footer to inject JS scripts. |
| `checkout.gateway.selected` | `CheckoutController::pay()` | `array $txn, string $gateway` | Fired when gateway method is chosen. |
| `checkout.manual_verify.submitted`| `CheckoutController` | `array $txn, array $proof` | Fired when manual receipt proof is submitted. |

### Filters

| Hook Name | Fired From | Parameter Signature | Expected Return Type | Description |
| :--- | :--- | :--- | :--- | :--- |
| `checkout.render` | `CheckoutController` | `array $data` | `array` | Filters Twig view context variables. |
| `checkout.intent.render` | `PaymentIntentCheckout` | `array $data` | `array` | Filters PaymentIntent context variables. |
| `checkout.template` | `CheckoutController` | `string $templatePath` | `string` | Filters layout templates resolving path (PHP or Twig). |
| `checkout.status.template` | `CheckoutController` | `string $templatePath` | `string` | Filters layout transaction processing results view page path. |
| `checkout.payment_link.template` | `PaymentLinkCheckout` | `string $templatePath` | `string` | Filters link direct amount query view page path. |
| `checkout.csp.sources` | `SecurityHeadersMiddleware`| `array $sources` | `array` | Filters whitelist Content Security Policy domains. |

---

## 6. Manual Payment Gateways

### Filters

| Hook Name | Fired From | Parameter Signature | Expected Return Type | Description |
| :--- | :--- | :--- | :--- | :--- |
| `gateway.manual.render` | `ManualGatewayService` | `string $html, array $gateway` | `string` | Filters custom manual payment HTML markup. |
| `gateway.manual.verify` | `ManualGatewayService` | `array $result, array $gateway, array $data` | `array` | Filters client manual slip validations. |

---

## 7. Communications & Messaging

### Actions

| Hook Name | Fired From | Parameter Signature | Description |
| :--- | :--- | :--- | :--- |
| `communication.sms.send` | `CommunicationService` | `int $mid, string $to, array $result` | Fired after outbound SMS delivery action finishes. |
| `communication.mail.send` | `CommunicationService` | `int $mid, array $message, array $result`| Fired after outbound Email delivery action finishes. |

### Filters

| Hook Name | Fired From | Parameter Signature | Expected Return Type | Description |
| :--- | :--- | :--- | :--- | :--- |
| `communication.channels` | `CommunicationService` | `array $channels` | `array` | Filters list of system notification channels. |
| `communication.template.render` | `CommunicationService` | `string $html, array $vars` | `string` | Filters SMS/Email raw messaging layout compilation. |
| `mfs.templates` | `SmsParserService` | `array $templates` | `array` | Filters regular expression reconciliations for MFS SMS matching. |

---

## 8. Administration & Layouts

### Actions

| Hook Name | Fired From | Parameter Signature | Description |
| :--- | :--- | :--- | :--- |
| `audit.log.created` | `AuditLogger` | `array $entry` | Fired when audit action persists inside DB. |

### Filters

| Hook Name | Fired From | Parameter Signature | Expected Return Type | Description |
| :--- | :--- | :--- | :--- | :--- |
| `admin.page.before_render` | `BaseController` | `array $data, string $template` | `array` | Filters variables context before admin rendering. |
| `admin.page.after_render` | `BaseController` | `string $html, string $template` | `string` | Filters final rendered admin HTML layouts. |
| `admin.template.resolve` | `AdminPageTrait` | `string $template, array $data` | `string` | Filters theme-customized admin pages overrides. |
| `admin.template.data` | `AdminPageTrait` | `array $data, string $tpl` | `array` | Filters theme-customized view context. |
| `admin.dashboard.stats` | `DashboardController` | `array $stats` | `array` | Filters home statistics widgets numbers. |
| `report.data` | `DashboardController` | `array $report, array $params` | `array` | Filters custom financial grids columns. |
| `export.row` | `DashboardController` | `array $row` | `array` | Filters line-item export formats. |

---

## 9. Dynamic Webhooks & Domain Mappings

### Actions

| Hook Name | Fired From | Parameter Signature | Description |
| :--- | :--- | :--- | :--- |
| `webhook.incoming.{gateway}` | `WebhookController` | `WebhookPayload $payload` | Unified inbound callback IPN receiver actions. `{gateway}` represents the dynamic slug matching the payload. |
| `webhook.delivery.success` | `WebhookDispatcher` | `int $merchantId, array $event` | Fired when outbound merchant IPN completes. |
| `webhook.delivery.failed` | `WebhookDispatcher` | `int $merchantId, array $event` | Fired when outbound IPN delivery reaches retry limits. |
| `domain.mapped` | `DomainService` | `string $domain, int $mid` | Fired when new domain is linked to brand. |
| `domain.verified` | `DomainService` | `string $domain, int $mid` | Fired when domain resolves DNS verification checks. |
| `domain.removed` | `DomainService` | `string $domain, int $mid` | Fired when domain mappings are deleted. |

### Filters

| Hook Name | Fired From | Parameter Signature | Expected Return Type | Description |
| :--- | :--- | :--- | :--- | :--- |
| `domain.resolve` | `DomainMiddleware` | `array $resolved` | `array` | Filters domain mappings context. |

---

## 10. Platform Update Lifecycles

### Actions

| Hook Name | Fired From | Parameter Signature | Description |
| :--- | :--- | :--- | :--- |
| `update.available` | `UpdateService::check()` | `string $version` | Fired when a new registry version is resolved. |
| `update.before` | `UpdateService::execute()` | `string $version` | Fired before a platform update begins execution. |
| `update.after` | `UpdateService::execute()` | `string $version` | Fired when a platform update completes successfully. |
| `update.failed` | `UpdateService::execute()` | `string $version, string $error`| Fired when an execution exception occurs. |
| `update.rollback` | `UpdateService::execute()` | `string $version` | Fired when system rolls back to restoration point. |
