# Task Plan: Global Giants (BNPL, Direct Debit, & Wallets) (Batch 5)

## Goal
Develop, harden, and verify 6 secure, production-ready payment gateways: Amazon Pay, GoCardless, Affirm, Afterpay, Sezzle, and BitPay, with 100% PHPStan Level 9 and PHPUnit passing rates.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Research and document API specifications for Batch 5 gateways
- [x] Identify constraints, currency support, and security mechanisms
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Identify folder structures and verify missing resources (e.g. icons, manifests)
- [x] Create directories, manifest.json, and icon.svg for Amazon Pay, GoCardless, Affirm, Afterpay, Sezzle, BitPay
- **Status:** complete

### Phase 3: Implementation
- [x] Implement AmazonPayGateway.php
- [x] Implement GocardlessGateway.php
- [x] Implement AffirmGateway.php
- [x] Implement AfterpayGateway.php
- [x] Implement SezzleGateway.php
- [x] Implement BitpayGateway.php
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Create Unit/GlobalGiantsGatewayTest.php containing comprehensive contract and sandbox bypass tests
- [x] Run PHPUnit tests until 100% pass rate is met
- [x] Run PHPStan Level 9 analysis and resolve any detected issues
- [x] Verify loadability of all gateway plugins via scratch script
- **Status:** complete

### Phase 5: Delivery
- [x] Update walkthrough.md and plan files
- [x] Perform plan attestation sync
- [x] Finalize work
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|

## Errors Encountered
| Error | Resolution |
|-------|------------|
