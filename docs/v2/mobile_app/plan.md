# Own Pay: Mobile Companion App & API Ecosystem — Master Architecture

> **Version:** 0.1.1 | **Date:** 2026-05-23 | **Status:** Updated

---

## 1. Core Philosophy (Non-Negotiable)

| Principle | Rule |
|---|---|
| **Data Sovereignty** | Data exists ONLY on the Merchant's server + Merchant's phone |
| **Zero Third-Party** | NO Firebase, NO APNs, NO external LLM/AI APIs |
| **User Privacy** | Server NEVER receives personal SMS or OTPs. All filtering is local |
| **Play Store Safe** | Multi-strategy SMS capture with graceful fallbacks |

---

## 2. System Overview

```
┌──────────────────────────────────┐       ┌──────────────────────────────┐
│  FLUTTER MOBILE APP              │       │  PHP WEB BACKEND (OwnPay)    │
│                                  │       │                              │
│  ┌────────────┐ ┌─────────────┐  │       │  ┌──────────────────────┐    │
│  │ SMS Capture │ │ Privacy     │  │       │  │ /api/mobile/v1/devices/pair│
│  │ (Dual-Eng) │→│ Gate        │──┼─AES──→│  │ /api/mobile/v1/sms   │    │
│  └────────────┘ └─────────────┘  │  256  │  │ /api/mobile/v1/notifications│
│  ┌────────────┐ ┌─────────────┐  │       │  │ /api/mobile/v1/dashboard  │    │
│  │ Offline    │ │ Sync Engine │──┼─JWT──→│  └──────────────────────┘    │
│  │ Queue(Hive)│ │ (Bulk Push) │  │       │  ┌──────────────────────┐    │
│  └────────────┘ └─────────────┘  │  256  │  │ SMS Parser (2-Tier)  │    │
│  ┌────────────┐ ┌─────────────┐  │       │  │ Regex → Heuristic    │    │
│  │ Dashboard  │ │ Notif Pull  │←─┼─JSON──│  └──────────────────────┘    │
│  │ (Cached)   │ │ (Polling)   │  │       │                              │
│  └────────────┘ └─────────────┘  │       │                              │
└──────────────────────────────────┘       └──────────────────────────────┘
```

---

## 3. Pillar 1: Device Pairing (5-Min OTP Handshake)

### Flow

1. Admin opens **Web Panel → Settings → Mobile App → Pair Device**
2. Server generates 6-digit OTP, stores in `op_device_pairing_tokens` (TTL: 5 min)
3. Web UI renders QR: `{"server_url":"https://merchant.com","otp":"482910"}`
4. Flutter scans QR → hits `POST /api/mobile/v1/devices/pair`
5. Server validates OTP + creates device record → issues credentials → invalidates OTP

### Auth Model

| Credential | Lifetime | Purpose |
|---|---|---|
| **Access Token (JWT)** | 15 minutes | REST API authentication |
| **Refresh Token** | 90 days (revocable, rotated on refresh) | Silent JWT renewal (JTI blacklisted to prevent replay) |
| **AES-256 Key** | Permanent (rotatable) | End-to-end payload encryption |
| **Device Fingerprint** | Permanent | Device-pinning validation (via jwt_fingerprint SHA-256 hash) |

### JWT Payload

```json
{
  "sub": "device:<device_uuid>",
  "iss": "ownpay",
  "iat": 1714200000,
  "exp": 1714200900,
  "brand_id": 1,
  "scopes": ["sms:submit", "dashboard:read", "notifications:poll"]
}
```

### Server-Side Key Storage

Server stores its copy of the AES-256 key encrypted via `FieldEncryptor` (AES-256-GCM with `ENCRYPTION_KEY`). On re-pairing, server can re-issue or rotate.

### Device Fingerprint Validation

On pairing, app sends: `android_id + app_signing_cert_sha256`. Server stores `jwt_fingerprint` which is `hash('sha256', $deviceUuid . $merchantId)`. Every API call verification matches the request JWT signature and extracts the paired device configuration to authorize access.

### API: `POST /api/mobile/v1/devices/pair`

**Request:**
```json
{
  "pairing_code": "482910",
  "device_name": "Samsung Galaxy A54",
  "device_id": "<android_id>:<cert_sha256>",
  "app_version": "1.0.0",
  "platform": "android"
}
```

**Response (201):**
```json
{
  "success": true,
  "access_token": "<jwt>",
  "refresh_token": "<opaque_token>",
  "expires_in": 900,
  "aes_key": "<hex_64_chars>",
  "device_uuid": "<uuid>"
}
```

### DB Tables (V0.1.0)

#### `op_device_pairing_tokens`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| merchant_id | BIGINT UNSIGNED | FK → op_merchants |
| otp_hash | VARCHAR(64) | SHA-256 of OTP (never store raw) |
| expires_at | DATETIME(6) | now() + 5 min |
| is_used | TINYINT(1) DEFAULT 0 | Invalidated after use |
| used_at | DATETIME(6) NULL | |
| created_by | BIGINT UNSIGNED | Admin who generated |
| created_at | DATETIME(6) | |

#### `op_paired_devices`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| merchant_id | BIGINT UNSIGNED | FK → op_merchants |
| device_id | VARCHAR(64) UNIQUE | Server-generated UUID |
| device_name | VARCHAR(150) | |
| platform | VARCHAR(30) | |
| jwt_fingerprint | VARCHAR(255) | SHA-256 of device fingerprint |
| aes_key_encrypted | TEXT | AES key encrypted via FieldEncryptor |
| last_heartbeat | DATETIME(6) NULL | Updated on each API call/heartbeat |
| status | ENUM('active','revoked','inactive') | |
| paired_at | DATETIME(6) | |

---

## 4. Pillar 2: Mobile Privacy Gate & Multi-Strategy SMS Capture

> For the complete Flutter App Architecture (Pillars 2, 3, and 5 Mobile Side), please refer to: **[flutter_plan.md](flutter_plan.md)**.

### Filter Rules Config (Backend Endpoint)

**API: `GET /api/mobile/v1/config/filter-rules`** (JWT required)

```json
{
  "success": true,
  "version": 1,
  "updated_at": "2026-05-20T10:00:00Z",
  "allowed_senders": ["bKash", "16247", "Nagad", "DBBL"],
  "positive_keywords": ["received", "credited", "TrxID", "TxnID", "deposited", "Tk", "BDT"],
  "negative_keywords": ["OTP", "PIN", "password", "verify", "verification", "code"],
  "check_interval_hours": 24
}
```

App caches this locally. Re-fetches every 24h or on manual refresh.

---

## 5. Pillar 3: Offline-First Sync Engine & Audit Trail

> **See [flutter_plan.md](flutter_plan.md) for local DB schema, sync worker flow, and Audit Trail UI.**

### API: `POST /api/mobile/v1/sms`

**Request:** (JWT required)
```json
{
  "messages": [
    {
      "local_id": 42,
      "encrypted_payload": "<aes_ciphertext_base64>",
      "sender": "bKash",
      "received_at": "2026-05-20T10:30:00+06:00"
    }
  ]
}
```

**Response (200):**
```json
{
  "success": true,
  "results": [
    { "local_id": 42, "status": "accepted", "server_ref": "sms_abc123" }
  ]
}
```

---

## 6. Pillar 4: Zero-AI SMS Parsing Engine (PHP Backend)

### Server-Side Flow

```
Receive encrypted payload → Decrypt with device's AES key
→ Tier 1: Try all regex templates for this sender
→ Match? → Extract named groups → Save to op_sms_parsed
→ No match? → Tier 2: Heuristic analysis
→ Extracted? → Save to op_sms_parsed with confidence=heuristic
→ Failed? → Save raw to op_sms_parsed with status=unparsed, flag for admin review
```

### Tier 1: Dynamic Regex Templates (Admin Managed)

Stored in `op_sms_templates` table.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| merchant_id | BIGINT UNSIGNED | FK → op_merchants (NULL for global templates) |
| gateway_slug | VARCHAR(60) | Linked payment gateway slug |
| sender_pattern | VARCHAR(100) | E.g. "bKash", "Nagad" |
| amount_regex | VARCHAR(500) | Regex pattern to extract payment amount |
| trx_id_regex | VARCHAR(500) | Regex pattern to extract transaction ID (nullable) |
| sender_regex | VARCHAR(500) | Regex pattern to extract sender phone number (nullable) |
| priority | INT | Matching priority ascending (default: 10) |
| status | ENUM('active','inactive') | Active/inactive state (default: 'active') |
| created_at | DATETIME(6) | Timestamp when created |
| updated_at | DATETIME(6) | Timestamp when updated |


### Tier 2: Heuristic Engine

Pure PHP lexical analysis (`SmsHeuristicParser`):
1. **Amount detection:** Find `Tk` or `BDT` followed by number. Disambiguate from `Balance` by proximity keywords (`received`, `credited` → transaction amount; `balance` → balance)
2. **TrxID detection:** Look for `TrxID`, `TxnId`, `Ref`, `Transaction ID` followed by alphanumeric
3. **Sender detection:** 11-digit mobile numbers or known bank names
4. **Type detection:** `received`, `credited`, `deposited` → credit; `debited`, `sent`, `withdrawn` → debit

### DB: `op_sms_parsed`

Stores all parsed SMS messages, both matching templates and heuristics fallbacks.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| merchant_id | BIGINT UNSIGNED | FK → op_merchants |
| device_id | VARCHAR(64) | FK → op_paired_devices.device_id |
| local_id | INT NULL | Mobile app local queue identifier |
| sender | VARCHAR(100) | Originating phone/shortcode |
| body | TEXT | Original decrypted SMS body |
| amount | DECIMAL(20,6) | Extracted payment amount |
| trx_id | VARCHAR(100) | Payment gateway transaction ID |
| parsed_sender | VARCHAR(255) | Customer identifier/sender number |
| parsed_balance | DECIMAL(20,6) | Account balance |
| gateway_slug | VARCHAR(60) | Target gateway slug |
| parser_type | ENUM('regex','heuristic','manual','unparsed') | |
| parsed_type | VARCHAR(50) | e.g. credit, debit |
| template_id | BIGINT UNSIGNED NULL | Linked template ID |
| parse_confidence | ENUM('high','medium','low') | |
| match_status | ENUM('pending','matched','unmatched','ignored','accepted','parse_error','admin_review') | |
| transaction_id | BIGINT UNSIGNED NULL | Linked created transaction |
| raw_data | JSON | Structured data payload |
| encrypted_raw | TEXT | Encrypted message (audit) |
| received_at | DATETIME(6) | Client timestamp of SMS |
| created_at | DATETIME(6) | Server insert timestamp |

---

## 7. Real-Time Notifications

### Flow
- Mobile app polls `GET /api/mobile/v1/notifications` every 10-15 seconds.
- Server returns a list of pending notifications for the specific device ID.
- App displays local notifications and acknowledges receipt via `POST /api/mobile/v1/notifications/ack` with `ids`.

#### `op_mobile_notifications`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| merchant_id | BIGINT UNSIGNED | FK → op_merchants |
| device_uuid | VARCHAR(64) | Refers to `op_paired_devices.device_id` |
| type | VARCHAR(50) | Notification type |
| title | VARCHAR(255) | |
| body | TEXT | |
| payload | JSON | Additional data |
| is_read | TINYINT(1) | |
| read_at | DATETIME(6) NULL | |
| created_at | DATETIME(6) | |

---

## 8. Dashboard APIs

Exposes status summary and recent stats directly to companion device:
- **`GET /api/mobile/v1/dashboard`** returns today's stats, recent transactions, unread notification count, and current server time.

---

## 9. API Authentication Flow

```
Every request (except /devices/pair and /devices/refresh):
  1. Extract JWT from Authorization: Bearer <token>
  2. Validate JWT signature + expiry
  3. Validate device is active in DB
  4. Route to handler with merchant_id and device_id injected
```
