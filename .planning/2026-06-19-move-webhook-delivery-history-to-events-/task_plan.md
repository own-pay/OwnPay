# Task Plan: Move webhook delivery history to events page and add gateway_trx_id to outbound webhook payload

## Goal
Relocate the Webhook Delivery History table to the Webhook Events page, and dynamically include the gateway transaction ID in outbound webhook payloads without modifying the database schema.

## Current Phase
None (All tasks completed)

## Phases

### Phase 1: Planning & Research
- [x] Research template locations and DB columns
- [x] Document research findings in findings.md
- [x] Define the exact changes to be made (this plan)
- **Status:** complete

### Phase 2: Implementation — Outbound Payload Enrichment
- [x] Modify `WebhookDispatcher::buildPayload()` to include `gateway_trx_id`
- [x] Modify `WebhookService::dispatch()` to include `gateway_trx_id`
- [x] Update `tests/Unit/WebhookDispatcherTest.php` to assert the presence of `gateway_trx_id` in test payloads
- [x] Run PHPUnit tests to verify payload changes
- **Status:** complete

### Phase 3: Implementation — UI Relocation & Controller Mapping
- [x] Update `WebhookDispatcher::listDeliveries` signature to allow `?int $merchantId`
- [x] Remove Webhook Delivery History card from `templates/admin/developer/index.twig`
- [x] Update `DeveloperController::index` to remove fetching and passing `webhook_deliveries`
- [x] Update `WebhookEventController::index` to fetch deliveries via `WebhookDispatcher` and pass them to the template
- [x] Append Webhook Delivery History card to `templates/admin/webhooks/events.twig`
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify template syntax using `composer lint:twig` and asset syntax with `npm run lint`
- [x] Run PHPUnit test suite (`vendor/bin/phpunit`)
- [x] Perform manual visual sanity checks if possible
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Load `gateway_trx_id` from `op_transactions` if not passed in array | Guarantees reliability even when webhook triggers pass minimal transaction details. |
| Allow nullable merchant ID in `listDeliveries` | Gracefully supports global superadmin list view. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
