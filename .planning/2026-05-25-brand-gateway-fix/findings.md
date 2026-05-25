# Findings & Decisions

## Requirements
- Fix the issue where gateway plugins configured for a specific brand/store are not displaying on the checkout page.
- Ensure that configuring a gateway plugin for a brand correctly creates/updates/syncs the corresponding record in `op_gateway_configs`.
- Ensure brand-specific activation/deactivation of a gateway plugin correctly syncs its `status` in `op_gateway_configs`.
- Ensure brand context is resolved and set in `BrandContext` during checkout flows so plugin hooks can be resolved.

## Research Findings
- `PluginController::saveSettings()` saves settings via `SettingsRepository` but does not check if the plugin is a gateway or sync with `op_gateway_configs`.
- `PluginManager::activate()` and `deactivate()` handle brand-specific plugin status in `op_brand_plugins` but do not synchronize `status` inside `op_gateway_configs` for gateway plugins.
- Checkout page flows (e.g. `CheckoutController`, `PaymentIntentCheckoutController`, `PaymentLinkCheckoutController`, `InvoiceCheckoutController`) do not set the active brand ID in the `BrandContext` service singleton, leading to failure when validating plugin ownership and status checks.
- `GatewayConfigRepository::listActiveForCheckout()` uses `TenantScope` and queries the `op_gateway_configs` table scoped by the brand/merchant ID and checks that status is 'active'.
- In `CheckoutController::show()`, it retrieves `apiGateways` using `$this->apiGw->forTenant($mid)->listActiveForCheckout()`. However, the `BrandContext` active brand ID is never set explicitly for the request, meaning any plugin hooks executed during the checkout flow will fail validation checks checking for active plugins.
- `BrandContext` has a `setActiveBrandId(int $id)` method which stores the active brand ID in the cache `$activeBrandId` and optionally in `$_SESSION` if a session is active. This can be used in public checkout controllers to set the tenant/brand context before invoking event hooks.
- `EventManager::isOwnerActive($owner)` gets the active brand ID from `BrandContext::getActiveBrandId()` and calls `PluginRegistry::isPluginActive($owner, $brandId)`. If `BrandContext` is not initialized with the transaction's merchant ID during public checkout, `getActiveBrandId()` returns `null` or a fallback brand ID, causing `isOwnerActive()` to fail or resolve incorrectly for the current brand.
- `PluginRegistry::isPluginActive($slug, $brandId)` caches brand active statuses. When a `$brandId` is provided, it reads the status overrides from `op_brand_plugins` via `PluginRepository::getBrandPluginStatuses($brandId)`. If a plugin has no override, it defaults to checking if the plugin is globally active in `op_plugins`.
- There are four checkout controllers in total that need to set the `BrandContext` active brand ID explicitly before invoking actions/hooks:
  1. [CheckoutController.php](file:///c:/laragon/www/ownpay/src/Controller/Checkout/CheckoutController.php): Sets brand context in `show()`, `pay()`, `cancel()`, `status()`.
  2. [InvoiceCheckoutController.php](file:///c:/laragon/www/ownpay/src/Controller/Checkout/InvoiceCheckoutController.php): Main flow redirects to `CheckoutController`, but any hooks inside it should also be evaluated in the correct brand context.
  3. [PaymentIntentCheckoutController.php](file:///c:/laragon/www/ownpay/src/Controller/Checkout/PaymentIntentCheckoutController.php): Renders checkouts directly for Intents. Needs brand context in `show()`, `pay()`, `status()`.
  4. [PaymentLinkCheckoutController.php](file:///c:/laragon/www/ownpay/src/Controller/Checkout/PaymentLinkCheckoutController.php): Handles payment links, redirects to checkout room. Should set brand context for hooks.
- The `op_gateway_configs` table schema is defined in [schema.sql](file:///c:/laragon/www/ownpay/database/schema.sql#L143). It contains: `id`, `merchant_id`, `gateway_id`, `credentials_enc`, `settings` (JSON), `mode` ('live', 'sandbox'), and `status` ('active', 'inactive').
- `DashboardController.php` (around lines 763-771) performs configuration writes/saves to `op_gateway_configs` for some settings (maybe built-in/deprecated gateways or settings).
- `GatewayBridge::decryptCredentials(string $gatewaySlug, int $merchantId)` attempts to decrypt credentials from `op_gateway_configs`. If it is null or empty, it falls back to retrieving the settings from `op_system_settings` under group `plugin.{$gatewaySlug}` for the specific brand.
- Since `listActiveForCheckout()` joins `op_gateway_configs` with `op_gateways` and filters by `gc.status = 'active'`, any plugin-based gateway configured for a brand must have a corresponding record in `op_gateway_configs` with `status = 'active'`, otherwise it is completely omitted from the active gateways on checkout.
- We must upsert a record in `op_gateway_configs` for the brand when gateway plugin settings are saved (`PluginController::saveSettings`) and synchronize the status in `op_gateway_configs` when the gateway plugin is activated/deactivated for a brand (`PluginManager::activate` / `deactivate`).
- [x] Implemented gateway config synchronization in `PluginController::saveSettings()`.
- [x] Implemented gateway config synchronization in `PluginManager::activate()` and `deactivate()`.
- [x] Initialized `BrandContext` active brand ID in `CheckoutController`.
- [x] Analyzed `PaymentIntentCheckoutController` methods: `show()` resolves merchant ID via `$mid` from `$intent['merchant_id']`, `pay()` resolves it similarly, and `status()` resolves it via `$mid` from `$intent['merchant_id']`.
- [x] Initialized `BrandContext` active brand ID in `PaymentIntentCheckoutController`.
- [x] Initialized `BrandContext` active brand ID in `InvoiceCheckoutController`.
- [x] Initialized `BrandContext` active brand ID in `PaymentLinkCheckoutController`.
- Discovered tests inside `tests/Plugin/`: `PluginManifestTest.php`, `PluginTrashTest.php`, `TenantPluginLifecycleTest.php`.
- `IntegrationTestCase` initializes the database connection and skips tests if not available.
- We will write a brand new test class `tests/Plugin/BrandGatewayConfigSyncTest.php` to verify synchronization.
- `Request` constructor accepts `query`, `post`, `server`, `files`, `cookies`, `rawBody`. We can use it to mock plugin configuration save requests.
- `Request::all()` merges query parameters, JSON body, and POST variables.
- `Request` has a method `setRouteParams(array $params): void` to set route parameters.
- **Bug identified**: In `PluginManager::activate()`, the brand gateway configs sync logic was running before `registerGatewayDefinition()` registered the gateway globally in `op_gateways`. Consequently, the gateway lookups returned null, preventing the config record from being created. We must move the activation sync logic to run after `registerGatewayDefinition()`.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
-
