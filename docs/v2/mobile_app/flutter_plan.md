# Own Pay: Mobile Companion App — Flutter Architecture Plan

> **Note:** This document outlines the Flutter mobile application architecture. For the Backend API and system overview, refer to the master plan: [plan.md](plan.md).

---

## 1. Pillar 2: Mobile Privacy Gate & Multi-Strategy SMS Capture

### SMS Capture Strategies (3-Tier Fallback)

| Tier | Method | Distribution | Play Store Safe? |
|---|---|---|---|
| **Tier A** | `READ_SMS` permission (BroadcastReceiver) | GitHub/Sideload APK | N/A (not on store) |
| **Tier B** | `SMS Retriever API` (sender-hash based) | Play Store | ✅ Yes |
| **Tier C** | Manual Share Intent (user forwards SMS) | Play Store fallback | ✅ Yes |

**Architecture:** Strategy pattern (`SmsStrategy` interface). App detects distribution channel at runtime and selects strategy. Strategies are swappable without touching business logic.

### Privacy Gate (Local Filtering)

```
SMS Received → Check sender against allowed_senders
             → If unknown sender → DROP (never leaves device)
             → Check negative_keywords (OTP, PIN, password, verify)
             → If negative match → DROP
             → Check positive_keywords (received, TrxID, credited, debited)
             → If no positive match → DROP
             → PASS → Encrypt with AES-256 → Queue for sync
```

---

## 2. Pillar 3: Offline-First Sync Engine & Audit Trail

### Local DB Schema (Hive/SQLite)

#### `sms_queue` (local)
| Field | Type | Notes |
|---|---|---|
| local_id | auto-increment | |
| encrypted_payload | String | AES-256 encrypted SMS body |
| sender | String | Sender ID/number |
| received_at | DateTime | Original SMS timestamp |
| status | Enum | `pending`, `syncing`, `approved`, `failed` |
| failure_reason | String? | e.g. "401 Auth Error", "Timeout" |
| retry_count | int | Max 5 retries |
| server_ref | String? | Server-assigned ID on success |
| created_at | DateTime | |

### Sync Flow

```
[Foreground Service] → SMS captured → Encrypt → Save to local DB (status: pending)
                     ↓
[Connectivity Monitor] → Online? → Batch query: WHERE status IN ('pending','failed') AND retry_count < 5
                     ↓
[Sync Worker] → POST /api/mobile/v1/sms (batch or single)
             → 200 OK → status = 'approved', save server_ref
             → 401    → status = 'failed', reason = 'Auth Error - re-pair required'
             → 500    → status = 'failed', reason = 'Server Error', retry_count++
             → Timeout → status = 'failed', reason = 'Network Timeout', retry_count++
```

### Audit Trail UI

Three tabs: **All** | **Synced** (✅) | **Issues** (❌)

Each entry shows: sender, time, status badge, failure reason (if any).

### Auto-Cleanup Rule

- `approved` entries older than 30 days → auto-deleted from local DB
- `failed` entries → kept indefinitely until user manually clears

---

## 3. Pillar 5: Real-Time Notifications & Dashboard (Mobile Side)

### Notification Strategy

- Companion app Foreground Service polls `GET /api/mobile/v1/notifications` every 10-15s.
- App receives response → triggers **native local notification** via `flutter_local_notifications`.
- App acknowledges processed notifications using `POST /api/mobile/v1/notifications/acknowledgements` with array of notification `ids`.

### Offline Dashboard

- App caches last-fetched dashboard data in local Hive box (from `GET /api/mobile/v1/dashboard`)
- If offline: show cached data + banner "You are offline. Showing cached data from [timestamp]."
- On reconnect: auto-refresh

---

## 4. Flutter App Structure

```
lib/
├── main.dart
├── app/
│   ├── app.dart                    # MaterialApp + routing
│   └── di.dart                     # Dependency injection (get_it)
├── core/
│   ├── config/                     # App constants, env config
│   ├── crypto/                     # AES-256 encrypt/decrypt
│   ├── network/                    # Dio HTTP client + interceptors (JWT auto-refresh)
│   ├── storage/                    # Hive boxes, secure storage
│   └── services/                   # Connectivity, foreground service
├── features/
│   ├── pairing/                    # QR scan, OTP input, pair flow
│   ├── sms_capture/                # Strategy pattern: ReadSms, SmsRetriever, ManualShare
│   ├── privacy_gate/               # Local filter engine
│   ├── sync/                       # Offline queue, bulk sync worker
│   ├── notifications/              # Poll service, local notifications
│   ├── dashboard/                  # Home, transactions, summary
│   └── settings/                   # Device info, re-pair, clear data
└── shared/
    ├── models/                     # Data classes
    ├── widgets/                    # Reusable UI components
    └── utils/                      # Formatters, validators
```

---

## 5. Tech Stack

| Package | Purpose |
|---|---|
| `dio` | HTTP client with interceptors |
| `hive` / `hive_flutter` | Local NoSQL storage |
| `flutter_secure_storage` | Secure credential storage (Keystore/Keychain) |
| `mobile_scanner` | QR code scanning |
| `flutter_local_notifications` | Native notifications |
| `connectivity_plus` | Network monitoring |
| `encrypt` | AES-256 encryption |
| `get_it` + `injectable` | Dependency injection |
| `go_router` | Navigation |
| `flutter_bloc` | State management |
