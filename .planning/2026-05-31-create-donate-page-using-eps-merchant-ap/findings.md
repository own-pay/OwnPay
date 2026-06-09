# Discovery & Findings

## EPS Payment Gateway API Guidelines
Based on the `docs\EPSmerchantapiguideline.pdf` analysis:
- **GetToken API**:
  - Endpoint: `https://pgapi.eps.com.bd/v1/Auth/GetToken`
  - Method: `POST`
  - Headers:
    - `Content-Type: application/json`
    - `x-hash`: HMAC-SHA512 of `userName` signed using `hashKey` (raw base64 string, not decoded), then base64 encoded.
  - Body: `{"userName": "<username>", "password": "<password>"}`
- **InitializePayment API**:
  - Endpoint: `https://pgapi.eps.com.bd/v1/EPSEngine/InitializeEPS`
  - Method: `POST`
  - Headers:
    - `Content-Type: application/json`
    - `Authorization`: `Bearer <Token>`
    - `x-hash`: HMAC-SHA512 of `merchantTransactionId` signed using `hashKey`, then base64 encoded.
  - Body (JSON): Contains storeId, CustomerOrderId, merchantTransactionId, transactionTypeId (1 for Web), totalAmount, successUrl, failUrl, cancelUrl, customerName, customerEmail, CustomerAddress, CustomerCity, CustomerPostcode, CustomerCountry, CustomerPhone, ipAddress, ProductName, NoOfItem, ShippingMethod, ProductProfile, ProductCategory, ProductList.
- **CheckMerchantTransactionStatus API**:
  - Endpoint: `https://pgapi.eps.com.bd/v1/EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId=<MTID>`
  - Method: `GET`
  - Headers:
    - `Authorization`: `Bearer <Token>`
    - `x-hash`: HMAC-SHA512 of `merchantTransactionId` signed using `hashKey`, then base64 encoded.

## Verification of Hash BKXUD
The PDF provides an example userName and HashKey:
- `userName` / `dt_merchant@eps.com.bd`
- `HashKey` / `SFNLQHJlY2lwZXdhbGEjYTc3Zi1mOTQ5NWZhY2M2ZTZuZXQ=`
- HMAC-SHA512 output / `BKXUD5z0NQgPDQMcZuPL2dSUwo5oSdvBpzz2xbkxikB7KfYV0kZIF8sW6udvSqOTZNUJ5VHnMTSJP3oxDABpJQ==`
We verified via Python that using the key as raw bytes `SFNLQHJlY2lwZXdhbGEjYTc3Zi1mOTQ5NWZhY2M2ZTZuZXQ=` and `dt_merchant@eps.com.bd` as message produces exactly this signature.

## Existing EPS Implementation
An existing implementation exists in `modules/gateways/eps/EpsGateway.php`. It fully implements the `getToken`, `initiate`, and `verify` routines using cURL. We can extract and reuse these functions in a standalone format inside `donate.php` to avoid external dependencies.

## Target Structure
File: `public_html/public_html/donate.php`
- Self-contained PHP script.
- Handles multiple views based on URL parameters:
  - **Form View**: Standard donation page with premium CSS styling. Light theme inspired by premium SaaS landing pages and GitHub's sleek interface.
  - **Checkout Processing**: Initiates payment using EPS API and redirects.
  - **Callback Handling**: Receives the gateway redirects (`successUrl`/`failUrl`/`cancelUrl`), verifies the status using the status check API, and updates states.
  - **Thank You Page**: Appears upon successful payment. Shows a custom form to write a donation message.
  - **Message Processing**: Saves the message, amount, name, date, and user IP to `public_html/public_html/donations.json`.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
-
