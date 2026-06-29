# Findings & Decisions - Batch 5

## Requirements

- Develop, integrate, and validate 6 production-ready payment gateway adapters for the Global Giants (BNPL, Direct Debit, & Wallets) ecosystem:
  1. **Amazon Pay** (slug: `amazon-pay`)
  2. **GoCardless** (slug: `gocardless`)
  3. **Affirm** (slug: `affirm`)
  4. **Afterpay (Clearpay)** (slug: `afterpay`)
  5. **Sezzle** (slug: `sezzle`)
  6. **BitPay** (slug: `bitpay`)
- **Core Constraints & Hardening:**
  1. Strict typing (`declare(strict_types=1);` as the first statement, no BOM/whitespace).
  2. High-precision financial math using PHP `BCMath` for subunits/cents conversions (e.g. `bcmul()` and `bcdiv()`).
  3. Strict sandbox simulation isolation: Throw `\RuntimeException` if simulation transaction IDs (like `SIM_`) or sandbox/test keys are used in `live` production mode.
  4. Dynamic CSP configuration mapped in `manifest.json`.
  5. Native signature calculations (HMAC-SHA256, HMAC-SHA512, SHA256) instead of heavy SDK dependencies.
  6. 100% PHPStan Level 9 and PHPUnit compliance.

## Research Findings

- **Amazon Pay Checkout v2:**
  - Standard endpoint: `POST /v2/checkoutSessions`
  - Mode: `live` vs `test` (endpoints `https://pay-api.amazon.com` / `https://pay-api.amazon.eu` and sandbox equivalents).
  - Subunit: Convert amount using `bcmul($amount, "100", 0)`.
  - Webhook: Verifies header `X-Amz-Pay-Signature` matching SHA256withRSA. For test mocks, we will implement a robust helper.
- **GoCardless Billing Requests:**
  - Base URLs: `https://api.gocardless.com` / `https://api-sandbox.gocardless.com`
  - Subunit: `bcmul($amount, "100", 0)`.
  - Webhook: Verifies header `Webhook-Signature` matching `hash_hmac('sha256', $rawBody, $webhookSecret)`.
- **Affirm Direct Checkout v2:**
  - Base URLs: `https://api.affirm.com` / `https://sandbox.affirm.com`
  - Subunit: `bcmul($amount, "100", 0)`.
  - Webhook: Verify callback data or process signature checks via API transactions capture.
- **Afterpay (Clearpay) Checkout V2:**
  - Base URLs: `https://global-api.afterpay.com` / `https://global-api-sandbox.afterpay.com`
  - Subunit: `bcmul($amount, "100", 0)`.
  - Webhook: Verify via basic authentication callback or API lookup `GET /v2/payments/{id}`.
- **Sezzle Session V2:**
  - Base URLs: `https://gateway.sezzle.com` / `https://sandbox.gateway.sezzle.com`
  - Subunit: `bcmul($amount, "100", 0)`.
  - Webhook: Verifies header `X-Sezzle-Signature` matching `hash_hmac('sha256', $rawBody, $privateKey)`.
- **BitPay Invoices V2:**
  - Base URLs: `https://bitpay.com` / `https://test.bitpay.com`
  - Subunit: BitPay supports decimal amounts (like BTC or USD) directly as strings. We will format with `bcmul($amount, "1", 2)` to ensure exact 2 decimal places.
  - Webhook: Verify IPN payload by matching POS tokens or executing a secure backchannel status fetch.

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| No external SDKs | We implement pure curl/openssl endpoints to keep the plugins lightweight, self-contained, and highly secure. |
| BCMath everywhere | Every currency conversion strictly uses string-based `bcmul` to prevent float precision bugs. |
| Simulation Isolation | Active rejection of sandbox keys or `SIM_` transactions when `mode === 'live'` to comply with auditing specs. |

## Issues Encountered

| Issue | Resolution |
|-------|------------|
| None | N/A |

## Resources

- PHP BCMath Documentation
- OpenSSL PHP Documentation
