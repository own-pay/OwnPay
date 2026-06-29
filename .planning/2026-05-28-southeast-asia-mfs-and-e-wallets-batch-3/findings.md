# Findings & Decisions - Batch 3 Southeast Asia MFS & E-Wallets

## Requirements

- Fully functional, production-ready, highly secure payment gateway adapters for:
  1. **ShopeePay**: Integrated via Omise's hosted payment source redirect.
  2. **Touch 'n Go eWallet**: Integrated via Stripe's certified `touch_n_go` PaymentIntent channel.
  3. **Billplz**: Integrated via REST API v3 bills creation and callback `X-Signature` HMAC-SHA256 verification.
  4. **MoMo**: Integrated via REST API v2 captureWallet and IPN parameter sorted signature checking using HMAC-SHA256.
  5. **TrueMoney**: Integrated via Omise's hosted payment source redirect using `truemoney` channel.

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Stripe for Touch 'n Go | Stripe provides certified, extremely stable Touch 'n Go API integrations which guarantees instant merchant compliance and payment success. |
| Omise for ShopeePay & TrueMoney | Omise is the primary ShopeePay/TrueMoney acquirer in Southeast Asia, ensuring robust hosted redirect source options. |
| VND 1:1 Subunit Math | Vietnamese Dong does not support decimal subunits. bcmul with '1' and 0 decimal places ensures integer-only amounts. |

## Webhook Signature Verification Specifications

### 1. Billplz

- Webhook signature key: `x_signature` header check.
- Verification signature formula: `hash_hmac('sha256', $rawBody, $signatureKey)`.

### 2. MoMo

- Webhook signature parameter: `signature` field check.
- Verification signature formula: sort payload parameters alphabetically using `ksort()`, concatenate as `key=value&...`, and hash using `hash_hmac('sha256', $rawHash, $secretKey)`.
