# Findings & Decisions

## Requirements
- Conduct an in-depth codebase audit to find hidden concurrency, ledger, and scoping bugs.
- Maintain strict type safety (PHPStan level 9) and 100% test coverage.

## Research Findings
- **Vulnerability Identified (Concurrent Payment Intent Checkout Creation)**: In `PaymentIntentCheckoutController::pay()` and `expressPay()`, concurrent AJAX requests submitted for the same payment intent do not acquire a database row-level lock on the intent or transaction tables. Both requests can find no existing transaction and proceed to call `TransactionService::create()`, creating two separate transaction rows in `op_transactions` pointing to the same `payment_intent_id`. Since each has a unique `trx_id`, both can be verified and completed independently by callbacks, leading to double-crediting of the merchant's ledger balance for a single intent.
- **Test Failure Root Cause (Database Connection Mismatch)**: The eager-booting of the `Database` singleton inside `config/services.php` (when the `.installed` lock file is present) causes `Database::setInstance()` to register a new PDO connection instance before the integration tests can override the container bindings. When the controller resolves `Database::getInstance()`, it receives the eager-booted connection instead of the test connection override, resulting in two distinct database connections trying to acquire conflicting transaction locks and causing a `1205 Lock wait timeout exceeded` error on insert.
- **Remediation**: 
  1. Wrap the payment intent fetching, status/expiration checks, and transaction lookup/creation logic inside a database transaction block using `SELECT ... FOR UPDATE` row locks on both `op_payment_intents` and `op_transactions`. Re-use existing non-terminal transactions atomically.
  2. In `FinancialLeakageAuditTest::testPaymentIntentCheckoutConcurrencyPrevention`, explicitly restore the correct static database singleton by calling `\OwnPay\Core\Database::setInstance($this->db)` right after registering the container instance.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Apply row-level locks to payment intent checkouts | Guarantees that only one transaction can be active/processing for a single payment intent, preventing duplicate transaction creation and double-crediting. |
| Restore static database instance in test | Align the container's Database instance with the global `Database::getInstance()` singleton so both the controller and repositories share the same connection. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| 1205 Lock wait timeout on concurrent test | Restore the static database instance via `setInstance()` in the test. |

## Resources
- [PaymentIntentCheckoutController.php](file:///c:/laragon/www/ownpay/src/Controller/Checkout/PaymentIntentCheckoutController.php)
- [FinancialLeakageAuditTest.php](file:///c:/laragon/www/ownpay/tests/Integration/FinancialLeakageAuditTest.php)

