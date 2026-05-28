# Task Plan: Southeast Asia MFS & E-Wallets (Batch 3)

## Goal
Systematically design, implement, and validate 5 new production-ready, highly secure payment gateway plugins for the Southeast Asian digital wallet ecosystem: ShopeePay, Touch 'n Go (eWallet), Billplz, MoMo (Vietnam), and TrueMoney.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent and regional requirements
- [x] Identify constraints (specifically VND integer subunits)
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define approach and structure
- [x] Create project structure and manifest/icon files under `modules/gateways/`
- **Status:** complete

### Phase 3: Implementation
- [x] Implement `ShopeePayGateway.php` integrating Omise hosted sources
- [x] Implement `TouchNGoGateway.php` integrating Stripe PaymentIntents Touch 'n Go channel
- [x] Implement `BillplzGateway.php` with direct REST API v3 bills creation
- [x] Implement `MomoGateway.php` with direct REST API v2 captureWallet integration
- [x] Implement `TrueMoneyGateway.php` integrating Omise hosted sources
- [x] Ensure strict typing and constructor injection across all classes
- [x] Apply high-precision BCMath math precision for subunit conversions (and VND integer validation)
- [x] Harden simulation mode inputs against live mode bypasses
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify requirements met via custom tests in `tests/Unit/SoutheastAsiaGatewayTest.php`
- [x] Document test results (0 errors in PHPStan Level 9, 418/418 tests in PHPUnit)
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs and validate all 112 gateway adapters load cleanly
- [x] Deliver walkthrough.md to user
- [x] Prompt user for next batch (Batch 4: Africa & MENA MFS)
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Stripe for Touch 'n Go | Stripe provides certified, extremely stable Touch 'n Go API integrations which guarantees instant merchant compliance and payment success. |
| Omise for ShopeePay & TrueMoney | Omise is the primary ShopeePay/TrueMoney acquirer in Southeast Asia, ensuring robust hosted redirect source options. |
| VND 1:1 Subunit Math | Vietnamese Dong does not support decimal subunits. bcmul with '1' and 0 decimal places ensures integer-only amounts. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| Mixed array offset access on authorize_uri | Wrapped in getArray core helper for scannable_code and image arrays. |
| Mixed array offset access on redirect_to_url | Wrapped in getArray core helper for next_action and redirect_to_url arrays. |
