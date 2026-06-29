# Findings & Decisions - Express Checkout Plugins

## Requirements

- **Create Google Pay (`google-pay`) & Apple Pay (`apple-pay`) plugins** under `modules/gateways/`.
- **Dynamic visual state**: The Express Checkout buttons (Apple Pay & Google Pay) should appear on the checkout page ONLY if their respective gateway plugins are active and configured for the brand/merchant.
- **Premium Styling**: Match exactly the premium TailwindCSS + SVG vectors styling defined in `docs/v2/theme/Own_pay_checkout_template.html`.
- **Zero Legacies & Robustness**: Complete typing (PHP 8.2+ `declare(strict_types=1)`), balance double-entry ledger bookkeeping constraints, no placeholders, and fully dynamic checkout handling.

## Research Findings

1. **Design Reference**:
   - `docs/v2/theme/Own_pay_checkout_template.html` (lines 201-208) implements the express checkout section.
   - Apple Pay: Dark premium theme button, custom Apple icon, calls `doQP('Apple Pay')`.
   - Google Pay: White borderless-like premium button, custom Google logo, calls `doQP('Google Pay')`.
2. **Template Path**:
   - `templates/checkout/partials/_express-checkout.twig` is where the express checkout elements are rendered.
   - Currently, it statically outputs Apple Pay and Google Pay buttons when `config.expressCheckout` is enabled.
3. **Gateway Registration**:
   - `PluginLoader::loadActive()` automatically registers active plugins implementing `GatewayAdapterInterface` into the `GatewayBridge` service.
   - `PluginManager::registerGatewayDefinition()` registers active gateway plugins into the database `op_gateways` table under type `api`.
4. **Checkout Route Configuration**:
   - `public/assets/js/checkout.js` has a quick pay handler `window.doQP` that POSTs to `basePath + '/express'`.
   - The `/express` route does not exist in `config/routes/web.php` or `PaymentIntentCheckoutController.php`. Adding it enables seamless execution without needing custom client overrides.

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| **Use Category `express` in Manifest** | Separates Apple Pay/Google Pay from standard credit card gateways in `gateways.global` so they don't clutter the normal tabs grid, but routes them to `$gateways['express']`. |
| **Implement `/express` POST Route** | Allows using the existing `window.doQP` hook in `checkout.js` without rewriting compiled JS or introducing fragile overrides. |
| **Leverage Standard `/pay` Pipeline for Wallet Callback** | Initiating Apple Pay/Google Pay creates a secure `op_transactions` pending record, returns callback URL, and verification runs double-entry ledger double-capture lock checks automatically. |
| **Verify CSRF & Domain/Tenant Context** | Restricts express checkout requests to the verified domain scope, matching core white-label requirements. |
