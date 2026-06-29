# Task Plan: Batch 2 Gateway Integration - India MFS, UPI & Aggregators

## Goal

Systematically design, implement, and validate 5 new production-ready, highly secure payment gateway plugins for the Indian Localized MFS ecosystem: Paytm, Cashfree, PayU India, Instamojo, and MobiKwik.

## Current Phase

Phase 5: Delivery & Documentation

## Phases

### Phase 1: Requirements & Discovery

- [x] Research Paytm, Cashfree, PayU, Instamojo, and MobiKwik APIs and specifications
- [x] Gather details on math (BCMath), signature, and webhook verification algorithms
- [x] Document discoveries and API parameters in `findings.md`
- **Status:** complete

### Phase 2: Planning & Structure

- [x] Create directory structures under `modules/gateways/` for:
  - `paytm`
  - `cashfree`
  - `payu`
  - `instamojo`
  - `mobikwik`
- [x] Write `manifest.json` for each gateway containing appropriate metadata, namespace, and CSPs
- [x] Create elegant custom `icon.svg` files for each gateway
- **Status:** complete

### Phase 3: Implementation

- [x] Implement `CashfreeGateway.php` integrating `payment_session_id` hosted redirect flow and HMAC-SHA256 signature IPN check
- [x] Implement `PaytmGateway.php` with native OpenSSL AES128-CBC encryption and decryption routines for checksum generation and validation
- [x] Implement `PayuGateway.php` with standard request parameters and SHA512 reverse hash validation (handling additionalCharges properly)
- [x] Implement `InstamojoGateway.php` with OAuth2 authentication, payment request creation, and MAC verification (sorting alphabetically by key, case-insensitive)
- [x] Implement `MobikwikGateway.php` using Zaakpay web checkout with alphabetical key `ksort` and SHA-256 HMAC checksum signing
- [x] Ensure each file begins with `declare(strict_types=1);` and utilizes constructor injection
- [x] Ensure all subunit conversions utilize strict string casting and `bcmul()`/`bcdiv()` (BCMath math precision)
- [x] Harden sandbox simulation inputs to prevent live mode bypass (rejection of sandbox/simulation parameters in live mode)
- **Status:** complete

### Phase 4: Testing & Verification

- [x] Create mock integration unit tests in `tests/Unit/IndiaMfsGatewayTest.php` to verify mathematically precise conversions, sandbox live isolation, and loadability
- [x] Run PHPStan analysis to ensure 100% Level 9 compliance with zero errors across the entire codebase
- [x] Run the PHPUnit test suite to confirm 100% passing tests
- [x] Validate module loadability of all gateway adapters using the loadability script
- **Status:** complete

### Phase 5: Delivery & Documentation

- [x] Update `walkthrough.md` with final integration summaries
- [x] Update structural documentation in `docs/v2/plugins/hooks-reference.md` or general API documentation if required
- **Status:** complete

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| Dependency-free Paytm Checksum | Write native OpenSSL AES-128-CBC routines inside PaytmGateway to keep OwnPay lightweight and fast without adding external packages. |
| Zaakpay as MobiKwik engine | MobiKwik PG operates on the Zaakpay engine. We will implement Zaakpay web checkout with ksort-based HMAC-SHA256 checksums. |

## Errors Encountered

| Error | Resolution |
|-------|------------|
| redunant null coalesce | Removed unnecessary ?? null coalesce operator in Instamojo webhook mac extraction |
| binary op on mixed | Explicitly cast value of payload key to string inside Zaakpay checksum loop |
| mixed-to-string cast | Pre-checked is_scalar in Paytm getStringByParams to avoid mixed casting exceptions |
