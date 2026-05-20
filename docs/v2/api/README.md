# OwnPay API Reference

Welcome to the official API Reference for **OwnPay**. OwnPay provides developer-friendly REST endpoints to initiate payments, track transactions, manage customer profiles, pair companion mobile devices, and audit double-entry ledger balances.

This documentation is designed to be easily imported into Readme.io, Swagger UI, or Postman. The full OpenAPI v3 specification is located at [`openapi.yaml`](file:///c:/laragon/www/ownpay/docs/v2/api/openapi.yaml).

---

## 1. API Architecture & Domains

OwnPay utilizes a white-label domain structure:
* **Admin Panel**: Only accessible via the master domain (e.g., `ownpay.test` or your configured `APP_DOMAIN`).
* **Merchant APIs**: Can be queried via the master domain OR the brand's configured custom domain (e.g., `pay.merchantbrand.com`).
* **Public Checkout / Callbacks**: MUST run under the merchant's configured custom domain to prevent exposure of the master brand.

---

## 2. API Groups & Authentication

Authentication is scoped based on the API target group. The key must be passed within the `Authorization` header as a Bearer token.

| API Group | Endpoint Prefix | Auth Mechanism | Example Token |
|-----------|-----------------|----------------|---------------|
| **Merchant API** | `/api/v1/*` | Bearer API Key | `Authorization: Bearer opk_live_xxxxxxxx` |
| **Mobile API** | `/api/mobile/v1/*` | Bearer JWT | `Authorization: Bearer eyJhbGciOiJIUzI1NiIs...` |
| **Admin API** | `/api/admin/v1/*` | Bearer API Key (Admin Scope) | `Authorization: Bearer opk_live_xxxxxxxx` |

### Getting a Merchant/Admin API Key
1. Navigate to the Admin Dashboard (on the master `APP_DOMAIN`).
2. Go to **Developers → API Keys**.
3. Click **Generate API Key**. The key value will only be visible once.

### Pairing a Companion Mobile Device
1. Navigate to the Admin Dashboard.
2. Go to **Mobile & SMS → Paired Devices** and click **Generate Pairing Code** (generates a short-lived 6-digit numeric OTP).
3. Post the OTP to the pair endpoint `/api/mobile/v1/devices/pair` to retrieve your JWT access token and refresh token.

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

### Error Format Example (401 Unauthenticated)
```json
{
  "success": false,
  "error": "Invalid API key or token expired"
}
```

### HTTP Status Codes
* `200 OK` — Request successful.
* `201 Created` — Resource created (e.g., Payment Intent, customer).
* `400 Bad Request` — Missing or malformed parameters.
* `401 Unauthenticated` — Invalid or missing authorization headers.
* `403 Forbidden` — Access denied due to role restrictions.
* `404 Not Found` — Resource or endpoint does not exist.
* `422 Unprocessable Entity` — Request body failed validation.
* `429 Too Many Requests` — Rate limit exceeded (Default: 60 requests/min).
* `500 Internal Server Error` — Server issue. Error messages do not expose stack traces.

---

## 4. Merchant API Integration Recipes

### Recipe A: Initiating a Payment Intent (POST `/api/v1/payments/initiate`)
This creates a new payment intent and returns the checkout URL. You should redirect your customer to the `checkout_url`.

#### Bash (cURL)
```bash
curl -X POST https://pay.merchantbrand.com/api/v1/payments/initiate \
  -H "Authorization: Bearer opk_live_your_key_here" \
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
$response = $client->post('https://pay.merchantbrand.com/api/v1/payments/initiate', [
    'headers' => [
        'Authorization' => 'Bearer opk_live_your_key_here',
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
  "transaction_id": "TXN-10948294",
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

Refer to [`openapi.yaml`](file:///c:/laragon/www/ownpay/docs/v2/api/openapi.yaml) for complete request schemas, query filters, and response models of the remaining endpoints:
* **Refund Management**: POST `/api/v1/refunds` and GET `/api/v1/refunds/{trx_id}`.
* **Customer Registry**: GET `/api/v1/customers`, POST `/api/v1/customers`, GET `/api/v1/customers/{identifier}`.
* **API Credentials Management**: GET `/api/v1/api-keys` and POST `/api/v1/api-keys/{id}/revoke`.
* **Mobile Synchronization**: POST `/api/mobile/v1/sms` (forwarding gateway texts) and GET `/api/mobile/v1/config/filter-rules`.
* **DNS Verification**: POST `/api/admin/v1/domains/verify`.
