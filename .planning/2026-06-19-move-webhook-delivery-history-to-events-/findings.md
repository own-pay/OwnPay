# Findings & Decisions

## Requirements
- Move the "Webhook Delivery History" table from `/admin/developer#webhooks` to the `/admin/webhooks/events` page.
- Add `gateway_trx_id` to the outbound webhook payload.
- Do not create any new DB schemas/tables; reuse the existing database columns.

## Research Findings
- **UI Source:** The Webhook Delivery History table is currently in `templates/admin/developer/index.twig` (lines 273-323). It displays outbound/inbound deliveries.
- **UI Destination:** The table should be relocated to the bottom of `templates/admin/webhooks/events.twig`.
- **Controller Data Routing:**
  - `Admin\DeveloperController@index` fetches deliveries via `$dispatcher->listDeliveries($mid, 50)`.
  - `/admin/webhooks/events` is handled by `Admin\WebhookEventController@index`, which currently does not fetch deliveries.
  - `WebhookDispatcher::listDeliveries(int $merchantId, int $limit)` only supports a non-nullable integer merchant ID, which causes issues for global/superadmin views where `$mid` is null. We will modify the signature to `?int $merchantId` to support global deliveries listing.
- **Gateway Transaction ID:**
  - Stored in `op_transactions.gateway_trx_id` (type: `VARCHAR(200)`).
  - Outbound payloads are built via `WebhookDispatcher::buildPayload(string $event, array $data)`.
  - Custom payloads sent via `WebhookService::dispatch(int $merchantId, string $eventType, array $payload)` are saved as JSON and then sent via `deliver()`.
  - We will enrich the payload in both places to safely fetch and attach the `gateway_trx_id` if a transaction identifier (`transaction_id`, `trx_id`, or `id`) is present in the data array.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Update `WebhookDispatcher::listDeliveries` parameter type to `?int` | Support superadmin view where `merchant_id` is null (global dataset listing). |
| Query `op_transactions` by transaction key if `gateway_trx_id` is not present in payload | Ensure backward compatibility and robust resolution for payloads constructed without explicit database columns. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
-
