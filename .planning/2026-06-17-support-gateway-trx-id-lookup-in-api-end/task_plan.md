# Task Plan: Support Gateway TRX ID lookup in API endpoints

## Goal
Enable Payment, Transaction, and Refund API GET endpoints to lookup by either OwnPay trx_id or gateway_trx_id and return a descriptive 404 message if lookup fails via a gateway transaction ID.

## Current Phase
Phase 1: Discovery & Technical Details

## Phases

### Phase 1: Discovery & Technical Details
- [x] Analyze PaymentController lookup mechanism
- [x] Analyze TransactionController lookup mechanism
- [x] Analyze RefundController query mechanism
- [x] Verify TransactionRepository schema and methods
- **Status:** complete

### Phase 2: Design and Planning
- [x] Document proposed modifications in findings.md
- [x] Create testing plan for the modifications
- **Status:** complete

### Phase 3: Implementation
- [x] Modify `src/Controller/Api/TransactionController.php`
- [x] Modify `src/Controller/Api/RefundController.php`
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Write integration test cases in `tests/`
- [x] Execute `vendor/bin/phpunit` tests
- [x] Execute `vendor/bin/phpstan analyse` to ensure strict type compatibility
- **Status:** complete

### Phase 5: Documentation & Sync
- [x] Update OpenAPI specifications
- [x] Run `graphify update .` to update the knowledge graph
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use two-stage validation in RefundController::show | Allows us to check if transaction exists before determining whether to return "Refund not found" or the gateway 404 message |

## Errors Encountered
| Error | Resolution |
|-------|------------|

