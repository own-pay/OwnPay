# Findings & Decisions

## Requirements
- Develop, integrate, and validate 5 production-ready payment gateway adapters for the Africa & Middle East (MENA) Mobile Financial Services (MFS) and digital wallets ecosystem:
  1. **MTN Mobile Money (MoMo)** (slug: `mtn-momo`)
  2. **Orange Money** (slug: `orange-money`)
  3. **OPay** (slug: `opay`)
  4. **MyFatoorah** (slug: `myfatoorah`)
  5. **Tap Payments** (slug: `tap-payments`)
- **Core Constraints & Hardening:**
  1. Strict typing (`declare(strict_types=1);` as the first statement, no BOM/whitespace).
  2. High-precision financial math using PHP `BCMath` for subunits/cents conversions (e.g. `bcmul()` and `bcdiv()`).
  3. Strict sandbox simulation isolation: Throw exception or return failed validation if simulation transaction IDs (like `SIM_`) or UAT credentials are used in `live` production mode.
  4. Dynamic CSP configuration.
  5. Native signature calculations (HMAC-SHA256, HMAC-SHA512, SHA256) instead of heavy SDK dependencies.
  6. MTN MoMo webhook spoofing protection: Treat callback purely as a notification to trigger a secure backchannel server-to-server checkback status lookup.
  7. 100% PHPStan Level 9 and PHPUnit compliance.

## Research Findings

### 1. MTN Mobile Money (MoMo)
- **Initiate Endpoint:** `POST /collection/v1_0/requesttopay`
- **Check Status Endpoint:** `GET /collection/v1_0/requesttopay/{X-Reference-Id}`
- **Authentication:** OAuth2 Client Credentials Basic Auth `base64(api_user_id:api_key)` at `POST /collection/token/`.
- **Headers:** `Ocp-Apim-Subscription-Key`, `X-Target-Environment` (`sandbox` or `live`), `X-Reference-Id` (UUID v4).
- **Callback Security:** Unsigned callback. We treat webhook callbacks solely as notifications to trigger the server-to-server `GET /collection/v1_0/requesttopay/{id}` checkback lookup.

### 2. Orange Money (Web Payment)
- **Token Endpoint:** `POST /oauth/v2/token` via Basic Auth `base64(client_id:client_secret)` with body `grant_type=client_credentials`.
- **Initiate Endpoint:** `POST /orange-money-webpay/dev/v1/webpayment` (Sandbox) / `/orange-money-webpay/{country}/v1/webpayment` (Live).
- **Status Endpoint:** `POST /orange-money-webpay/dev/v1/transactionstatus` (Sandbox) with body `{"pay_token":"..."}`.
- **Statuses:** `SUCCESS`, `FAILED`, `PENDING`, `INITIATED`, `EXPIRED`.

### 3. OPay Cashier Checkout
- **Initiate Endpoint:** `POST /api/v1/international/cashier/create`
- **Headers:** `Authorization: Bearer <public_key>`, `MerchantId: <merchant_id>`.
- **Payload:** `{"country":"NG", "reference":"trx_id", "amount":{"total":cents_int}, "returnUrl":"...", "cancelUrl":"..."}`.
- **Webhook Signature:** Calculated over raw request body using HMAC-SHA512 with the merchant's secret key. Header `X-OPay-Signature` or `Signature`.

### 4. MyFatoorah V2
- **Initiate Endpoint:** `POST /v2/SendPayment`
- **Status Endpoint:** `POST /v2/GetPaymentStatus` with body `{"Key": "payment_id", "KeyType": "PaymentId"}`.
- **Webhook Verification:** Header `MyFatoorah-Signature` representing base64-encoded binary HMAC-SHA256 of the raw body or concatenated data string (alphabetically sorted).

### 5. Tap Payments (goSell Charges v2)
- **Initiate Endpoint:** `POST /v2/charges`
- **Status Endpoint:** `GET /v2/charges/{charge_id}`
- **Source:** Use `src_all` to trigger the hosted checkout page.
- **Webhook Signature:** Header `X-Tap-Sign` representing HMAC-SHA256 of the raw request body with the webhook shared secret.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| **Independent `tap-payments` slug** | Avoids namespace and configuration conflicts with the existing Trust Axiata Pay (`tap`) gateway from Bangladesh. |
| **No heavy SDKs** | We will implement cURL manually with custom headers for all gateways to ensure lightweight, zero-dependency, self-contained module logic. |
| **BCMath casting** | Explicit string-casting (e.g. `(string) (float) $params['amount']`) before `bcmul` ensures floating-point values do not undergo IEEE 754 precision loss during cents formatting. |
| **Strict SIM_ rejection** | Rejects `SIM_` prefixes in transaction/session IDs or sandbox keys when mode is `live` to prevent environment bypass. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| N/A | Initial plan stage |

## Resources
- [MTN MoMo API Portal](https://momodeveloper.mtn.com/)
- [Orange Developer Portal](https://developer.orange.com/)
- [OPay Checkout Documentation](https://www.opaycheckout.com/)
- [MyFatoorah Developer Portal](https://docs.myfatoorah.com/)
- [Tap Payments goSell API Reference](https://api.tap.company/)
