# Task Plan: Audit and Hardening of Gateway Plugins

## Goal
Audit all 97 payment gateway plugins, identify any mock/stub verification bypasses, and refactor the 6 flagged plugins (Alipay, Easypaisa, JazzCash, Binance Personal, Apple Pay, Google Pay) to be fully functional, cryptographically secure, and production-ready.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Discovery & Setup
- [x] Scan codebase and identify all stubs/placeholders
- [x] Confirm False Positives (CCAvenue, Rocket)
- [x] Establish planning files
- **Status:** complete

### Phase 2: Implementation & Refactoring
- [x] Refactor AlipayGateway to implement RSA2 signature verification
- [x] Refactor JazzCashGateway to implement HMAC-SHA256 signature verification in verify()
- [x] Refactor EasypaisaGateway to implement HMAC-SHA256 signature check in initiate() and verify()
- [x] Refactor BinancePersonalGateway to add BscScan API validation of TxHash
- [x] Refactor ApplePayGateway to use Stripe Checkout Sessions for card/wallet acquisition
- [x] Refactor GooglePayGateway to use Stripe Checkout Sessions for card/wallet acquisition
- [x] Identify and patch 14 additional payment gateways (Authorize.Net, BLIK, Braintree, Ebanx, Fawry, Giropay, Kushki, Paddle, Payfast, PayTabs, Przelewy24, Sofort, Trustly, Xendit) containing simulation/mock bypasses in live mode
- [x] Align Stripe, Apple Pay, and Google Pay integrations with the latest Stripe developer guide to support dynamic payment methods (removing payment_method_types)
- **Status:** complete

### Phase 3: Testing & Verification
- [x] Verify plugin loadability using check script
- [x] Run PHPUnit test suite to ensure no regressions (405 tests green)
- [x] Run PHPStan Level 9 to ensure type safety
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Standardize Apple Pay & Google Pay on Stripe | Digital wallets require an acquirer. Stripe Checkout Sessions provide Apple/Google Pay buttons natively and securely. |
| Use BscScan API for Binance Personal | Enables secure verification of BSC/BEP20 transactions by transaction hash. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
