# Findings & Decisions - Batch 2 India MFS, UPI & Aggregators

## Requirements

- Fully functional, production-ready, highly secure payment gateway adapters for:
  1. **Cashfree**: Uses PG API v2023-08-01. Returns `payment_session_id` and redirects to hosted checkout. Uses HMAC-SHA256 of `$timestamp . $rawBody` to verify webhooks.
  2. **Paytm**: Redirection flow via Initiate Transaction API returning `txnToken`. Redirects via HTML POST form to `showPaymentPage`. Secure AES-128-CBC checksum generation and validation natively implemented using OpenSSL.
  3. **PayU India**: Hosted checkout redirect. Computes signature hash `key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||SALT`. Callback reverse hash check: `SALT|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key` (or with `additionalCharges` prefix).
  4. **Instamojo**: OAuth2 authorization, POST payment requests to `/v2/payment-requests/`, retrieves `longurl` for hosted checkout. Webhook verification via case-insensitive `ksort()` alphabetical key-value sorted pipe `|` concatenation and HMAC-SHA1.
  5. **MobiKwik (Zaakpay)**: Web hosted checkout posting fields sorted alphabetically via `ksort()`, concatenating values directly (or as key=value pairs ending with secret key), hashed via HMAC-SHA256.

- All adapters must enforce:
  - Strict type checking (`declare(strict_types=1);` at top).
  - Clean container instantiation and PSR-11 injection support.
  - High-precision BCMath math for subunits (INR uses 2 decimal places, cents = amount * 100).
  - Sandbox live isolation: prevent simulation inputs in `live` production mode.
  - Perfect type-safe return contracts.

## Research Findings

### 1. Cashfree

- **Base URLs**:
  - Sandbox: `https://sandbox.cashfree.com/pg`
  - Live: `https://api.cashfree.com/pg`
- **Hosted Checkout Redirect**:
  - Sandbox: `https://payments-test.cashfree.com/order/{payment_session_id}`
  - Live: `https://payments.cashfree.com/order/{payment_session_id}`
- **Signature Calculation**:
  - Webhook header: `x-webhook-signature` and `x-webhook-timestamp`.
  - Signature body: `$timestamp . $rawBody`.
  - HMAC key: `client_secret`.
  - Base64 encoded hash: `base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $clientSecret, true))`.

### 2. Paytm

- **Base URLs**:
  - Sandbox: `https://securegw-stage.paytm.in`
  - Live: `https://securegw.paytm.in`
- **Show Payment Page**:
  - Staging: `https://securestage.paytmpayments.com/theia/api/v1/showPaymentPage`
  - Live: `https://securegw.paytm.in/theia/api/v1/showPaymentPage`
- **AES-128-CBC Checksum**:
  - IV: `@@@@&&&&####$$$$`.
  - Hash string: `$params . "|" . $salt`.
  - Encrypted signature: `openssl_encrypt($hashString, "AES-128-CBC", $key, 0, self::$iv)`.
  - Verification: Decrypt signature, split into SHA-256 hash (first 64 chars) and salt (remainder), and verify.

### 3. PayU India

- **Base URLs**:
  - Sandbox: `https://test.payu.in/_payment`
  - Live: `https://secure.payu.in/_payment`
- **Signature Hash**:
  - Sequence: `key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||salt`
- **Callback Reverse Hash**:
  - With additional charges: `additionalCharges|salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key`
  - Standard: `salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key`

### 4. Instamojo

- **Base URLs**:
  - Sandbox: `https://test.instamojo.com`
  - Live: `https://api.instamojo.com`
- **Webhook MAC Verification**:
  - Sort parameters alphabetically by key case-insensitive using `ksort($data, SORT_STRING | SORT_FLAG_CASE)`.
  - Join values using pipe: `implode('|', $data)`.
  - Sign with HMAC-SHA1 using secret salt: `hash_hmac('sha1', $message, $salt)`.

### 5. MobiKwik (Zaakpay)

- **Base URLs**:
  - Sandbox: `https://zaakstaging.zaakpay.com/api/paymentTransact/v8`
  - Live: `https://api.zaakpay.com/api/paymentTransact/v8`
- **Checksum algorithm**:
  - Parameters sorted alphabetically by key using `ksort()`.
  - Joined as query parameters `key1=value1&key2=value2&...&secret=SECRET_KEY`.
  - Hash generated: `hash('sha256', $all)`.

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Dependency-free Paytm | Avoid requiring new Composer packages by writing standard PHP OpenSSL AES-128-CBC routines inside PaytmGateway. |
| BCMath Subunits | Cast amounts to string, multiply by 100 using `bcmul($amount, '100', 0)` to securely convert to cents. |
| Sandbox Isolation | Block simulation flags/credentials (e.g. `SIM_`, `TEST_` keys) in `live` production mode. |

## Issues Encountered

| Issue | Resolution |
|-------|------------|

## Resources

- Cashfree API: `https://www.cashfree.com/docs/api-reference/`
- PayU India: `https://docs.payu.in/`
- Instamojo: `https://docs.instamojo.com/`
- Paytm: `https://developer.paytm.com/docs/`
