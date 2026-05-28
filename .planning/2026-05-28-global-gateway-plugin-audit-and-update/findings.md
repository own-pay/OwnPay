# Findings & Decisions: Global Gateway Plugin Audit

## Requirements
- Audit all 97 gateway plugins in `modules/gateways/` to ensure they match the latest 2026 developer documentation.
- Retrieve latest specifications using `ctx7` as the primary tool and internet search as a fallback.
- Identify deprecated endpoints, legacy webhook configurations, or query parameter mismatches.
- Refactor outdated code while preserving external interfaces, `BCMath` precision, strict typing, and container injection.

## Research Findings
An automated scan of the `modules/gateways/` directory has established a baseline code inventory for all 97 payment gateways:
- **Total active/inactive plugins**: 97
- **HMAC/SHA Webhook Verification**: 8 gateways (`authorize-net`, `coinbase-commerce`, `flutterwave`, `oxapay`, `paystack`, `paytabs`, `razorpay`, `xendit`)
- **Direct/Query API Callback Verification**: 88 gateways (`adyen`, `alipay`, `apple-pay`, `google-pay`, etc.)
- **Off-site/Sandbox Redirect/No HTTP Calls**: 1 gateway (`btcpay`)

### Batching & Documentation Search Plan
To remain within Context7 API limits and the 3-command CLI constraint per turn, we will batch our documentation retrieval:
* **Batch 1 (Global & Large Aggregators)**: Stripe, PayPal, Adyen, Braintree, Paddle
* **Batch 2 (South Asia & Local MFS)**: Razorpay, bKash, Nagad, SSLCommerz, Rocket
* **Batch 3 (Southeast Asia & LatAm)**: GCash, OVO, DANA, Mercado Pago, Paystack, Flutterwave
* **Batch 4 (Europe & East Asia)**: Klarna, Mollie, Przelewy24, BLIK, Sofort, Trustly, Pix

We will cross-examine these gateways using `ctx7` (for key packages) and the local integration handbooks (as a baseline for all 97 plugins).

## Technical Decisions
| Decision | Rationale |
|----------|-----------|

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [OwnPay Gateway Integration Handbooks](file:///c:/laragon/www/ownpay/docs/v2/plugins/gateways/)

