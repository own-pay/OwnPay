# Task Plan: Africa & Middle East (MENA) MFS Payment Gateways (Batch 4)

## Goal
Develop, harden, and verify 5 secure, production-ready payment gateways: MTN Mobile Money (MoMo), Orange Money, OPay, MyFatoorah, and Tap Payments, with 100% PHPStan Level 9 and PHPUnit passing rates.

## Current Phase
Phase 2: Planning & Structure

## Phases

### Phase 1: Requirements & Discovery
- [x] Research and document API specifications for Batch 4 gateways
- [x] Identify constraints, currency support, and security mechanisms
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Identify folder structures and verify missing resources (e.g. icons, manifests)
- [x] Create directories, manifest.json, and icon.svg for OPay, MyFatoorah, Tap Payments
- [x] Complete Orange Money icon.svg
- **Status:** complete

### Phase 3: Implementation
- [x] Implement MtnMomoGateway.php
- [x] Implement OrangeMoneyGateway.php
- [x] Implement OpayGateway.php
- [x] Implement MyfatoorahGateway.php
- [x] Implement TapPaymentsGateway.php
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Create Unit/AfricaMenaGatewayTest.php containing comprehensive contract and sandbox bypass tests
- [x] Run PHPUnit tests until 100% pass rate is met
- [x] Run PHPStan Level 9 analysis and resolve any detected issues
- [x] Verify loadability of all gateway plugins via scratch script
- **Status:** complete

### Phase 5: Delivery
- [x] Update walkthrough.md and plan files
- [x] Perform plan attestation sync
- [x] Propose Batch 5 to user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| **Use `tap-payments` slug** | Distinguishes Tap Payments (MENA) from Dutch-Bangla Trust Axiata Pay (`tap`) from Bangladesh. |
| **No heavy SDKs** | Minimizes security vulnerability surface area and deployment footprint. |

## Errors Encountered
| Error | Resolution |
|-------|------------|

