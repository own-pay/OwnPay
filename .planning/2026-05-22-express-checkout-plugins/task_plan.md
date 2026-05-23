# Task Plan: Express Checkout Plugins

## Goal
Create Google Pay (`google-pay`) and Apple Pay (`apple-pay`) express checkout gateway plugins, implement `/express` POST routing, integrate them in `CheckoutController` and `PaymentIntentCheckoutController`, and dynamically render the premium SVG buttons on the checkout page when active.

## Current Phase
Phase 3: Route & Controller Integrations

## Phases

### Phase 1: Discovery & Planning
- [x] Research template design (`Own_pay_checkout_template.html`)
- [x] Inspect existing express-checkout partial and checkout JS
- [x] Create detailed design and implementation plan
- **Status:** complete

### Phase 2: Gateway Plugins Development
- [x] Create `apple-pay` manifest, PHP gateway adapter, and icon SVG
- [x] Create `google-pay` manifest, PHP gateway adapter, and icon SVG
- **Status:** complete

### Phase 3: Route & Controller Integrations
- [ ] Add `/express` POST route in `config/routes/web.php`
- [ ] Add `'express' => []` to `$gateways` array in `CheckoutController.php` and `PaymentIntentCheckoutController.php`
- [ ] Implement `expressPay()` method in `CheckoutController.php`
- [ ] Implement `expressPay()` method in `PaymentIntentCheckoutController.php`
- **Status:** in_progress

### Phase 4: Dynamic Theme & Template
- [ ] Update `templates/checkout/checkout.twig` to conditionally load express checkout based on `gateways.express`
- [ ] Update `templates/checkout/partials/_express-checkout.twig` to dynamically render premium SVG buttons for Apple Pay and Google Pay
- **Status:** pending

### Phase 5: Verification & Seeding
- [ ] Write integration or unit tests / Run PHPUnit
- [ ] Seed the plugins and check configs for testing
- [ ] Visual verification & Attestation
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| `"category": "express"` | Group wallets under express category key to dynamically isolate from tab loops while enabling auto-rendering. |
| POST `/express` route | Leverages standard `window.doQP` JavaScript handler on checkout pages. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
