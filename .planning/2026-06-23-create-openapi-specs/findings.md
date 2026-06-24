# Findings & Decisions

## Requirements
- Generate 3 OpenAPI v3.2.0 API specifications in YAML format:
  - Merchant API (`docs/v2/api/merchant_api.yaml`)
  - Mobile Companion App API (`docs/v2/api/mobile_api.yaml`)
  - Administrative API (`docs/v2/api/admin_api.yaml`)
- Detailed, explained, fully described, no placeholders, no guessing.

## Research Findings
### 1. Routes Structure (config/routes/api.php)
- **Merchant API**:
  - GET `/api/v1/health`
  - POST `/api/v1/payments`
  - GET `/api/v1/payments/{payment_id}`
  - GET `/api/v1/transactions`
  - GET `/api/v1/transactions/{trx_id}`
  - POST `/api/v1/refunds`
  - GET `/api/v1/refunds`
  - GET `/api/v1/refunds/{trx_id}`
  - GET `/api/v1/customers`
  - GET `/api/v1/customers/{identifier}`
  - POST `/api/v1/customers`
  - GET `/api/v1/api-keys`
  - POST `/api/v1/api-keys`
  - DELETE `/api/v1/api-keys/{id}`
  - POST `/api/v1/webhooks/tests`
  - GET `/api/v1/webhooks/deliveries`
  - POST `/csp-report-api` (public/webhook, under 'api-public' middleware)
- **Mobile Companion App API**:
  - POST `/api/mobile/v1/devices`
  - POST `/api/mobile/v1/devices/heartbeats`
  - DELETE `/api/mobile/v1/devices/{id}`
  - POST `/api/mobile/v1/devices/bulk-revocations`
  - POST `/api/mobile/v1/sms`
  - GET `/api/mobile/v1/sms/queues`
  - GET `/api/mobile/v1/notifications`
  - POST `/api/mobile/v1/notifications/acknowledgements`
  - GET `/api/mobile/v1/dashboard`
  - GET `/api/mobile/v1/config/filter-rules`
  - POST `/api/mobile/v1/devices/token-refreshes`
  - GET `/api/mobile/v1/devices/statuses`
- **Administrative API**:
  - GET `/api/admin/v1/sms-templates`
  - PUT `/api/admin/v1/sms-templates/{id}`
  - GET `/api/admin/v1/sms-queues`
  - POST `/api/admin/v1/sms-queues/{id}/retries`
  - GET `/api/admin/v1/devices`
  - DELETE `/api/admin/v1/devices/{id}`
  - POST `/api/admin/v1/domains/verifications`

### 2. Standard API Response Formats (Response.php)
- **Standard API Success Response**:
  ```json
  {
    "success": true,
    "data": {},
    "meta": {}
  }
  ```
- **Standard API Single Error Response**:
  ```json
  {
    "success": false,
    "error": "Error message",
    "errors": [
      {
        "code": "ERROR_CODE",
        "message": "Error message",
        "field": "field_name"
      }
    ],
    "request_id": "request-uuid"
  }
  ```
- **Standard API Validation Errors Response**:
  ```json
  {
    "success": false,
    "error": "First validation error message",
    "errors": [
      {
        "code": "VALIDATION_FAILED",
        "message": "amount must be a positive number",
        "field": "amount"
      }
    ],
    "request_id": "request-uuid"
  }
  ```

### 3. Detailed Endpoint Findings (Part 1 - Merchant API)
- **GET `/api/v1/health`**
  - Performs health checks on db ping, active gateways, customer counts, and mobile paired devices active in the last 10 minutes.
  - Returns `200` (healthy) or `503` (degraded).
  - Header: `X-API-Version`
  - Success Data: `{ status: "healthy"|"degraded", version: string, db: "connected"|"error", mobile: { connected: boolean, active_devices: integer }, gateways: integer, customers: integer, time: string (ISO8601) }`
- **POST `/api/v1/payments`**
  - Body:
    - `amount` (string/number, required, positive)
    - `currency` (string, required, 3-letter ISO code)
    - `callback_url` (string, optional, HTTP/HTTPS URL)
    - `redirect_url` (string, optional, HTTP/HTTPS URL)
    - `cancel_url` (string, optional, HTTP/HTTPS URL)
    - `customer_email` (string, optional, email format)
    - `customer_name` (string, optional)
    - `customer_phone` (string, optional)
    - `reference` (string, optional)
    - `gateway` (string, optional)
    - `metadata` (object/array, optional)
  - Success Response: `201` Created. Data: `{ payment_id: string (UUID), token: string, checkout_url: string, status: string }`
  - Error Responses: `422` (Validation errors: `INVALID_AMOUNT`, `INVALID_CURRENCY`, `INVALID_CALLBACK_URL`, etc.), `500` (`PAYMENT_PROCESSING_FAILED`)
- **GET `/api/v1/payments/{payment_id}`**
  - Path param: `payment_id` (string, UUID, required)
  - Success Response: `200` OK. Data:
    - If payment has been completed: `{ id: integer, trx_id: string, gateway_trx_id: string, amount: string, currency: string, fee: string, status: string, gateway: string, method: string, reference: string, created_at: string, completed_at: string, customer: { name: string, email: string } }`
    - If no transaction yet (intent only): `{ id: null, trx_id: null, gateway_trx_id: null, amount: string, currency: string, fee: "0.00", status: string, gateway: null, method: null, reference: string, created_at: string, completed_at: null, customer: { name: string, email: string } }`
  - Error Responses: `422` (`PAYMENT_ID_REQUIRED`, `INVALID_PAYMENT_ID`), `404` (`PAYMENT_NOT_FOUND`)
- **GET `/api/v1/transactions`**
  - Query params:
    - `page` (integer, optional, default 1)
    - `per_page` (integer, optional, default 25, max 100)
    - `status` (string, optional)
    - `gateway` (string, optional)
    - `from` (string, date/datetime, optional)
    - `to` (string, date/datetime, optional)
  - Success Response: `200` OK. Data: array of transactions: `{ id: integer, trx_id: string, gateway_trx_id: string|null, amount: string, currency: string, fee: string, net_amount: string|null, status: string, gateway: string|null, method: string|null, reference: string|null, created_at: string, updated_at: string|null }`. Meta: `{ page: integer, per_page: integer, total: integer, total_pages: integer }`
- **GET `/api/v1/transactions/{trx_id}`**
  - Path param: `trx_id` (string, required) - OwnPay ID (starts with OP- / OP_) or gateway transaction ID.
  - Success Response: `200` OK. Data: single transaction object.
  - Error Responses: `422` (`TRANSACTION_ID_REQUIRED`), `404` (`TRANSACTION_NOT_FOUND`)
- **POST `/api/v1/refunds`**
  - Body:
    - `trx_id` or `transaction_id` (string/integer, required)
    - `amount` (string/number, optional) - defaults to full refund
    - `reason` (string, optional)
  - Success Response: `201` Created. Data: `{ id: integer, uuid: string, transaction_id: integer, trx_id: string, gateway_trx_id: string|null, amount: string, reason: string, status: string, processed_at: string|null, created_at: string }`
  - Error Responses: `422` (`TRANSACTION_ID_REQUIRED`), `404` (`TRANSACTION_NOT_FOUND`), `400` (`INVALID_REFUND_PARAMETERS`), `500` (`REFUND_PROCESSING_FAILED`)
- **GET `/api/v1/refunds/{trx_id}`**
  - Path param: `trx_id` (string, required)
  - Success Response: `200` OK. Data: single refund object.
  - Error Responses: `422` (`TRANSACTION_ID_REQUIRED`), `404` (`TRANSACTION_NOT_FOUND`, `REFUND_NOT_FOUND`)
- **GET `/api/v1/refunds`**
  - Query params: `page`, `per_page`, `status`, `trx_id`, `transaction_id`, `from`, `to`
  - Success Response: `200` OK. Data: array of refunds. Meta: pagination.

- **GET `/api/v1/customers`**
  - Query params: `page`, `per_page`
  - Success Response: `200` OK. Data: list of customer records. Each: `{ id: integer, uuid: string, name: string|null, email: string|null, phone: string|null, email_masked: string|null, phone_masked: string|null, created_at: string }`. Meta: `{ page: integer, per_page: integer, total: integer }`
- **GET `/api/v1/customers/{identifier}`**
  - Path param: `identifier` (string, required, rawurldecoded email or phone)
  - Success Response: `200` OK. Data: `{ id: integer, uuid: string, name: string|null, email: string|null, phone: string|null, created_at: string }`
  - Error Responses: `422` (`IDENTIFIER_REQUIRED`), `404` (`CUSTOMER_NOT_FOUND`)
- **POST `/api/v1/customers`**
  - Body:
    - `name` (string, required)
    - `email` (string, optional)
    - `phone` (string, optional)
  - Success Response: `201` Created. Data: `{ id: integer, uuid: string }`
  - Error Responses: `422` (`NAME_REQUIRED`), `409` (`CUSTOMER_EXISTS`), `500` (`CUSTOMER_CREATION_FAILED`)
- **GET `/api/v1/api-keys`**
  - Headers: `X-Super-Admin-Email` (required, string, active superadmin email)
  - Auth: API key with `write` and `admin` scopes.
  - Success Response: `200` OK. Data: list of key metadata: `{ id: integer, name: string, prefix: string|null, status: string, last_used: string|null, expires_at: string|null, created_at: string }`
  - Error Responses: `401` (`UNAUTHORIZED`), `403` (`INSUFFICIENT_PRIVILEGE`), `400` (`SUPER_ADMIN_EMAIL_REQUIRED`), `403` (`INVALID_SUPER_ADMIN`)
- **POST `/api/v1/api-keys`**
  - Headers: `X-Super-Admin-Email` (required, active superadmin email)
  - Body:
    - `name` or `label` (string, optional, defaults to 'Default')
    - `scopes` (array of strings, optional, defaults to `['read', 'write']`, allowed: `read`, `write`, `admin`)
  - Success Response: `201` Created. Data: `{ key: string (plain key displayed once), prefix: string, warning: string }`
  - Error Responses: `422` (`INVALID_SCOPES`)
- **DELETE `/api/v1/api-keys/{id}`**
  - Headers: `X-Super-Admin-Email` (required, active superadmin email)
  - Path param: `id` (integer, required)
  - Success Response: `200` OK. Data: `{ message: "Key revoked" }`
  - Error Responses: `404` (`KEY_NOT_FOUND`), `400` (`KEY_REVOCATION_FAILED`)
- **POST `/api/v1/webhooks/tests`**
  - Success Response: `200` OK. Data: `{ status_code: integer|null, response_time_ms: integer|null }`
  - Error Response: `400` (`WEBHOOK_TEST_FAILED`, message contains error details)
- **GET `/api/v1/webhooks/deliveries`**
  - Success Response: `200` OK. Data: array of delivery attempts.

### 4. Detailed Endpoint Findings (Part 2 - Mobile Companion App API)
- **POST `/api/mobile/v1/devices`**
  - Public bootstrapping endpoint.
  - Body:
    - `pairing_code` (string, required)
    - `device_id` (string, required, unique client hardware identifier)
    - `device_name` (string, optional, default: 'Unknown')
    - `app_version` (string, optional, default: '1.0.0')
    - `platform` (string, optional, default: 'android')
  - Success Response: `201` Created. Data: `{ access_token: string (JWT), device_uuid: string (UUID), refresh_token: string (JWT), aes_key: string, expires_in: integer }`
  - Error Responses: `422` (`PAIRING_PARAMETERS_REQUIRED`), `400` (`DEVICE_PAIRING_FAILED`, `INVALID_PAIRING_CODE`)
- **POST `/api/mobile/v1/devices/heartbeats`**
  - Auth: JWT (mobile)
  - Success Response: `200` OK. Data: `{ server_time: string (ISO8601) }`
- **DELETE `/api/mobile/v1/devices/{id}`**
  - Auth: JWT (mobile)
  - Path param: `id` (integer or string/UUID)
  - Success Response: `200` OK. Data: `{ message: "Device revoked" }`
- **POST `/api/mobile/v1/devices/bulk-revocations`**
  - Auth: JWT (mobile)
  - Body:
    - `device_ids` (array of strings, required)
  - Success Response: `200` OK. Data: `{ revoked: integer }`
  - Error Responses: `422` (`INVALID_PARAMETER_TYPE`, `DEVICE_IDS_REQUIRED`)
- **POST `/api/mobile/v1/devices/token-refreshes`**
  - Public bootstrapping endpoint.
  - Headers: `X-Device-Fingerprint` (required unless passed as query/body param `fingerprint`)
  - Body:
    - `refresh_token` (string, required, long-lived JWT token)
    - `fingerprint` (string, optional, required if header is missing)
  - Success Response: `200` OK. Data: `{ access_token: string, refresh_token: string, expires_in: integer, server_time: string (ISO8601) }`
  - Error Responses: `422` (`REFRESH_TOKEN_REQUIRED`, `FINGERPRINT_REQUIRED`), `401` (`REFRESH_FAILED`: e.g., device revoked or fingerprint mismatch)
- **GET `/api/mobile/v1/devices/statuses`**
  - Auth: JWT (mobile)
  - Success Response: `200` OK. Data: `{ device_id: string, device_name: string, platform: string, status: string, last_heartbeat: string|null, merchant_id: integer, server_time: string (ISO8601) }`
  - Error Response: `404` (`DEVICE_NOT_FOUND`)
- **POST `/api/mobile/v1/sms`**
  - Auth: JWT (mobile)
  - Body format: Can be single message object, batch array, or `{ messages: array }`.
    - Single message properties: `{ sender: string (required, max 100 bytes), encrypted_payload: string (required) or body: string (required), local_id: integer|null (optional), received_at: string (optional) }`
    - Batch limit: Max 200 messages. Max payload size: 16384 bytes combined body/payload.
  - Success Response: `200` OK (or `400` on single message reject).
    - Single mode: Data: `{ status: "accepted"|"duplicate"|"rejected", server_ref: string|null, error: string|null }`
    - Batch mode: Data: Array of items, each with: `{ status, server_ref, error, ... }`
  - Error Responses: `422` (`MESSAGES_REQUIRED`, `BATCH_TOO_LARGE`, `INVALID_MESSAGE_FORMAT`, `INVALID_MESSAGE_PAYLOAD`, `SENDER_TOO_LONG`, `PAYLOAD_TOO_LARGE`), `400` (for single message processing failures)
- **GET `/api/mobile/v1/sms/queues`**
  - Auth: JWT (mobile)
  - Success Response: `200` OK. Data: Array of pending outbound SMS (max 20).

- **GET `/api/mobile/v1/notifications`**
  - Auth: JWT (mobile)
  - Success Response: `200` OK. Data: Array of notification objects: `{ id: integer, type: string, title: string, body: string, data: string|null (JSON payload), read_at: string|null, created_at: string }`
- **POST `/api/mobile/v1/notifications/acknowledgements`**
  - Auth: JWT (mobile)
  - Body:
    - `ids` (array of integers, required)
  - Success Response: `200` OK. Data: `{ acknowledged: integer }`
  - Error Responses: `422` (`IDS_REQUIRED`)
- **GET `/api/mobile/v1/dashboard`**
  - Auth: JWT (mobile)
  - Headers: `X-API-Version`
  - Success Response: `200` OK. Data: `{ today: { revenue: string, total: integer, pending: integer }, recent_transactions: Array<{ trx_id: string, amount: string, currency: string, status: string, gateway: string|null, created_at: string }>, unread_notifications: integer, server_time: string (ISO8601) }`
- **GET `/api/mobile/v1/config/filter-rules`**
  - Auth: JWT (mobile)
  - Success Response: `200` OK. Data: `{ version: 1, updated_at: string (ISO8601), allowed_senders: Array<string>, positive_keywords: Array<string>, negative_keywords: Array<string>, check_interval_hours: integer }`

### 5. Detailed Endpoint Findings (Part 3 - Administrative API)
- **GET `/api/admin/v1/sms-templates`**
  - Auth: Bearer token with `admin` scope. Scoped to merchant.
  - Success Response: `200` OK. Data: Array of SMS templates.
- **PUT `/api/admin/v1/sms-templates/{id}`**
  - Auth: Bearer token with `admin` scope. Scoped to merchant.
  - Path param: `id` (integer, required)
  - Body:
    - `gateway_slug` (string, optional)
    - `sender_pattern` (string, optional)
    - `amount_regex` (string, optional)
    - `trx_id_regex` (string, optional)
    - `sender_regex` (string, optional)
    - `priority` (integer, optional)
    - `status` (string, optional)
  - Success Response: `200` OK. Data: `{ message: "Template updated successfully" }`
- **GET `/api/admin/v1/sms-queues`**
  - Auth: Bearer token with `admin` scope. Scoped to merchant.
  - Success Response: `200` OK. Data: Array of SMS queue items (max 100).
- **POST `/api/admin/v1/sms-queues/{id}/retries`**
  - Auth: Bearer token with `admin` scope. Scoped to merchant.
  - Path param: `id` (integer, required)
  - Success Response: `200` OK. Data: `{ message: "Queued for retry" }`
  - Error Responses: `409` (`SMS_NOT_RETRYABLE`), `400` (`SMS_RETRY_FAILED`)
- **GET `/api/admin/v1/devices`**
  - Auth: Bearer token with `admin` scope. Scoped to merchant.
  - Success Response: `200` OK. Data: Array of paired companion devices.
- **DELETE `/api/admin/v1/devices/{id}`**
  - Auth: Bearer token with `admin` scope. Scoped to merchant.
  - Path param: `id` (string/UUID, required)
  - Success Response: `200` OK. Data: `{ message: "Device revoked successfully" }`
  - Error Response: `400` (`DEVICE_REVOCATION_FAILED`)
- **POST `/api/admin/v1/domains/verifications`**
  - Auth: Bearer token with `admin` scope. Scoped to merchant.
  - Body:
    - `domain_id` (integer, required)
  - Success Response: `200` OK. Data: `{ verified: boolean }`
  - Error Response: `422` (`DOMAIN_ID_REQUIRED`)

### 6. Controllers Found
- **Merchant API Controllers**: HealthController, PaymentController, TransactionController, RefundController, CustomerController, ApiKeyController, WebhookController
- **Mobile Companion App API Controllers**: ConfigController, DashboardController, DeviceController, NotificationController, SmsController
- **Administrative API Controllers**: DeviceController, DomainController, SmsQueueController, SmsTemplateController

## Technical Decisions
| Decision | Rationale |
|----------|-----------|

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [api.php](file:///c:/laragon/www/ownpay/config/routes/api.php)
- [Response.php](file:///c:/laragon/www/ownpay/src/Http/Response.php)
