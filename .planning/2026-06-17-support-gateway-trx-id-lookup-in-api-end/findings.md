# Findings & Decisions

## Requirements
- Support GET `/api/v1/payments/{trx_id}`, `/api/v1/transactions/{trx_id}`, and `/api/v1/refunds/{trx_id}` lookup by either the OwnPay Transaction ID (`trx_id`, starting with `OP-`) or the Gateway/Provider transaction ID (`gateway_trx_id`).
- When lookup fails using a gateway transaction ID, return the specific message: `"Transaction not found using the gateway transaction ID. It may be an incomplete, pending, or failed payment. Try querying with the OwnPay transaction ID."`
- Keep API error/success responses aligned with `Response::apiError()` standards.
- Run tests and static analysis.

## Research Findings
- `TransactionRepository` uses the `TenantScope` trait to scope queries by `merchant_id`. It already defines `findByGatewayTrxId(string $gatewayTrxId): ?array` which queries by `gateway_trx_id` and filters by merchant_id context (`$this->requireTenant()`).
- `PaymentController::show` is already updated and checks `findByTrxId($trxId)` then falls back to `findByGatewayTrxId($trxId)`.
- `TransactionController::show` only queries `findByTrxId($trxId)` and needs the exact same fallback and custom error message logic.
- `RefundController::show` performs direct DB query via PDO:
  ```sql
  SELECT r.* FROM op_refunds r
  JOIN op_transactions t ON t.id = r.transaction_id
  WHERE t.trx_id = :trx_id AND r.merchant_id = :mid
  ```
  We need to search using either `t.trx_id = :trx_id` or `t.gateway_trx_id = :trx_id`.
  To ensure precise error handling (i.e. whether to return the customized gateway 404 error or a generic refund 404 error), we should first search for the transaction by both IDs. If not found, output the appropriate transaction 404 message. If found, search for the associated refund.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Update `TransactionController::show` fallback | Use `findByGatewayTrxId($trxId)` and return custom 404 if not found and not starting with `OP-` |
| Two-stage query in `RefundController::show` | Provides correct context for error messages: distinguishes between "Transaction not found using gateway ID" (404) and "Refund not found" (404) |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [PaymentController](file:///c:/laragon/www/ownpay/src/Controller/Api/PaymentController.php)
- [TransactionController](file:///c:/laragon/www/ownpay/src/Controller/Api/TransactionController.php)
- [RefundController](file:///c:/laragon/www/ownpay/src/Controller/Api/RefundController.php)
- [TransactionRepository](file:///c:/laragon/www/ownpay/src/Repository/TransactionRepository.php)

