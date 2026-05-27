# Findings & Decisions

## Requirements
- **Comprehensive Master Developer Guide (`developer-guide.md`)**: Must cover all fields in `manifest.json`, exhaustive interface function details, best practices, sandboxing constraints, domain urls, and scaffolding CLI.
- **Detailed Hooks & Filter Index (`hooks-reference.md`)**: Must exhaustively map system actions and filters, signature details, and dynamic webhook handlers.
- **Premium Explanation Level**: Production-grade explanation value, complete and rich documentation, clear do's and don'ts, best practices, and real code snippets.

## Research Findings
- **Plugin Entrypoints & Interfaces**:
  - `PluginInterface.php`: Entry contract requiring `metadata()`, `capabilities()`, `register()`, `boot()`, `deactivate()`, `uninstall()`, and `fields()`.
  - `GatewayAdapterInterface.php`: Payment adapter contract requiring `slug()`, `initiate()`, `verify()`, `verifyWebhook()`, `refund()`, `supports()`, and `supportedCurrencies()`.
  - `GatewayDefaults.php`: Pre-packaged traits for gateway adapter boilerplate, offering type casting wrappers (`getString`, `getInt`, etc.).
- **Security Sandboxing (`PluginSandbox.php`)**:
  - Blocks dangerous execution/reflection functions (e.g. `exec`, `eval`, `call_user_func`, `reflectionclass`, `array_map`, etc.).
  - Restricts filesystem access exclusively within the plugin directory boundaries and sandboxed `/storage` subdirectory.
  - Controls SQL queries by filtering statement structure and strictly blocking any query containing references to system `op_*` tables (except `op_plugin`), forcing database operations to leverage the Core Repository / Service APIs.
- **Manifest Engine (`PluginManifest.php`)**:
  - Exposes robust schemas for settings definition, capabilities registration, admin menus, migrations setup, and background cron schedules.
- **Webhook Routing & Domain Url Integration**:
  - Dispatches dynamically via `webhook.incoming.{slug}` action hooks carrying a unified, validated `WebhookPayload` value object.
  - Adheres strictly to PSR-4 standards under the base namespace `OwnPayPlugin\`.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| **Complete Re-Write of Both Documents** | The existing guides are significantly outdated, carrying inaccurate interface signatures and missing the vast suite of capabilities, database scopes, and CLI scaffolding controls introduced in modern updates. |
| **Include End-To-End Implementation Blueprints** | In order to provide a Paid Master-Class standard, we will include two fully structured implementation templates: a high-security automated gateway plugin and an administration reporting dashboard addon, giving developers immediate drag-and-drop value. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Mismatched Interface Signatures | Identified that `developer-guide.md` illustrated obsolete signatures (e.g. `register(EventManager $events)` instead of the correct PHP 8.2+ PSR-4 compliant `register(EventManager $events, Container $container)` signature). Updated blueprints will display 100% accurate system models. |

## Resources
- [PluginInterface.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginInterface.php)
- [GatewayAdapterInterface.php](file:///c:/laragon/www/ownpay/src/Gateway/GatewayAdapterInterface.php)
- [GatewayDefaults.php](file:///c:/laragon/www/ownpay/src/Gateway/GatewayDefaults.php)
- [PluginManifest.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginManifest.php)
- [PluginSandbox.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginSandbox.php)

## Supplementary Research Findings
- **Content Security Policy (CSP) Importance**:
  - Extremely critical for payment gateways. If the browser blocks external hosts (e.g. Stripe, PayPal checkout, GCash) because they are missing from CSP whitelist, checkouts will fail completely on the front-end, even if the backend behaves correctly.
  - Declaring `"csp"` in `manifest.json` enables the core `SecurityHeadersMiddleware` to automatically merge whitelisted domains, keeping the platform protected yet fully functional.
- **EventManager Capabilities**:
  - `register(EventManager $events, Container $container)`: The base entry method.
  - Available methods: `addAction()`, `doAction()`, `addFilter()`, `applyFilters()`, `removeAction()`, `removeFilter()`, `hasAction()`, `hasFilter()`.
- **Filesystem Security Boundaries**:
  - `file_put_contents()` is completely blocked by `PluginSandbox::isDangerousFunction()`.
  - To read/write files in sandboxed plugin environments, developers must utilize `fopen()`, `fwrite()`, `fread()`, and `fclose()` within the dedicated storage directory (`$sandbox->storagePath()` which maps to `modules/<type>/<slug>/storage/`).
- **Database Access Mechanisms**:
  - Raw SQL access to core `op_*` tables is blocked by the parser.
  - Safe Way: Inject and invoke brand-scoped Core Services and Repositories via PSR-11 container (e.g. `TransactionService` cloned via `$service->forTenant($merchantId)`), which automatically secures data isolation.
