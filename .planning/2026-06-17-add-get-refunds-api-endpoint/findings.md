# Findings & Decisions

## Requirements
- Add a new API endpoint: GET `/api/v1/refunds` to return refund history, status.
- Follow the patterns established by the transaction get endpoint (`/api/v1/transactions`).
- Follow best practices: type safety, parameterized queries, merchant isolation/scoping, and standard response formats.

## Research Findings
- Found `config/routes/api.php` maps transaction endpoints:
  - GET `/api/v1/transactions` -> `Api\TransactionController@index`
  - GET `/api/v1/transactions/{trx_id}` -> `Api\TransactionController@show`
- Found existing refund routes:
  - POST `/api/v1/refunds` -> `Api\RefundController@create`
  - GET `/api/v1/refunds/{trx_id}` -> `Api\RefundController@show`
- Added endpoint:
  - GET `/api/v1/refunds` to list all refunds.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Add GET `/api/v1/refunds` | Complete the refund API and satisfy the requirements. |
| Constructor injection of RefundRepository | Follow standard controller dependency injection pattern. |
| Join `op_transactions` on list/count queries | Allow filtering by original transaction `trx_id` and return `trx_id` in response. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Database Exception: uuid field required | Set up transactions with UUIDs in the test seeder. |

## Resources
- [api.php](file:///c:/laragon/www/ownpay/config/routes/api.php)
- [RefundController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/RefundController.php)
- [RefundRepository.php](file:///c:/laragon/www/ownpay/src/Repository/RefundRepository.php)
- [RefundApiIntegrationTest.php](file:///c:/laragon/www/ownpay/tests/Integration/RefundApiIntegrationTest.php)
