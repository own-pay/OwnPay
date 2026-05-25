# Task Plan: Brand Gateway Configuration Fix

## Goal
Fix brand-specific gateway configurations so that configured gateways display properly on the checkout page, and their event hooks run in the correct brand context.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent
- [x] Identify constraints
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define approach
- [x] Write implementation plan and obtain approval
- **Status:** complete

### Phase 3: Implementation
- [x] Upsert op_gateway_configs in PluginController::saveSettings
- [x] Update status in op_gateway_configs in PluginManager::activate and deactivate
- [x] Initialize BrandContext in CheckoutController, PaymentIntentCheckoutController, PaymentLinkCheckoutController, and InvoiceCheckoutController
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify using phpunit tests
- [x] Add unit test case if appropriate to check brand gateway resolution
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs
- [x] Deliver to user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|

## Errors Encountered
| Error | Resolution |
|-------|------------|
