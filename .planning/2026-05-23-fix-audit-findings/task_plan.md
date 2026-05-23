# Task Plan: Resolve Audit Findings

## Goal
Fix all codebase and database schema findings (suspended merchant blocks, BCMath calculation updates, and invoice foreign key constraints).

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Enforce Merchant Containment Checks (Finding 1)
- [x] Check merchant active status in BearerAuthMiddleware
- [x] Check merchant active status in DomainMiddleware
- [x] Check merchant active status in CheckoutController (show & pay)
- **Status:** complete

### Phase 2: Refactor Invoice Floating-Point Math to BCMath (Finding 2)
- [x] Refactor create() in InvoiceService to use BCMath
- [x] Refactor update() in InvoiceService to use BCMath
- **Status:** complete

### Phase 3: Apply Database Constraints (Finding 3)
- [x] Create 004_add_invoice_customer_fk.sql migration script
- [x] Update baseline schema.sql definition
- [x] Run migrations against development (ownpay) and test (ownpay_test) databases
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Execute scratch script verifying suspended merchant blocks
- [x] Run PHPUnit test suite (394 passing tests)
- [x] Run PHPStan static analysis (0 errors)
- **Status:** complete

### Phase 5: Delivery
- [x] Update task checklist and walkthrough documents
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Apply constraint migrations to both ownpay and ownpay_test | Ensures migrations are consistent across both environments and prevents test suite database schema failures. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| Relative autoload path error in scratch migration script | Used absolute paths in scratch script file. |
| DB enum warning on custom domain insertion | Inserted correct enum value 'checkout' for type column in op_domains. |
| DB missing column 'token' warning on op_transactions query | Updated transactions query to select trx_id in test verification script. |
