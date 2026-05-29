# Findings & Decisions - Financial Leakage Concurrency Audit

## Requirements
- Audit the payment callback processes and refund processing mechanisms for financial leakage risk.
- Identify concurrency race conditions, double-posting opportunities, or refund limits bypasses.
- Implement robust row-locking and transaction isolation boundaries to guarantee 100% financial integrity under high parallel volume.

## Research Findings

### FIND-011: [CRITICAL] Gateway Callback Concurrency Double-Posting Race Condition
- **Location**: `src/Service/Payment/GatewayApiService.php` (inside `handleCallback()`)
- **Description**: The gateway callback processing did not perform database-level exclusive row locking (`FOR UPDATE`) when loading transactions to evaluate their status. Under high concurrent transaction notifications (e.g. if the customer redirect and server-to-server IPN arrive concurrently at the exact same millisecond), both threads would load the transaction status as `pending` before either could commit `'completed'`. Consequently, both threads would execute transaction completion and double-post to the ledger, creating duplicate merchant payables and Cash debits.
- **Impact**: Financial leakage via double-crediting merchant balances and incorrect ledger reporting under high concurrency.
- **Priority**: P0 (Critical - Fix immediately)

### FIND-012: [HIGH] Refund Limit Bypasses via Concurrency Race Conditions
- **Location**: `src/Service/Payment/RefundService.php` (inside `create()`)
- **Description**: Calculating the remaining non-refunded amount and creating a refund record occurred sequentially without transaction scope isolation or explicit row locks. If two refund requests for the exact same completed transaction were processed concurrently, both queries to `getTotalRefundedAmount()` would see the same total and allow both refunds to process, exceeding the original transaction amount.
- **Impact**: Merchant balance leakage through unauthorized excess refunds.
- **Priority**: P1 (High - Fix immediately)

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| **Inject `FOR UPDATE` lock on `handleCallback`** | Enforces a secure exclusive write lock on the transaction row within a database transaction block, forcing concurrent callbacks to block and wait. The first thread completes the payment; subsequent threads unblock, see status `'completed'`, and cleanly skip execution. |
| **Wrap `RefundService::create` in exclusive locks** | Wraps the entire refund verification, sum calculation, and gateway execution in a database transaction block. Employs exclusive `FOR UPDATE` row locks on both the parent transaction record and related refunds totals to prevent concurrent capacity bypasses. |

## Resources
- [GatewayApiService.php](file:///c:/laragon/www/ownpay/src/Service/Payment/GatewayApiService.php)
- [RefundService.php](file:///c:/laragon/www/ownpay/src/Service/Payment/RefundService.php)
- [FinancialLeakageAuditTest.php](file:///c:/laragon/www/ownpay/tests/Integration/FinancialLeakageAuditTest.php)
