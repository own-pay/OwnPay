# Own Pay: Mobile Companion App & API Ecosystem — Master Architecture

> **Version:** 2.0 (Post-Critique) | **Date:** 2026-04-27 | **Status:** Approved

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
│  │ SMS Capture │ │ Privacy     │  │       │  │ /api/v1/device/pair  │    │
│  │ (Dual-Eng) │→│ Gate        │──┼─AES──→│  │ /api/v1/sms/submit   │    │
│  └────────────┘ └─────────────┘  │  256  │  │ /api/v1/notifications│    │
│  ┌────────────┐ ┌─────────────┐  │       │  │ /api/v1/dashboard/*  │    │
│  │ Offline    │ │ Sync Engine │──┼─JWT──→│  └──────────────────────┘    │
│  │ Queue(Hive)│ │ (Bulk Push) │  │       │  ┌──────────────────────┐    │
│  └────────────┘ └─────────────┘  │       │  │ SMS Parser (2-Tier)  │    │
│  ┌────────────┐ ┌─────────────┐  │       │  │ Regex → Heuristic    │    │
│  │ Dashboard  │ │ Notif Pull  │←─┼─JSON──│  └──────────────────────┘    │
│  │ (Cached)   │ │ (Polling)   │  │       │  ┌──────────────────────┐    │
│  └────────────┘ └─────────────┘  │       │  │ WebSocket (Optional) │    │
└──────────────────────────────────┘       └──────────────────────────────┘
```

---

## 3. Pillar 1: Device Pairing (5-Min OTP Handshake)

### Flow

1. Admin opens **Web Panel → Settings → Mobile App → Pair Device**
2. Server generates 6-digit OTP, stores in `op_device_pairing_tokens` (TTL: 5 min)
3. Web UI renders QR: `{"server_url":"https://merchant.com","otp":"482910"}`
4. Flutter scans QR → hits `POST /api/v1/device/pair`
5. Server validates OTP + creates device record → issues credentials → invalidates OTP

### Auth Model (Fix #1 Applied: No Permanent JWT)

| Credential | Lifetime | Purpose |
|---|---|---|
| **Access Token (JWT)** | 15 minutes | REST API authentication |
| **Refresh Token** | 90 days (revocable) | Silent JWT renewal |
| **AES-256 Key** | Permanent (rotatable) | End-to-end payload encryption |
| **Device Fingerprint** | Permanent | Device-pinning validation (Fix #5) |

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

### Server-Side Key Storage (Fix #4 Applied)

Server stores its copy of the AES-256 key encrypted via `FieldEncryptor` (existing AES-256-GCM with `PII_ENCRYPTION_KEY`). On re-pairing, server can re-issue or rotate.

### Device Fingerprint Validation (Fix #5 Applied)

On pairing, app sends: `android_id + app_signing_cert_sha256`. Server stores hash in `op_paired_devices.fingerprint_hash`. Every API call includes `X-Device-Fingerprint` header. Mismatch → 403 + alert.

### API: `POST /api/v1/device/pair`

**Request:**
```json
{
  "otp": "482910",
  "device_name": "Samsung Galaxy A54",
  "device_fingerprint": "<android_id>:<cert_sha256>",
  "app_version": "1.0.0",
  "platform": "android"
}
```

**Response (200):**
```json
{
  "access_token": "<jwt>",
  "refresh_token": "<opaque_token>",
  "expires_in": 900,
  "aes_key": "<hex_64_chars>",
  "device_id": "<uuid>",
  "filter_rules_url": "/api/v1/config/filter-rules"
}
```

### DB Tables

#### `op_device_pairing_tokens`
| Column | Type | Notes |
|---|---|---|
| id | INT AUTO_INCREMENT PK | |
| otp_hash | VARCHAR(64) | SHA-256 of OTP (never store raw) |
| brand_id | INT | FK → op_brands |
| created_by | INT | Admin who generated |
| expires_at | DATETIME | now() + 5 min |
| is_used | TINYINT(1) DEFAULT 0 | Invalidated after use |

#### `op_paired_devices`
| Column | Type | Notes |
|---|---|---|
| id | INT AUTO_INCREMENT PK | |
| device_uuid | CHAR(36) UNIQUE | Server-generated UUID |
| brand_id | INT | FK → op_brands |
| device_name | VARCHAR(100) | |
| fingerprint_hash | VARCHAR(64) | SHA-256 of device fingerprint |
| aes_key_encrypted | TEXT | AES key encrypted via FieldEncryptor |
| refresh_token_hash | VARCHAR(64) | SHA-256 of refresh token |
| refresh_token_expires_at | DATETIME | |
| jwt_secret | VARCHAR(64) | Per-device HMAC key for JWT signing |
| platform | ENUM('android','ios') | |
| app_version | VARCHAR(20) | |
| last_seen_at | DATETIME | Updated on each API call |
| revoked_at | DATETIME NULL | Admin can revoke |
| created_at | DATETIME | |

---

## 4. Pillar 2: Mobile Privacy Gate & Multi-Strategy SMS Capture

> **Note:** The detailed implementation of the SMS capture strategies, local Hive database, offline sync engine, and native notifications are strictly mobile concerns. 
> 
> For the complete Flutter App Architecture (Pillars 2, 3, and 5 Mobile Side), please refer to: **[flutter_plan.md](flutter_plan.md)**.

### Filter Rules Config (Backend Endpoint)

**API: `GET /api/v1/config/filter-rules`** (JWT required)

```json
{
  "version": 3,
  "updated_at": "2026-04-27T10:00:00Z",
  "allowed_senders": ["bKash", "16247", "Nagad", "DBBL", "01729XXXXXX"],
  "positive_keywords": ["received", "TrxID", "credited", "debited", "Tk", "BDT", "deposited"],
  "negative_keywords": ["OTP", "PIN", "password", "verify", "verification", "code"],
  "check_interval_hours": 24
}
```

App caches this locally. Re-fetches every 24h or on manual refresh.

---

## 5. Pillar 3: Offline-First Sync Engine & Audit Trail

> **See [flutter_plan.md](flutter_plan.md) for local DB schema, sync worker flow, and Audit Trail UI.**

### API: `POST /api/v1/sms/submit`

**Request:** (JWT + X-Device-Fingerprint required)
```json
{
  "messages": [
    {
      "local_id": 42,
      "encrypted_payload": "<aes_ciphertext_base64>",
      "sender": "bKash",
      "received_at": "2026-04-27T10:30:00+06:00"
    }
  ]
}
```

**Response (200):**
```json
{
  "results": [
    { "local_id": 42, "status": "accepted", "server_ref": "sms_abc123" }
  ]
}
```

### Auto-Cleanup Rule (Mobile App)

- `approved` entries older than 30 days → auto-deleted from local DB
- `failed` entries → kept indefinitely until user manually clears

---

## 6. Pillar 4: Zero-AI SMS Parsing Engine (PHP Backend)

### Server-Side Flow

```
Receive encrypted payload → Decrypt with device's AES key
→ Tier 1: Try all regex templates for this sender
→ Match? → Extract named groups → Save to op_sms_data
→ No match? → Tier 2: Heuristic analysis
→ Extracted? → Save to op_sms_parsed with confidence=heuristic
→ Failed? → Save raw to op_sms_parsed with status=unparsed, flag for admin review
```

### Tier 1: Dynamic Regex Templates (Admin Managed)

Stored in `op_sms_templates` table. All hardcoded parsing in `MfsService.php` has been migrated here.

| Column | Type |
|---|---|
| id | INT PK |
| sender_pattern | VARCHAR(50) — e.g. "bKash", "16247", "Nagad" |
| regex_pattern | TEXT — Named capture groups |
| transaction_type | ENUM('credit','debit','both') |
| provider_name | VARCHAR(50) — Human readable name |
| currency | CHAR(3) — Defaults to 'BDT' |
| balance_verify | TINYINT(1) — Enable/disable balance validation |
| priority | INT — Lower = try first |
| is_active | TINYINT(1) |

**Raw SMS Auto-Conversion:**
Admins can submit "Raw SMS" strings with tags (e.g., `Tk {amount} from {sender}. TrxID {trxid}`). The backend `SmsTemplateAdminController` automatically converts these to secure PCRE regexes (e.g., `(?P<amount>[\d,]+)`) before saving to the database.

**Plugin Compatibility:**
The parser runs `apply_filters('mfs.templates', $dbTemplates)` before executing the regex engine, preserving third-party addon support without hardcoded arrays.

### Tier 2: Heuristic Engine

Pure PHP lexical analysis:
1. **Amount detection:** Find `Tk` or `BDT` followed by number. Disambiguate from `Balance` by proximity keywords (`received`, `debited`, `deposit` → transaction amount; `balance`, `remaining` → balance)
2. **TrxID detection:** Look for `TrxID`, `TxnId`, `Ref`, `Transaction ID` followed by alphanumeric
3. **Sender detection:** 11-digit BD mobile numbers or known bank name dictionary
4. **Type detection:** `received`, `credited`, `deposited` → credit; `debited`, `sent`, `withdrawn` → debit

### DB: `op_sms_parsed` (V1 Engine Parsed SMS Table)

We use `op_sms_parsed` to store the output of the 2-Tier engine (instead of mixing it with legacy `op_sms_data`).

| Column | Type | Notes |
|---|---|---|
| device_uuid | CHAR(36) | FK → op_paired_devices |
| encrypted_raw | TEXT | Original encrypted payload (audit) |
| parsed_amount | DECIMAL(15,2) | |
| parsed_trx_id | VARCHAR(50) | |
| parsed_sender | VARCHAR(50) | |
| parsed_balance | DECIMAL(15,2) NULL | |
| parsed_type | ENUM('credit','debit','unknown') | |
| parse_method | ENUM('regex','heuristic','unparsed') | |
| template_id | INT NULL | FK → op_sms_templates if regex matched |
| parse_confidence | ENUM('high','medium','low') | |
| received_at | DATETIME | Original SMS timestamp |
| processed_at | DATETIME | Server parse timestamp |

---

### Real-Time Notifications

| Strategy | When | How |
|---|---|---|
| **Primary: Short Polling** | Always (default) | App polls `GET /api/v1/notifications/poll` every 10-15s |

### Dashboard APIs

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/v1/dashboard/summary` | GET | Today's totals, counts |
| `/api/v1/dashboard/transactions` | GET | Paginated transaction list |
| `/api/v1/dashboard/transaction/{id}` | GET | Single transaction detail |
| `/api/v1/device/refresh` | POST | Refresh JWT using refresh token |
| `/api/v1/device/status` | GET | Device connection status |

> **See [flutter_plan.md](flutter_plan.md) for Mobile UI implementations of the notifications and offline dashboard.**

---

## 8. API Authentication Flow

```
Every request (except /device/pair and /device/refresh):
  1. Extract JWT from Authorization: Bearer <token>
  2. Validate JWT signature + expiry
  3. Extract device_uuid from JWT claims
  4. Validate X-Device-Fingerprint header against stored hash
  5. Update last_seen_at
  6. Check if device is revoked → 403 if revoked
  7. Route to handler

/device/refresh:
  1. Accept refresh_token in body
  2. Hash it, lookup in op_paired_devices
  3. Check expiry + revoked status
  4. Issue new JWT (15 min)
  5. Optionally rotate refresh token
```

---

## 9. Flutter App Structure & Tech Stack

> **See [flutter_plan.md](flutter_plan.md) for the complete Flutter directory structure and Flutter-specific package dependencies.**

### PHP Backend (additions to existing OwnPay)
| Component | Purpose |
|---|---|
| `firebase/php-jwt` (composer) | JWT encode/decode |
| `OwnPay\Security\FieldEncryptor` | AES key storage (existing) |
| `OwnPay\Middleware\BearerAuthMiddleware` | Extended for JWT (existing) |
| New: `src/Service/DevicePairingService.php` | Pairing logic |
| New: `src/Service/SmsParserService.php` | 2-tier parsing engine |
| New: `src/Service/MobileNotificationService.php` | Notification queue |
| New: `src/Repository/PairedDeviceRepository.php` | Device CRUD |
| New: `src/Repository/SmsTemplateRepository.php` | Regex templates |
| New: `src/Controller/Api/V1/` | API controllers namespace |

---

## 12. Execution Order

| Part | Pillar | Scope |
|---|---|---|
| **Part 1** | Pillar 1 | DB migration + Pairing API + JWT auth + Web admin UI |
| **Part 2** | Pillar 4 | SMS parser engine (Regex + Heuristic) + submit API |
| **Part 3** | Pillar 2+3 | Flutter app: pairing, SMS capture, privacy gate, sync engine |
| **Part 4** | Pillar 5 | Polling notifications + Dashboard APIs + Flutter dashboard |
| **Part 5** | Polish | Error handling, edge cases, tests, sideload vs Play Store builds |
