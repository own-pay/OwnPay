# Task Plan: Resolve Code Review Gaps

## Goal
Implement merchant status containment checks in `PaymentIntentCheckoutController` and optimize database binding formats in `InvoiceService`.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Discovery
- [x] Read `PaymentIntentCheckoutController.php` and identify exact insertion points for status checks
- [x] Review float conversion points in `InvoiceService.php`
- **Status:** complete

### Phase 2: Design & Plan Approval
- [x] Define code review gaps resolution plan
- [x] Create implementation plan artifact
- **Status:** complete

### Phase 3: Implementation
- [x] Add merchant status checks in `PaymentIntentCheckoutController::show`
- [x] Add merchant status checks in `PaymentIntentCheckoutController::pay`
- [x] Add merchant status checks in `PaymentIntentCheckoutController::expressPay`
- [x] Refactor float casts in `InvoiceService::create` and `InvoiceService::update` to pass BCMath output strings directly to database operations
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Execute scratch tests verifying suspended merchant block on payment intents
- [x] Run PHPUnit test suite to ensure all tests pass
- [x] Run PHPStan static analysis to verify 0 errors
- **Status:** complete

### Phase 5: Delivery
- [x] Update walkthrough document and task checklist
- [x] Commit all code changes to git
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Validate merchant status directly in PaymentIntentCheckoutController | Ensures that payment intents cannot be viewed or paid if the owner merchant is suspended, blocking any master domain bypass. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
