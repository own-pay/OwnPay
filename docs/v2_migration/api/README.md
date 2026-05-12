# OwnPay API Documentation

## Quick Start

### 1. Get an API Key
Go to **Admin → Developer Hub → API Keys** and generate a new key.
Your key will look like: `opk_live_xxxxxxxxxxxxxxxx`

### 2. Make Your First Request
```bash
curl -s -H "Authorization: Bearer YOUR_API_KEY" \
  https://your-ownpay-domain.com/api/v1/health
```

Response:
```json
{
  "success": true,
  "version": "0.1.0",
  "server_time": "2026-01-01T00:00:00+00:00"
}
```

---

## Authentication

| API Group | Auth Method | Header |
|-----------|-------------|--------|
| Merchant API (`/api/v1/*`) | Bearer API Key | `Authorization: Bearer opk_live_xxx` |
| Mobile API (`/api/mobile/v1/*`) | Bearer JWT | `Authorization: Bearer eyJ...` |
| Admin API (`/api/admin/v1/*`) | Bearer API Key (admin scope) | `Authorization: Bearer opk_live_xxx` |

---

## Endpoints Overview

### Merchant API

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/health` | Health check |
| POST | `/api/v1/payments/initiate` | Create payment intent |
| GET | `/api/v1/payments/{id}` | Get payment status |
| GET | `/api/v1/transactions` | List transactions |
| GET | `/api/v1/transactions/{id}` | Get transaction |
| POST | `/api/v1/refunds` | Create refund |
| GET | `/api/v1/refunds/{id}` | Get refund |
| GET | `/api/v1/customers` | List customers |
| POST | `/api/v1/customers` | Create customer |
| GET | `/api/v1/customers/{id}` | Get customer |
| GET | `/api/v1/api-keys` | List API keys |
| POST | `/api/v1/api-keys` | Generate API key |
| POST | `/api/v1/api-keys/{id}/revoke` | Revoke API key |
| POST | `/api/v1/webhooks/test` | Test webhook |
| GET | `/api/v1/webhooks/deliveries` | Webhook delivery logs |

### Mobile API

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/mobile/v1/devices/pair` | Pair device (OTP) |
| POST | `/api/mobile/v1/devices/heartbeat` | Device heartbeat |
| POST | `/api/mobile/v1/devices/revoke` | Revoke device |
| POST | `/api/mobile/v1/devices/bulk-revoke` | Bulk revoke |
| POST | `/api/mobile/v1/devices/refresh` | Refresh JWT |
| GET | `/api/mobile/v1/devices/status` | Device status |
| POST | `/api/mobile/v1/sms` | Submit SMS |
| GET | `/api/mobile/v1/sms/queue` | Pending SMS queue |
| GET | `/api/mobile/v1/notifications` | List notifications |
| POST | `/api/mobile/v1/notifications/ack` | Acknowledge |
| GET | `/api/mobile/v1/dashboard` | Dashboard data |
| GET | `/api/mobile/v1/config/filter-rules` | SMS filter rules |

### Admin API

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/v1/sms-templates` | List SMS templates |
| PUT | `/api/admin/v1/sms-templates/{id}` | Update template |
| GET | `/api/admin/v1/sms-queue` | SMS queue |
| POST | `/api/admin/v1/sms-queue/{id}/retry` | Retry SMS |
| GET | `/api/admin/v1/devices` | List devices |
| POST | `/api/admin/v1/devices/{id}/revoke` | Revoke device |
| POST | `/api/admin/v1/domains/verify` | Verify domain |

---

## Common Examples

### Initiate a Payment
```bash
curl -X POST https://your-domain.com/api/v1/payments/initiate \
  -H "Authorization: Bearer opk_live_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 500.00,
    "currency": "BDT",
    "description": "Order #12345",
    "redirect_url": "https://yoursite.com/success",
    "cancel_url": "https://yoursite.com/cancel"
  }'
```

### List Transactions
```bash
curl -H "Authorization: Bearer opk_live_xxx" \
  "https://your-domain.com/api/v1/transactions?page=1&per_page=10&status=completed"
```

### Pair Mobile Device
```bash
curl -X POST https://your-domain.com/api/mobile/v1/devices/pair \
  -H "Content-Type: application/json" \
  -d '{
    "pairing_code": "482910",
    "device_id": "unique-device-uuid",
    "device_name": "My Phone",
    "platform": "android"
  }'
```

### Get SMS Filter Rules
```bash
curl -H "Authorization: Bearer JWT_TOKEN" \
  https://your-domain.com/api/mobile/v1/config/filter-rules
```

---

## Error Format
All errors return:
```json
{
  "success": false,
  "error": "Human-readable message"
}
```

HTTP status codes: `400` (bad request), `401` (unauthenticated), `403` (forbidden), `404` (not found), `422` (validation), `429` (rate limited), `500` (server error).

---

## OpenAPI Spec
The full OpenAPI 3.0.3 specification is available at [`openapi.yaml`](./openapi.yaml).
Import it into Swagger UI, Postman, or any OpenAPI-compatible tool.
