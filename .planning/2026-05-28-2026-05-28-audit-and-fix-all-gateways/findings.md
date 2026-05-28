# Findings & Decisions: Gateway Plugin Audit and Hardening

## Requirements
- Audit all gateway plugins in `modules/gateways/` to verify if they are fully functional and ready to accept payments.
- If any stubs or bypasses exist, refactor them to be secure and production-ready.
- The entire test suite must remain green (405 tests passing).
- Codebase must remain 100% PHPStan Level 9 clean.

## Research Findings
An initial scan identified 8 potential stubs out of 97 gateways.
Two of the flagged files are false positives and are already fully functional:
- `ccavenue`: Uses cryptographically secure AES-128-CBC request encryption and response decryption.
- `rocket`: Uses MD5 secure hash generation and verification.

Six gateways require hardening:
1. **Alipay**: The `verify()` method is currently a mock that bypasses signature checks. We need to implement proper RSA2 signature verification using `openssl_verify` and the configured Alipay public key.
2. **Easypaisa**: The `initiate()` and `verify()` methods lack signature hash generation and verification. We need to implement HMAC-SHA256 signature checks on sorted parameters using the Hash Key.
3. **JazzCash**: The `verify()` method currently accepts payments without checking the secure hash. We need to implement HMAC-SHA256 secure hash verification to match `initiate()`.
4. **Binance Personal**: Exists as a mockup button redirect. We will add a `bscscan_api_key` configuration field, display a form asking the customer for their Transaction Hash (`txhash`), and query the BscScan API to verify that the transaction succeeded, was sent to the configured `wallet_address`, and matches the expected amount.
5. **Apple Pay**: Exists as a mockup simulator. We will upgrade it to use the Stripe Checkout Sessions API to perform real wallet/card acquisitions securely.
6. **Google Pay**: Same as Apple Pay, we will upgrade it to run real charges using the Stripe Checkout Sessions API.
7. **Simulation Mode Live Bypasses**: 14 additional gateways (Authorize.Net, BLIK, Braintree, Ebanx, Fawry, Giropay, Kushki, Paddle, Payfast, PayTabs, Przelewy24, Sofort, Trustly, Xendit) had `verify()` or `initiate()` mock simulation fallback structures that allowed `SIM_` or empty values to bypass real verification in live mode. We patched them to explicitly verify that the mode is not `live` before permitting mock/simulation fallbacks, throwing a `\RuntimeException` on payment initiation failures and returning a failed transaction status on payment callback/verification checks.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Use Stripe Checkout for Apple/Google Pay | Apple Pay and Google Pay require a token acquirer/processor. Stripe is the industry standard and already has a robust adapter in this codebase. |
| Use BscScan Proxy API for Binance Personal | Allows programmatic verification of raw blockchain transactions, verifying destination address and transaction success status on the Binance Smart Chain. |
| Implement OpenSSL RSA2 for Alipay | Alipay Global utilizes RSA2 (SHA256 with RSA) signatures. Direct verification using `openssl_verify` guarantees security and authenticity. |
| Implement parameter sorting and HMAC-SHA256 for Easypaisa & JazzCash | Enforces merchant security and prevents fake checkout responses. |
| Enable dynamic payment methods on Stripe integrations | Stripe developer guide recommends omitting payment_method_types to support dynamic payment methods managed from the Stripe Dashboard, enabling seamless payment flow and support for wallets like Apple/Google Pay. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [Alipay openapi.alipay.com](https://openapi.alipay.com/gateway.do)
- [BscScan API docs](https://docs.bscscan.com)
- [Stripe Checkout Session API](https://stripe.com/docs/api/checkout/sessions)
