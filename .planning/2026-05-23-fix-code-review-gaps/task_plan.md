# Task Plan: Resolve Code Review Gaps

## Goal
Implement merchant status containment checks in `PaymentIntentCheckoutController` and optimize database binding formats in `InvoiceService`.

## Current Phase
Phase 1: Discovery

## Phases

### Phase 1: Discovery
- [x] Read `PaymentIntentCheckoutController.php` and identify exact insertion points for status checks
- [x] Review float conversion points in `InvoiceService.php`
- **Status:** complete

### Phase 2: Design & Plan Approval
- [ ] Define code review gaps resolution plan
- [ ] Create implementation plan artifact
- **Status:** in_progress

### Phase 3: Implementation
- [ ] Add merchant status checks in `PaymentIntentCheckoutController::show`
- [ ] Add merchant status checks in `PaymentIntentCheckoutController::pay`
- [ ] Add merchant status checks in `PaymentIntentCheckoutController::expressPay`
- [ ] Refactor float casts in `InvoiceService::create` and `InvoiceService::update` to pass BCMath output strings directly to database operations
- **Status:** pending

### Phase 4: Testing & Verification
- [ ] Execute scratch tests verifying suspended merchant block on payment intents
- [ ] Run PHPUnit test suite to ensure all tests pass
- [ ] Run PHPStan static analysis to verify 0 errors
- **Status:** pending

### Phase 5: Delivery
- [ ] Update walkthrough document and task checklist
- [ ] Commit all code changes to git
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Validate merchant status directly in PaymentIntentCheckoutController | Ensures that payment intents cannot be viewed or paid if the owner merchant is suspended, blocking any master domain bypass. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
