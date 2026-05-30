# Task Plan: Functional SMS Gateway Addon

## Goal
Make the SMS Gateway addon fully functional and integrated by implementing `SmsProviderInterface`, and support automatic togglable SMS alerts to customers on invoice creation and payment success events.

## Current Phase
Phase 2: Planning & Structure

## Phases

### Phase 1: Requirements & Discovery
- [x] Read the existing SMS Gateway plugin code.
- [x] Analyze `SmsProviderInterface` and `CommunicationService`.
- [x] Confirm that events `invoice.created` and `payment.transaction.completed` are standard event triggers in OwnPay.
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Outline settings toggles and customizable templates.
- [x] Write implementation_plan.md artifact.
- [ ] Lock planning files hash via attestation script.
- **Status:** in_progress

### Phase 3: Implementation
- [ ] Implement `SmsProviderInterface` in `modules/addons/sms-gateway/Plugin.php`.
- [ ] Update `fields()` in `Plugin.php` adding toggle switches and templates for Invoice Created and Payment Success events.
- [ ] Implement `onInvoiceCreated` event listener routing alerts.
- [ ] Implement `onPaymentSuccess` event listener routing alerts.
- [ ] Hardened PHPStan Level 9 compliance for strict type conversions.
- **Status:** pending

### Phase 4: Testing & Verification
- [ ] Create integration test suite `tests/Integration/SmsGatewayAddonTest.php`.
- [ ] Run test suite using PHPUnit.
- [ ] Run static analysis using PHPStan.
- **Status:** pending

### Phase 5: Delivery
- [ ] Document final walkthrough.md.
- [ ] Deliver complete code.
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Backward Compatibility | Retain `sms.send` legacy action matching by routing it to the newly compliant `send` method signature. |
| Customer PII Decryption | Integrates with CustomerPiiService to safely decrypt target customer phone numbers prior to SMS transmission. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
