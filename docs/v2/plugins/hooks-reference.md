# OwnPay — Comprehensive Hooks, Filters, & Capabilities Index
## The Sovereign Event Lifecycle and Integration Reference

---

## 1. Event Manager & Decoupled Execution Philosophy

The OwnPay engine is built upon a fully decoupled, event-driven architecture orchestrated by the **`OwnPay\Event\EventManager`** service. 

Action and Filter hooks allow developers to alter core system pathways—such as custom checkout UI skins, automated ledger bookkeeping records, messaging gateways, or outbound merchant IPNs—without introducing hardcoded overrides inside core files.

### Architectural Invariants

1. **Strict Error Isolation**: Every action callback is wrapped within a standalone, structured try-catch execution context. If a third-party plugin hook throws a fatal runtime exception (e.g. database network loss, API curl timeout), the `EventManager` logs the traceback detail via `Logger::error()` and gracefully proceeds to the next callback in queue. A plugin failure will **never** crash a critical transaction flow or disrupt double-entry bookkeeping pipelines.
2. **Deterministic Priority Chains**: Hooks are executed in ascending numerical priority (e.g., priority `5` executes before `10`). If multiple plugins register callbacks on the same hook at matching priorities, they execute in order of registration.
3. **Execution Context Identification**: The `EventManager` maintains an internal execution context stack (`ownerStack`). Calling `getActiveOwner()` dynamically resolves whether the current code path is executing within the `'core'` base system or is delegated to a specific plugin slug (e.g., `'bkash-api'`).
4. **Scoped Execution Filters**: To prevent data leaks and maintain tenant isolation boundaries under the **Sovereign Single-Owner, Multi-Brand model**, filter hooks respect active `merchant_id` scopes. For example, during dashboard statistics compilation, the system resolves and loads the active brand ID to restrict query visibility within designated scopes.

---

## 2. System Capabilities & RBAC Policies

Every plugin must declare its operational capability profile within `manifest.json`. The platform uses the **`OwnPay\Plugin\Capability`** backed enum to sandbox and restrict plugin access:

| Capability Enum Case | JSON String Key | Required Permission Keys | Security and Sandboxing Boundary |
| :--- | :--- | :--- | :--- |
| `Capability::GATEWAY` | `"gateway"` | `gateway.process`, `gateway.config` | Authorized to hook into payment processing interfaces, execute outbound curls, and handle secure IPN webhooks. |
| `Capability::THEME` | `"theme"` | `theme.render` | Granted privileges to override CSS stylesheets, inject DOM structures, and render custom visual checkout skins. |
| `Capability::ADDON` | `"addon"` | *None (Base Platform Access)* | Generic extension capability enabling standard administrative tasks. |
| `Capability::COMMUNICATION` | `"communication"` | `comm.send` | Authorized to connect to third-party SMS, Email, or chat delivery microservices. |
| `Capability::ANALYTICS` | `"analytics"` | `analytics.read` | Granted read privileges to transactional datasets to render administration dashboards. |
| `Capability::WEBHOOK` | `"webhook"` | *None (Dynamic Routing)* | Authorized to register endpoints under `/webhook/{slug}` for automated backchannel calls. |
| `Capability::NOTIFICATION` | `"notification"` | *None* | Granted authority to post visual alerts to administrative control centers. |
| `Capability::EXPORT` | `"export"` | *None* | Privileged to process reporting datasets into CSV, PDF, or Excel sheets. |
| `Capability::AUTHENTICATION` | `"authentication"` | *None* | Authorized to implement OAuth or SSO directory layers. |
| `Capability::STORAGE` | `"storage"` | `storage.read`, `storage.write` | Privileged to run filesystems access inside external block storage structures. |
| `Capability::CRON` | `"cron"` | *None* | Granted authority to register automated intervals inside core system scheduler loops. |
| `Capability::DASHBOARD` | `"dashboard"` | *None* | Authorized to inject HTML/JS panels and summary figures into home screens. |
| `Capability::DB_READ` | `"db_read"` | *None* | Granted read-only permission to register table queries under the sandboxed `op_plugin_*` structures. |
| `Capability::DB_WRITE` | `"db_write"` | *None* | Granted write permission to persist entries inside sandboxed custom `op_plugin_*` tables. |
| `Capability::FILE_READ` | `"file_read"` | *None* | Sandboxed read capability restricted strictly to the plugin's folder and logs space. |
| `Capability::FILE_WRITE` | `"file_write"` | *None* | Sandboxed write capability confined inside the local plugin `/storage` folder. |
| `Capability::HTTP_OUTBOUND` | `"http_outbound"` | *None* | Grants execution of backchannel CURL parameters to query remote checkout servers. |
| `Capability::HOOKS` | `"hooks"` | *None* | Authorized to subscribe to system Event Manager hooks. |
| `Capability::CHECKOUT_UI` | `"checkout_ui"` | *None* | Permitted to filter visual twigs and variables within the transaction page environment. |

---

## 3. Core Platform & Application Lifecycle Hooks

### Action Hooks

#### `system.boot`
* **Triggered From**: `OwnPay\Kernel::boot()`
* **Parameters**: None
* **Description**: Dispatched during early application bootstrap. Fired on every HTTP and CLI request after the dependency injection container boots, but before middleware execution starts. Use to load dynamic hooks or run light system configuration overrides.

#### `system.shutdown`
* **Triggered From**: `OwnPay\Kernel::terminate()`
* **Parameters**: None
* **Description**: Dispatched on graceful application shutdown. Fired after the response payload is transmitted to the client, preceding script exit. Essential to close persistent DB cursors, flush caches, or compile runtime performance traces.

#### `plugin.before_install`
* **Triggered From**: `OwnPay\Plugin\PluginManager::install()`
* **Parameters**: `string $zipPath` (Absolute file path to the uploaded module ZIP archive)
* **Description**: Fired before a new plugin package is extracted. Use to inspect ZIP hashes or run pre-extraction verification routines.

#### `plugin.installed`
* **Triggered From**: `OwnPay\Plugin\PluginManager::install()`
* **Parameters**: `string $slug`, `array $manifest` (The parsed `manifest.json` property array)
* **Description**: Fired after successful file extraction and validation checks. Use to copy asset folders or run startup logs.

#### `plugin.before_activate`
* **Triggered From**: `OwnPay\Plugin\PluginManager::activate()`
* **Parameters**: `string $slug`, `int $brandId` (The active brand context activating the plugin)
* **Description**: Fired before database migrations or startup activation scripts run.

#### `plugin.activated`
* **Triggered From**: `OwnPay\Plugin\PluginManager::activate()`
* **Parameters**: `string $slug`, `int $migrationsCount`, `int $brandId` (The active brand context)
* **Description**: Fired when activation completes successfully. Use to register default options or trigger external activation API checks.

#### `plugin.before_deactivate`
* **Triggered From**: `OwnPay\Plugin\PluginManager::deactivate()`
* **Parameters**: `string $slug`, `int $brandId`
* **Description**: Fired before the plugin is suspended.

#### `plugin.deactivated`
* **Triggered From**: `OwnPay\Plugin\PluginManager::deactivate()`
* **Parameters**: `string $slug`, `int $brandId`
* **Description**: Fired after the plugin is marked inactive. Use to flush cached hooks. Do NOT drop database tables here.

#### `plugin.before_uninstall`
* **Triggered From**: `OwnPay\Plugin\PluginManager::uninstall()`
* **Parameters**: `string $slug`
* **Description**: Fired before structural deletion of the plugin starts.

#### `plugin.uninstalled`
* **Triggered From**: `OwnPay\Plugin\PluginManager::uninstall()`
* **Parameters**: `string $slug`
* **Description**: Fired after plugin files are deleted and settings are permanently purged from the system. Use to clean up external credentials or log final deletion events.

#### `mobile.device.paired`
* **Triggered From**: `OwnPay\Service\Device\DevicePairingService::pair()`
* **Parameters**: `string $deviceUuid`, `int $merchantId`, `int $userId`
* **Description**: Fired when a mobile app registers and pairs with a brand context.

#### `mobile.device.revoked`
* **Triggered From**: `OwnPay\Service\Device\DevicePairingService::revoke()`
* **Parameters**: `string $deviceUuid`, `int $merchantId`
* **Description**: Fired when a paired mobile app's API keys or push access are revoked.

---

### Filter Hooks

#### `system.request`
* **Triggered From**: `OwnPay\Kernel::handle()`
* **Parameters**: `\OwnPay\Http\Request $request`
* **Expected Return**: `\OwnPay\Http\Request`
* **Description**: Filters incepted HTTP request objects on entry. Allows plugins to inspect or rewrite path parameters, rewrite headers, or enforce rate-limiting.

#### `system.response`
* **Triggered From**: `OwnPay\Kernel::handle()`
* **Parameters**: `\OwnPay\Http\Response $response`, `\OwnPay\Http\Request $request`
* **Expected Return**: `\OwnPay\Http\Response`
* **Description**: Filters final response payloads. Allows plugins to inject security headers or modify JSON bodies before output.

#### `system.middleware_pipeline`
* **Triggered From**: `OwnPay\Kernel::boot()`
* **Parameters**: `array $middleware` (List of global middleware class strings)
* **Expected Return**: `array`
* **Description**: Filters global middleware layers, enabling the injection of custom route firewalls or audit trackers.

#### `db.query.before`
* **Triggered From**: `OwnPay\Database\Database::query()`
* **Parameters**: `array $queryData` (Array containing `['sql' => string, 'params' => array]`)
* **Expected Return**: `array`
* **Description**: Filters SQL statements before PDO execution. **Security Sandbox Control**: Handled query scripts are scanned by `PluginSandbox::validateSql()` to block direct system table access.

---

## 4. Authentication, Security & RBAC Hooks

### Action Hooks

#### `auth.login.success`
* **Triggered From**: `OwnPay\Security\Authenticator::verify()` and `OwnPay\Service\Auth\AuthSessionService::login()`
* **Parameters**: `array $user` (User account columns array), `string $ip`
* **Description**: Fired when administrative or staff users log in successfully. Use to dispatch notifications or reset brute-force counters.

#### `auth.login.failed`
* **Triggered From**: `OwnPay\Security\Authenticator::verify()` and `OwnPay\Service\Auth\AuthSessionService::login()`
* **Parameters**: `string $email`, `string $ip`
* **Description**: Fired on credential mismatch. Essential to trigger account lockouts or security logs.

#### `auth.logout`
* **Triggered From**: `OwnPay\Service\Auth\AuthSessionService::clear()`
* **Parameters**: `int $userId`
* **Description**: Fired when user sessions are terminated.

---

### Filter Hooks

#### `auth.login.before`
* **Triggered From**: `OwnPay\Service\Auth\AuthSessionService::verifyAttempt()`
* **Parameters**: `bool $allowed`, `string $email`, `string $ip`
* **Expected Return**: `bool`
* **Description**: Filters access parameters before credentials validation. Return `false` to block verification (e.g. on blocked IP ranges).

---

## 5. Payment Engine & Ledger Bookkeeping Hooks

### Action Hooks

#### `payment.transaction.created`
* **Triggered From**: `OwnPay\Service\Payment\TransactionService::create()`
* **Parameters**: `array $transaction` (Database columns representing the new transaction)
* **Description**: Fired when a transaction is entered in the database with status `pending`. Use to trigger logging or dispatch early order holds.

#### `payment.transaction.completed`
* **Triggered From**: `OwnPay\Service\Payment\TransactionService::markCompleted()`
* **Parameters**: `array $transaction`
* **Description**: Fired when a transaction resolves successfully. Essential to release order holds and compile double-entry ledger bookkeeping.

#### `payment.transaction.failed`
* **Triggered From**: `OwnPay\Service\Payment\TransactionService::markFailed()`
* **Parameters**: `array $transaction`
* **Description**: Fired on transaction decline or timeout.

#### `payment.transaction.cancelled`
* **Triggered From**: `OwnPay\Service\Payment\TransactionService::markCancelled()`
* **Parameters**: `array $transaction`
* **Description**: Fired when the buyer cancels the checkout process.

#### `payment.intent.created`
* **Triggered From**: `OwnPay\Service\Payment\PaymentService::createIntent()`
* **Parameters**: `array $intent`
* **Description**: Fired when a checkout intent session is generated.

#### `payment.intent.expired`
* **Triggered From**: `OwnPay\Service\Payment\PaymentService::expireIntents()`
* **Parameters**: `array $intent` (or array with `['count' => int, 'batch' => true]` when processing in batches)
* **Description**: Fired when checkout intents time out without completion.

#### `ledger.entry.created`
* **Triggered From**: `OwnPay\Service\Payment\LedgerService::postEntry()`
* **Parameters**: `array $entry` (Database columns representing the new double-entry ledger entry)
* **Description**: Fired when a balanced double-entry booking entry is committed.

#### `dispute.opened`
* **Triggered From**: `OwnPay\Service\Payment\DisputeService::open()`
* **Parameters**: `array $dispute`
* **Description**: Fired when a chargeback dispute registers from a processor webhook. Use to place merchant balances on hold.

#### `dispute.resolved`
* **Triggered From**: `OwnPay\Service\Payment\DisputeService::resolve()`
* **Parameters**: `array $dispute` (Array containing `['id' => int, 'status' => string, 'resolution' => string]`)
* **Description**: Fired when dispute outcome resolves. Use to release or debit funds in the ledger.

---

### Filter Hooks

#### `payment.transaction.before_create`
* **Triggered From**: `OwnPay\Service\Payment\TransactionService::create()`
* **Parameters**: `array $data`, `int $merchantId`
* **Expected Return**: `array`
* **Description**: Filters columns prior to database insertion. Use to inject custom metadata parameters.

#### `payment.amount.calculate`
* **Triggered From**: `OwnPay\Service\Payment\PaymentService::calculate()`
* **Parameters**: `string $amount`, `array $context` (Currency and brand details)
* **Expected Return**: `string`
* **Description**: Filters currency decimals and rounding conversions before processor payload compilation.

#### `payment.fee.calculate`
* **Triggered From**: `OwnPay\Service\Payment\FeeService::calculate()`
* **Parameters**: `string $fee`, `array $context`
* **Expected Return**: `string`
* **Description**: Filters dynamic merchant settlement fees. Allows plugins to apply custom pricing tiers based on transaction size.

#### `gateway.capture.before`
* **Triggered From**: `OwnPay\Gateway\GatewayBridge::capture()`
* **Parameters**: `array $params`, `string $slug`, `int $merchantId`
* **Expected Return**: `array`
* **Description**: Filters transaction parameters sent to payment adapters.

---

## 6. Front-end Checkout & Theme UI Hooks

### Action Hooks

#### `checkout.before`
* **Triggered From**: `OwnPay\Controller\CheckoutController::index()`
* **Parameters**: `array $transaction`
* **Description**: Fired before the checkout visual layout is parsed.

#### `checkout.head`
* **Triggered From**: Base checkout twig page templates
* **Parameters**: None
* **Description**: Anchor tag situated inside the visual HTML `<head>` tag. Use to inject stylesheet link elements or custom fonts.

#### `checkout.footer`
* **Triggered From**: Base checkout twig page templates
* **Parameters**: None
* **Description**: Anchor tag situated in the HTML footer space. Use to inject custom JavaScript scripts or tracking pixels.

#### `checkout.gateway.selected`
* **Triggered From**: `OwnPay\Controller\CheckoutController::pay()`
* **Parameters**: `array $transaction`, `string $gateway`
* **Description**: Fired when the buyer selects a payment gateway.

#### `checkout.manual_verify.submitted`
* **Triggered From**: `OwnPay\Controller\CheckoutController::submitManualProof()`
* **Parameters**: `array $transaction`, `array $proof` (Slip details and image URLs)
* **Description**: Fired when a manual receipt slip is submitted by the buyer for manual verification.

---

### Filter Hooks

#### `checkout.render`
* **Triggered From**: `OwnPay\Controller\CheckoutController::index()`
* **Parameters**: `array $data` (Twig template context variables)
* **Expected Return**: `array`
* **Description**: Filters Twig view context variables before layout parsing. Use to inject custom brand settings.

#### `checkout.intent.render`
* **Triggered From**: `OwnPay\Controller\PaymentIntentCheckout::index()`
* **Parameters**: `array $data`
* **Expected Return**: `array`
* **Description**: Filters variables context before payment intent screen generation.

#### `checkout.template`
* **Triggered From**: `OwnPay\Controller\CheckoutController::index()`
* **Parameters**: `string $templatePath` (Relative file path to target template)
* **Expected Return**: `string`
* **Description**: Filters layout templates resolving path, enabling themes to override the standard visual checkout design.

#### `checkout.status.template`
* **Triggered From**: `OwnPay\Controller\CheckoutController::status()`
* **Parameters**: `string $templatePath`
* **Expected Return**: `string`
* **Description**: Filters layout transaction processing results view page path (Success / Decline screen templates).

#### `checkout.payment_link.template`
* **Triggered From**: `OwnPay\Controller\PaymentLinkCheckout::index()`
* **Parameters**: `string $templatePath`
* **Expected Return**: `string`
* **Description**: Filters link direct amount query view page path.

#### `checkout.csp.sources`
* **Triggered From**: `OwnPay\Middleware\SecurityHeadersMiddleware`
* **Parameters**: `array $sources` (Whitelist domains mapped by directive e.g. `connect-src`)
* **Expected Return**: `array`
* **Description**: Filters content security policies, allowing gateways to append callback server domains to prevent browser console blocks.

---

## 7. Manual Payment Slip Verification Hooks

### Filter Hooks

#### `gateway.manual.render`
* **Triggered From**: `OwnPay\Service\Payment\ManualGatewayService::render()`
* **Parameters**: `string $html`, `array $gateway`
* **Expected Return**: `string`
* **Description**: Filters custom manual payment HTML markup, letting addons dynamically inject input boxes or validation instructions.

#### `gateway.manual.verify`
* **Triggered From**: `OwnPay\Service\Payment\ManualGatewayService::verify()`
* **Parameters**: `array $result`, `array $gateway`, `array $submittedData`
* **Expected Return**: `array`
* **Description**: Filters manual slip validations. Use to run custom checks against receipt formats.

---

## 8. Communication & Messaging Channels

### Action Hooks

#### `communication.sms.send`
* **Triggered From**: `OwnPay\Service\Communication\CommunicationService::sendSms()` and `OwnPay\Service\Notification\NotificationService::send()`
* **Parameters**: `int $merchantId`, `string $recipientNumber`, `array $deliveryResult`
* **Description**: Fired after outbound SMS delivery action finishes.

#### `communication.mail.send`
* **Triggered From**: `OwnPay\Service\Communication\CommunicationService::sendMail()` and `OwnPay\Service\Notification\NotificationService::send()`
* **Parameters**: `int $merchantId`, `array $messagePayload`, `array $deliveryResult`
* **Description**: Fired after outbound Email delivery action finishes.

---

### Filter Hooks

#### `communication.channels`
* **Triggered From**: `OwnPay\Service\Communication\CommunicationService::getChannels()`
* **Parameters**: `array $channels`
* **Expected Return**: `array`
* **Description**: Filters list of system notification channels, allowing plugins to add Telegram or Slack callback integrations.

#### `communication.template.render`
* **Triggered From**: `OwnPay\Service\Communication\CommunicationService::compileTemplate()`
* **Parameters**: `string $html`, `array $variables`
* **Expected Return**: `string`
* **Description**: Filters SMS/Email raw messaging layout compilation.

#### `mfs.templates`
* **Triggered From**: `OwnPay\Service\Communication\SmsParserService::getTemplates()`
* **Parameters**: `array $templates` (Regex schemas matching SMS templates)
* **Expected Return**: `array`
* **Description**: Filters regular expression reconciliations for MFS SMS matching, allowing addons to register new SMS match patterns.

---

## 9. Admin Panel Operations & Report Grids

### Action Hooks

#### `audit.log.created`
* **Triggered From**: `OwnPay\Service\System\AuditLogger::log()`
* **Parameters**: `array $entry` (Database columns representing the log entry)
* **Description**: Fired when a new admin audit action is written to the database.

---

### Filter Hooks

#### `admin.page.before_render`
* **Triggered From**: `OwnPay\Controller\BaseController::render()`
* **Parameters**: `array $data` (Context variables), `string $templatePath`
* **Expected Return**: `array`
* **Description**: Filters variables context before admin dashboard rendering.

#### `admin.page.after_render`
* **Triggered From**: `OwnPay\Controller\BaseController::render()`
* **Parameters**: `string $html`, `string $templatePath`
* **Expected Return**: `string`
* **Description**: Filters final rendered admin HTML layouts before transmission.

#### `admin.template.resolve`
* **Triggered From**: `OwnPay\Traits\AdminPageTrait::resolve()`
* **Parameters**: `string $template`, `array $data`
* **Expected Return**: `string`
* **Description**: Filters theme-customized admin pages overrides, letting themes intercept backend screens.

#### `admin.template.data`
* **Triggered From**: `OwnPay\Traits\AdminPageTrait::resolveData()`
* **Parameters**: `array $data`, `string $template`
* **Expected Return**: `array`
* **Description**: Filters theme-customized view context variables.

#### `admin.dashboard.stats`
* **Triggered From**: `OwnPay\Controller\DashboardController::index()`
* **Parameters**: `array $stats`
* **Expected Return**: `array`
* **Description**: Filters dashboard statistics panels, letting analytics addons inject customized reporting blocks (e.g. conversion rates).

#### `report.data`
* **Triggered From**: `OwnPay\Controller\DashboardController::getReport()`
* **Parameters**: `array $reportData`, `array $queryParams`
* **Expected Return**: `array`
* **Description**: Filters custom financial grids and records lists prior to rendering.

#### `export.row`
* **Triggered From**: `OwnPay\Controller\DashboardController::export()`
* **Parameters**: `array $row` (An array representing a CSV line-item)
* **Expected Return**: `array`
* **Description**: Filters line-item export formats. Use to scrub sensitive customer details before export.

---

## 10. Dynamic Webhook Observers & Mapped Domain Events

### Action Hooks

#### `webhook.incoming.{gateway}`
* **Triggered From**: `OwnPay\Controller\WebhookController::handle()`
* **Parameters**: `\OwnPay\Model\WebhookPayload $payload`
* **Description**: Unified inbound callback IPN receiver actions. `{gateway}` represents the dynamic slug matching the payload. Refer to the Developer Guide for verification practices.

#### `webhook.delivery.success`
* **Triggered From**: `OwnPay\Service\Notification\WebhookDispatcher::send()` and `OwnPay\Service\Payment\WebhookService::dispatch()`
* **Parameters**: `int $merchantId` (or `array $webhook` when processed by WebhookService), `array $event` (or `int $httpCode` when processed by WebhookService)
* **Description**: Fired when outbound merchant IPN completes successfully.

#### `webhook.delivery.failed`
* **Triggered From**: `OwnPay\Service\Notification\WebhookDispatcher::send()` and `OwnPay\Service\Payment\WebhookService::dispatch()`
* **Parameters**: `int $merchantId` (or `array $webhook`), `array $event` (or `string $errorMessage`)
* **Description**: Fired when outbound IPN delivery reaches retry limits or encounters connection errors.

#### `domain.mapped`
* **Triggered From**: `OwnPay\Service\Domain\DomainService::mapDomain()`
* **Parameters**: `string $domain`, `int $merchantId`
* **Description**: Fired when a new white-label custom domain is linked to a brand context.

#### `domain.verified`
* **Triggered From**: `OwnPay\Service\Domain\DomainService::verifyDomain()`
* **Parameters**: `string $domainName`, `int $merchantId`
* **Description**: Fired when a custom domain successfully passes DNS verification checks.

#### `domain.removed`
* **Triggered From**: `OwnPay\Service\Domain\DomainService::removeDomain()`
* **Parameters**: `string $domainName`, `int $merchantId`
* **Description**: Fired when a custom domain mapping is deleted.

---

### Filter Hooks

#### `domain.resolve`
* **Triggered From**: `OwnPay\Middleware\DomainMiddleware::resolve()`
* **Parameters**: `array $resolvedContext` (Details of the resolved host, brand, and status)
* **Expected Return**: `array`
* **Description**: Filters resolved domain mapping details. Use to intercept and customize white-label routing logic.

---

## 11. Auto-Updater Lifecycle Hooks

### Action Hooks

#### `update.available`
* **Triggered From**: `OwnPay\Service\Update\UpdateService::check()`
* **Parameters**: `string $latestVersion`
* **Description**: Fired when a newer release of the OwnPay core platform is detected in remote repositories.

#### `update.before`
* **Triggered From**: `OwnPay\Service\Update\UpdateService::execute()`
* **Parameters**: `string $targetVersion`
* **Description**: Fired before a platform update begins execution. Use to trigger custom data backups.

#### `update.after`
* **Triggered From**: `OwnPay\Service\Update\UpdateService::execute()`
* **Parameters**: `string $targetVersion`
* **Description**: Fired when a platform update completes successfully.

#### `update.failed`
* **Triggered From**: `OwnPay\Service\Update\UpdateService::execute()`
* **Parameters**: `string $targetVersion`, `string $errorMessage`
* **Description**: Fired when an execution exception occurs during update operations.

#### `update.rollback`
* **Triggered From**: `OwnPay\Service\Update\UpdateService::execute()`
* **Parameters**: `string $targetVersion`
* **Description**: Fired when system rolls back to restoration point.
