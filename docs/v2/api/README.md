# OwnPay API Reference

Welcome to the official API Reference for **OwnPay**. OwnPay provides developer-friendly REST endpoints to initiate payments, track transactions, manage customer profiles, pair companion mobile devices, and audit double-entry ledger balances.

This documentation is fully structured to align with the **OpenAPI Specification v3.2.0**. The complete OpenAPI specification is located at [`openapi.yaml`](openapi.yaml).

---

## 1. API Architecture & Domains

OwnPay utilizes a white-label domain structure to isolate brand identities:
* **Admin Panel**: Only accessible via the master domain (e.g., `ownpay.test` or your configured `APP_DOMAIN`).
* **Merchant APIs**: Can be queried via the master domain OR the brand's configured custom domain (e.g., `pay.merchantbrand.com`).
* **Public Checkout / Callbacks**: MUST run under the merchant's configured custom domain to prevent exposure of the master brand.

### CORS & Preflight Guidelines (Client-Side Calls)
If you are calling the OwnPay API directly from a browser-based application or a standalone client (such as the official Office API Tester), please note:
> [!IMPORTANT]
> OwnPay's static security filters (`CorsMiddleware`) block credential authentication (`Access-Control-Allow-Credentials: false`) if wildcard (`*`) origins are specified. In staging and production environments, you MUST configure explicit domain origins in your brand system configurations to pass authorized CORS preflight requests safely.

---

## 2. API Groups & Authentication

Authentication is scoped based on the API target group. The credentials must be passed within the HTTP `Authorization` header as a Bearer token.

| API Group | Endpoint Prefix | Auth Mechanism | Example Token |
|-----------|-----------------|----------------|---------------|
| **Merchant API** | `/api/v1/*` | Bearer API Key | `Authorization: Bearer op_abcdefgh.12345678...` |
| **Mobile API** | `/api/mobile/v1/*` | Bearer JWT (Access token) | `Authorization: Bearer eyJhbGciOiJIUzI1NiIs...` |
| **Admin API** | `/api/admin/v1/*` | Bearer API Key (Admin Scope) | `Authorization: Bearer op_abcdefgh.12345678...` |

### A. Getting a Merchant/Admin API Key
1. Navigate to the Admin Dashboard (on the master `APP_DOMAIN`).
2. Go to **Developers → API Keys**.
3. Click **Generate API Key**. The key value will only be visible once.

> [!NOTE]
> **API Key Validation Logic**: All API keys follow the strict `op_[identifier].[secret]` structure. The core `BearerAuthMiddleware.php` validates this format, extracting the first 8 characters of the token after the prefix (`[identifier]`) to search and resolve the brand context in the database. If this structure is broken or malformed, requests fail immediately with a `401 Unauthenticated` status.

### B. Pairing a Companion Mobile Device
To authorize a mobile application companion, use the following flow:
1. In the Admin Dashboard, navigate to **Mobile & SMS → Paired Devices** and click **Generate Pairing Code** (generates a short-lived 6-digit numeric OTP).
2. **OTP Code Validity**: The generated pairing code is only valid for exactly **5 minutes (300 seconds)**.
3. Dispatch a `POST` request with the OTP payload to the collection pairing endpoint `/api/mobile/v1/devices` to establish your session JWT.
   *(Note: The OpenAPI specification and templates may refer to this endpoint as `/api/mobile/v1/devices/pair` or `/api/mobile/v1/devices` depending on client-side routing, but both map internally to the core `pair` action).*

### C. JWT Token Rotation & Lifetimes (Mobile API)
The Mobile API implements strict Token Rotation on the token refreshes endpoint:
* **JWT Access Token Expiry**: Exactly **15 minutes (900 seconds)**.
* **JWT Refresh Token Expiry**: Exactly **30 days (2,592,000 seconds)**.
* To rotate credentials, dispatch your current refresh token to `POST /api/mobile/v1/devices/token-refreshes`.
* A brand new access token and a **new refresh token** are returned. The previous refresh token is instantly blacklisted to prevent replay hijacking.

---

## 3. Global Response & Error Formats

All API responses are returned as JSON. Successful responses contain a `success: true` root key alongside resource nodes.

### Success Format Example (GET `/api/v1/health`)
```json
{
  "success": true,
  "version": "0.1.0",
  "server_time": "2026-05-21T02:40:00+06:00"
}
```

### Error Format Example (422 Unprocessable Entity)
```json
{
  "success": false,
  "error": "Validation failed",
  "errors": [
    {
      "code": "INVALID_AMOUNT",
      "message": "The amount field must be greater than zero.",
      "field": "amount"
    }
  ],
  "request_id": "req_6656c12345678"
}
```

### HTTP Status Codes
* `200 OK` — Request successful.
* `201 Created` — Resource created (e.g., Payment Intent, customer, paired device).
* `400 Bad Request` — Missing or malformed parameters.
* `401 Unauthenticated` — Invalid or missing authorization headers.
* `403 Forbidden` — Access denied due to role restrictions or device deactivation.
* `404 Not Found` — Resource or endpoint does not exist.
* `422 Unprocessable Entity` — Request body failed validation.
* `429 Too Many Requests` — Rate limit exceeded (Default: 60 requests/min).
* `500 Internal Server Error` — Server issue. Error messages do not expose internal database stack traces or hostnames.

---

## 4. Merchant API Integration Recipes

### Recipe A: Initiating a Payment Intent (POST `/api/v1/payments`)
This creates a new payment intent and returns the checkout URL. You should redirect your customer to the `checkout_url`.

#### Payload Specification
* `amount` (float, required): Minimum `0.01`. The transaction total.
* `currency` (string, required): 3-letter ISO 4217 code (e.g. `BDT`, `USD`).
* `description` (string, optional): Max 255 characters description.
* `redirect_url` (string, required): URL redirected to after successful payment.
* `cancel_url` (string, required): URL redirected to if cancelled.
* `metadata` (object, optional): Custom key-value pairs for reconciliation.

#### Bash (cURL)
```bash
curl -X POST https://pay.merchantbrand.com/api/v1/payments \
  -H "Authorization: Bearer op_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 1250.00,
    "currency": "BDT",
    "description": "Premium Subscription - Invoice #5403",
    "redirect_url": "https://merchantbrand.com/payment/success",
    "cancel_url": "https://merchantbrand.com/payment/cancel",
    "metadata": {
      "order_id": "ORD-5403",
      "customer_email": "buyer@example.com"
    }
  }'
```

#### PHP (Guzzle)
```php
<?php
declare(strict_types=1);

require 'vendor/autoload.class.php';

$client = new \GuzzleHttp\Client();
$response = $client->post('https://pay.merchantbrand.com/api/v1/payments', [
    'headers' => [
        'Authorization' => 'Bearer op_your_key_here',
        'Content-Type' => 'application/json',
    ],
    'json' => [
        'amount' => 1250.00,
        'currency' => 'BDT',
        'description' => 'Premium Subscription - Invoice #5403',
        'redirect_url' => 'https://merchantbrand.com/payment/success',
        'cancel_url' => 'https://merchantbrand.com/payment/cancel',
    ]
]);

$data = json_decode($response->getBody()->getContents(), true);
$checkoutUrl = $data['checkout_url'];
header("Location: " . $checkoutUrl);
exit;
```

---

## 5. Webhook Signature Verification

When a payment intent is paid, OwnPay fires an outbound webhook notification to your configured `webhook_url`.

### The Webhook Payload
```json
{
  "event": "payment.completed",
  "transaction_id": "OP-10948294",
  "amount": "1250.00",
  "currency": "BDT",
  "gateway": "bkash",
  "gateway_type": "manual",
  "status": "completed",
  "customer": {
    "name": "Jane Doe",
    "email": "jane@example.com",
    "phone": "+8801700000000"
  },
  "metadata": {
    "order_id": "ORD-5403"
  },
  "timestamp": "2026-05-21T02:45:00+06:00"
}
```

### Signature Headers Sent
* `X-OwnPay-Signature` — HMAC-SHA256 of the raw JSON body using your merchant webhook secret.
* `X-OwnPay-Timestamp` — Unix timestamp of delivery.

### PHP Verification Example
```php
<?php
declare(strict_types=1);

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_OWNPAY_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_OWNPAY_TIMESTAMP'] ?? '';
$webhookSecret = 'your_webhook_secret_here';

// 1. Check for stale requests (re-play attacks)
if (time() - (int)$timestamp > 300) {
    http_response_code(400);
    echo "Expired timestamp";
    exit;
}

// 2. Verify HMAC signature
$expected = hash_hmac('sha256', $payload, $webhookSecret);
if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    echo "Invalid signature";
    exit;
}

// 3. Process webhook event safely
$data = json_decode($payload, true);
if ($data['event'] === 'payment.completed') {
    $orderId = $data['metadata']['order_id'] ?? null;
    // Update order status in your system database...
}

http_response_code(200);
echo "OK";
```

---

## 6. Detailed Endpoint Directory

The API endpoints are organized into four primary functional categories mapping to application route namespaces.

### Category A: Merchant Group (`Merchant`)

#### Health & Diagnostics
* `GET /api/v1/health` — Returns application version, system uptime, and server time.

#### Payment Lifecycle
* `POST /api/v1/payments` — Starts a payment intent lifecycle.
  * *Request Body*: [See Recipe A Specifications](#recipe-a-initiating-a-payment-intent-post-apiv1payments)
* `GET /api/v1/payments/{trx_id}` — Resolves complete status and metadata parameters of a payment intent by its unique transaction ID.

#### Transaction Audit Ledger
* `GET /api/v1/transactions` — Queries paginated settled transaction audit records.
  * *Query Parameters*: `page` (integer, default `1`), `per_page` (integer, default `20`, max `100`), `status` (enum: `pending`, `completed`, `failed`, `cancelled`, `refunded`).
* `GET /api/v1/transactions/{trx_id}` — Retrieves comprehensive financial and ledger data of a specific settled transaction.

#### Refunds & Reversals
* `POST /api/v1/refunds` — Creates a refund against a completed transaction.
  * *Request Body*: `{"transaction_id": "OP-10482938", "amount": 250.00, "reason": "Customer return"}` (with limits up to the total original transaction balance).
* `GET /api/v1/refunds/{trx_id}` — Resolves audit details of a processed refund.

#### Customer Profiles
* `GET /api/v1/customers` — Lists paginated customer profiles registered under the active brand context.
* `POST /api/v1/customers` — Inserts a new customer profile.
  * *Request Body*: `{"name": "Jane Doe", "email": "jane@example.com", "phone": "+8801700000000"}`
* `GET /api/v1/customers/{identifier}` — Retrieves a customer profile by database ID, email address, or phone number.

#### API Credentials Management
* `GET /api/v1/api-keys` — Lists registered API keys with masked security strings.
* `POST /api/v1/api-keys` — Generates a new live or test API key for the active brand.
  * *Request Body*: `{"name": "Production Server Key"}`
* `DELETE /api/v1/api-keys/{id}` — Deactivates and permanently revokes an API credential.

#### Outbound Webhooks & Logs
* `POST /api/v1/webhooks/tests` — Dispatches a test HMAC-SHA256 payload callback to verify endpoint signature validators.
* `GET /api/v1/webhooks/deliveries` — Returns a log history of outbound webhooks dispatched, including receiver HTTP status responses.

---

### Category B: Mobile Group (`Mobile`)

All Mobile companion APIs require Bearer JWT authorization generated during pairing.

#### Mobile Devices & Sessions
* `POST /api/mobile/v1/devices` — Pairs a companion device using the generated 6-digit admin OTP.
  * *Request Body*: `{"pairing_code": "123456", "device_name": "Pixel 8", "device_id": "uuid-string", "app_version": "1.0.0", "platform": "android"}`
* `POST /api/mobile/v1/devices/heartbeats` — Submits a quick ping to update connection status.
* `DELETE /api/mobile/v1/devices/{id}` — Self-revokes the active device session.
* `POST /api/mobile/v1/devices/bulk-revocations` — Revokes multiple registered devices concurrently.
  * *Request Body*: `{"ids": [12, 13]}`
* `POST /api/mobile/v1/devices/token-refreshes` — Exchange a valid refresh token for rotated credentials.
  * *Request Body*: `{"refresh_token": "eyJhbGciOiJIUzI1NiIs..."}`
* `GET /api/mobile/v1/devices/statuses` — Check JWT session validity and resolve active brand metadata.

#### SMS Processing Logs
* `POST /api/mobile/v1/sms` — Accepts forwarded banking/gateway notification SMS. Supports single payload or batch arrays of SMS logs.
  * *Request Body*: `{"sender": "bKash", "body": "You have received TK 500...", "received_at": "2026-05-21T02:40:00+06:00"}`
* `GET /api/mobile/v1/sms/queues` — Lists raw SMS texts waiting in the regex parser analyzer queue.

#### Push Notifications
* `GET /api/mobile/v1/notifications` — Lists unacknowledged push notification tasks targeting the active companion device.
* `POST /api/mobile/v1/notifications/acknowledgements` — Marks specified push notification IDs as completed.
  * *Request Body*: `{"ids": [1402, 1403]}`

#### Mobile Panels
* `GET /api/mobile/v1/dashboard` — Returns aggregated todays transaction statistics for companion displays.
* `GET /api/mobile/v1/config/filter-rules` — Returns whitelist senders, regex boundaries, and privacy parameters for dynamic device filters.

---

### Category C: Admin Group (`Admin`)

These endpoints require Bearer API key authorization with admin permissions.

#### Regex Templates
* `GET /api/admin/v1/sms-templates` — Lists regex match templates matching financial transaction notification strings.
* `PUT /api/admin/v1/sms-templates/{id}` — Updates pattern rules or status configurations of a specific SMS template.
  * *Request Body*: `{"regex_pattern": "TK (\\d+) from (\\d+)", "status": "active"}`

#### Global SMS Auditing
* `GET /api/admin/v1/sms-queues` — Lists all processed, matched, or failed SMS forwards system-wide.
* `POST /api/admin/v1/sms-queues/{id}/retries` — Reruns regex pattern match against a stored SMS text manually.

#### Device Control
* `GET /api/admin/v1/devices` — Lists all registered devices system-wide.
* `DELETE /api/admin/v1/devices/{id}` — Revokes active device sessions.

#### Custom Domains
* `POST /api/admin/v1/domains/verifications` — Triggers DNS mapping status verification scans.

---

### Category D: Global Security & Webhooks

* **CSP Report Listener**:
  * `POST /csp-report-api` — Global listener accepting Content Security Policy violation reports. No authorization required.
  * *Request Body*: Content Security Policy JSON payload (`application/csp-report`).
