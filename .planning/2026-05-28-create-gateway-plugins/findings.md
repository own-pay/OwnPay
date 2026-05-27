# Findings & Decisions

## Requirements
- 10 gateways implemented/refactored: PayTabs, Fawry, Midtrans, Xendit, Ebanx, Kushki, Payfast, Paddle, Braintree, Authorize.Net.
- Production-ready, robust PHP 8.2+ code, no stubs.
- 100% working, secure signatures, correct status checks, proper namespaces under `OwnPay\Modules\Gateways\<Slug>`.
- Auto-escaping, strict type declarations, and parameters binding for SQL/tenant services.

## Research Findings

### 1. PayTabs
- **Category:** Middle East / Aggregator
- **Endpoints:** Sandbox & Live: `https://secure-global.paytabs.com/payment/request`
- **Auth:** `Authorization: <Server Key>`
- **Fields:** `profile_id`, `server_key`, `region` (e.g. global, egypt, ksa)
- **Initiate:** POST to `/payment/request` with `profile_id`, `tran_type` (sale), `tran_class` (ecom), `cart_id`, `cart_currency`, `cart_amount`, `callback`, `return`. Returns `redirect_url` and `tran_ref`.
- **Verify:** POST to `/payment/query` with `profile_id`, `tran_ref`.
- **Webhook Verification:** Signature header is `signature`, verified using server key.

### 2. Fawry
- **Category:** Egypt / MENA
- **Endpoints:** Sandbox: `https://atfawry.fawryreadypay.com/`, Live: `https://www.fawrypay.com/`
- **Auth:** Signature based.
- **Fields:** `merchant_code`, `security_key`
- **Initiate:** Redirect or POST with `merchantCode`, `merchantRefNum`, `customerProfileId`, `chargeItems`, `signature`. Signature is SHA256 of `merchantCode + merchantRefNum + customerProfileId + returnUrl + chargeItemCode + chargeItemQty + chargeItemPrice + expiryDate + securityKey`.
- **Webhook Verification:** Verifies webhook payload SHA256 signature using `securityKey`.

### 3. Midtrans
- **Category:** Southeast Asia / Indonesia
- **Endpoints:** Sandbox: `https://app.sandbox.midtrans.com/snap/v1/transactions`, Live: `https://app.midtrans.com/snap/v1/transactions`
- **Auth:** Basic Auth: `Base64(server_key + :)`
- **Fields:** `server_key`, `client_key`
- **Initiate:** POST with `transaction_details` (order_id, gross_amount). Returns `redirect_url` and `token`.
- **Webhook Verification:** Verify SHA512 signature: `order_id + status_code + gross_amount + server_key` matching the `signature_key` header/body.

### 4. Xendit
- **Category:** Southeast Asia / Philippines / Indonesia
- **Endpoints:** `https://api.xendit.co/v2/invoices`
- **Auth:** Basic Auth: `Base64(api_key + :)`
- **Fields:** `api_key`, `callback_token`
- **Initiate:** POST with `external_id`, `amount`, `payer_email`, `description`, `success_redirect_url`. Returns `invoice_url`, `id`.
- **Webhook Verification:** Compares the webhook body `x-callback-token` header value with configured `callback_token`.

### 5. Ebanx
- **Category:** Latin America
- **Endpoints:** Sandbox: `https://sandbox.ebanxpay.com/ws/request`, Live: `https://api.ebanxpay.com/ws/request`
- **Auth:** `integration_key` parameter in payload.
- **Fields:** `integration_key`
- **Initiate:** POST with `integration_key`, `operation=request`, `payment` containing `amount`, `currency`, `merchant_payment_code`, `name`, `email`, `back_url`. Returns `redirect_url`, `hash`.
- **Webhook Verification:** Queries `/ws/query` with transaction hash to verify status.

### 6. Kushki
- **Category:** Latin America
- **Endpoints:** Sandbox: `https://sandbox-api.kushkipagos.com/`, Live: `https://api.kushkipagos.com/`
- **Auth:** Header `Private-Merchant-Id` / `Public-Merchant-Id`.
- **Fields:** `private_merchant_id`, `public_merchant_id`
- **Initiate:** Creates a payment session using hosted checkout URL or POST `/transfer/v1/init`. We will implement Hosted Checkout Session creation: POST `/checkout/v1/sessions` with amount, currency, redirectUrl. Returns `redirectUrl`.
- **Webhook Verification:** Validate Kushki signatures.

### 7. Payfast
- **Category:** South Africa
- **Endpoints:** Sandbox: `https://sandbox.payfast.co.za/eng/process`, Live: `https://www.payfast.co.za/eng/process`
- **Auth:** Signature based.
- **Fields:** `merchant_id`, `merchant_key`, `passphrase`
- **Initiate:** POST form data with `merchant_id`, `merchant_key`, `amount`, `item_name`, `m_payment_id`, `return_url`, `cancel_url`, `notify_url`, `signature`. Signature is MD5/SHA512.
- **Webhook Verification:** Webhook (ITN) verify payload signature, then query Payfast endpoint to verify validity.

### 8. Paddle
- **Category:** Global / Merchant of Record
- **Endpoints:** Sandbox: `https://sandbox-api.paddle.com`, Live: `https://api.paddle.com`
- **Auth:** `Authorization: Bearer <API Key>`
- **Fields:** `api_key`, `webhook_secret`
- **Initiate:** Create a transaction: POST `/transactions` with price/item or custom transaction amount. Returns checkout/redirect URL.
- **Webhook Verification:** Verifies signature in header `Paddle-Signature` (HMAC-SHA256).

### 9. Braintree
- **Category:** Global
- **Endpoints:** Sandbox: `https://api.sandbox.braintreegateway.com/merchants/{merchant_id}/`, Live: `https://api.braintreegateway.com/merchants/{merchant_id}/`
- **Auth:** Basic Auth: `Base64(public_key + : + private_key)`
- **Fields:** `merchant_id`, `public_key`, `private_key`
- **Initiate:** Post XML transaction request to `/transactions`.
- **Webhook Verification:** Verifies Braintree webhook signature using SHA1/HMAC.

### 10. Authorize.Net
- **Category:** Global / North America
- **Endpoints:** Sandbox: `https://apitest.authorize.net/xml/v1/request.api`, Live: `https://api.authorize.net/xml/v1/request.api`
- **Auth:** `loginId` and `transactionKey` in payload JSON.
- **Fields:** `login_id`, `transaction_key`, `signature_key`
- **Initiate:** Post `getHostedPaymentPageRequest` to API. Returns a hosted checkout token. Customer is redirected with form to Hosted Payment Page.
- **Webhook Verification:** Verify SHA512 signature using `signature_key`.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Use Hosted Checkouts | Standardizes security, complies with PCI-DSS, avoids merchant handling card data, fits capabilities of current framework. |
| Use cURL backchannel | Ensures robust verification, bypasses client-side tampering, aligns with GatewayAdapterInterface verify step. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- Gateway Developer Guides (PayTabs, Fawry, Midtrans, Xendit, Ebanx, Kushki, Payfast, Paddle, Braintree, Authorize.Net)
- OwnPay Handbook Volumes 1-6

