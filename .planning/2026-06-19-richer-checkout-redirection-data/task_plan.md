# Task Plan: Strict Payment ID Checkout Redirection & Lookup

## Goal
Switch the checkout redirect flow and status lookup API strictly to `payment_id` (the payment intent UUID) instead of the secure `token` or transaction ID, clean up any dead code, and ensure tests pass.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Identify standard Stripe/PayPal payment_id routing practices
- [x] Trace target route, controller, template, and client-side scripts
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Design step-by-step changes for routes, payment service, controllers, templates, and integration tests
- **Status:** complete

### Phase 3: Implementation
- [x] Add `findByUuid` lookup to `PaymentService`
- [x] Modify API routes inside `config/routes/api.php`
- [x] Refactor `PaymentController::show` to lookup strictly by `payment_id` (UUID)
- [x] Pass `intent_payment_id` to `checkout-status.twig` inside `PaymentIntentCheckoutController`
- [x] Update redirect parameters in `PaymentIntentCheckoutController::cancel`
- [x] Update data attributes in `templates/checkout/checkout-status.twig`
- [x] Refactor `public/assets/js/checkout-status.js` redirect logic
- [x] Update assertions and mock DB seeding in `tests/Integration/TrxIdLookupApiTest.php`
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run PHPUnit tests via `vendor/bin/phpunit`
- [x] Run style/syntax linters for Twig/JS/JSON
- **Status:** complete

### Phase 5: Delivery
- [x] Deliver the completed implementation and confirm verification outcomes
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Shift strictly to `payment_id` | Follows standard Stripe/Adyen/PayPal patterns by decoupling guest-checkout tokens from backend API queries. |
| Retrieve linked transaction dynamically in Payments API | Returns the full transaction context (fee, method, gateway, etc.) while preserving the lookup key as the Payment Intent UUID. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
