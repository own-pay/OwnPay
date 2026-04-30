# Own Pay Mobile App — Execution Checklist

> **Single source of truth.** Update status as work progresses.
> `[ ]` = pending | `[/]` = in progress | `[x]` = done

---

## Phase 0: Critique, Plan & Setup
- [x] Deep-critique architecture (5 flaws found)
- [x] Propose fixes (JWT expiry, polling, SMS strategy, AES backup, device pinning)
- [x] Get user approval on fixes
- [x] Create `docs/mobile_app/plan.md` (v2.0 with fixes)
- [x] Create `docs/mobile_app/todo.md` (this file)
- [x] Get user approval to begin Part 1

---

## Part 1: Pillar 1 — Device Pairing & Auth (PHP Backend)

### 1.1 Database Migration
- [x] Create migration: `op_device_pairing_tokens` table
- [x] Create migration: `op_paired_devices` table
- [x] Create migration: `op_mobile_notifications` table (for polling queue)
- [x] Add `composer require firebase/php-jwt`

### 1.2 Core Services
- [x] `src/Service/JwtService.php` — encode/decode/validate JWT with per-device HMAC secret
- [x] `src/Service/DevicePairingService.php` — OTP generation, validation, credential issuance
- [x] `src/Repository/PairedDeviceRepository.php` — CRUD for paired devices
- [x] `src/Repository/DevicePairingTokenRepository.php` — OTP token CRUD

### 1.3 API Middleware
- [x] Extend `BearerAuthMiddleware` or create `JwtAuthMiddleware` for JWT validation
- [x] Create `DeviceFingerprintMiddleware` — validate X-Device-Fingerprint header (integrated into JwtAuthMiddleware)
- [x] Add rate limiting rule for `/api/v1/device/pair` (5 req/5min per IP)

### 1.4 API Endpoints
- [x] `POST /api/v1/device/pair` — OTP validation + credential issuance
- [x] `POST /api/v1/device/refresh` — JWT refresh via refresh token
- [x] `GET  /api/v1/device/status` — Connection health check
- [x] Register routes in `public/api.php` (bypass BearerAuth pipeline)

### 1.5 Web Admin UI
- [x] Admin action: "device-pair-generate" (generate secure OTP via DevicePairingService)
- [x] Admin action: "device-paired-list" (list paired devices with status)
- [x] Admin action: "device-revoke" (revoke device access)
- [x] Admin page: Update "Connect Device" modal to use new secure pairing
- [x] Admin page: "Paired Devices" tab with revoke button
- [x] QR code generation using existing `chillerlan/php-qrcode` dependency

### 1.6 Tests
- [x] Unit test: JwtService (encode, decode, expired, invalid) — 12 tests, 27 assertions ✅
- [x] Unit test: DevicePairingService (16 tests, 45 assertions) — OTP gen, rate limit, valid/invalid/used OTP, re-pair, refresh, validate ✅
- [x] DeviceFingerprintMiddleware coverage — integrated into DevicePairingService validateRequest tests ✅
- [x] Integration test: Full pairing lifecycle (3 tests, 45 assertions) — OTP→Pair→JWT→Refresh→Revoke ✅

---

## Part 2: Pillar 4 — SMS Parsing Engine (PHP Backend)

### 2.1 Database
- [x] Create migration: `op_sms_templates` table (regex patterns per sender) — `migrations/009_sms_parsing_tables.sql`
- [x] Create `op_sms_parsed` table (mobile-submitted parsed SMS data) — separate from legacy `op_sms_data`
- [x] Seed 9 initial regex templates for: bKash (4), Nagad (2), Rocket (1), Upay (1), SureCash (1)

### 2.2 Parser Services
- [x] `src/Service/SmsParserService.php` — orchestrator (decrypt AES-256-GCM → Tier 1 → Tier 2 → save)
- [x] `src/Service/SmsRegexParser.php` — Tier 1: template-based regex matching with named capture groups
- [x] `src/Service/SmsHeuristicParser.php` — Tier 2: keyword/proximity-based lexical analysis fallback
- [x] `src/Repository/SmsTemplateRepository.php` — regex template CRUD with sender-based priority lookup
- [x] `src/Repository/SmsDataRepository.php` — parsed SMS data CRUD with dedup, pagination, unparsed counting

### 2.3 API Endpoints
- [x] `POST /api/v1/sms/submit` — batch (max 20) encrypted SMS, decrypt, parse, store — JWT+fingerprint+scope auth
- [x] `GET  /api/v1/config/filter-rules` — return filter config JSON (allowed senders, positive/negative keywords)
- [x] Route registration in `public/api.php` — bypasses BearerAuth, uses JWT internally

### 2.4 Admin UI
- [ ] Admin page: "SMS Templates" list (sender, regex, type, active toggle)
- [ ] Admin page: "Add/Edit SMS Template" form with regex test input
- [ ] Admin page: "Unparsed SMS" queue (review + manually assign template)

### 2.5 Tests
- [x] Unit test: SmsRegexParser — 13 tests, bKash/Nagad patterns, optional fields, priority, invalid regex ✅
- [x] Unit test: SmsHeuristicParser — 14 tests, Tk/BDT/Taka, balance disambiguation, confidence levels ✅
- [x] Unit test: SmsParserService — 12 tests, full pipeline, dedup, errors, batch, real AES-256-GCM ✅
- [x] Integration test: SmsParsingIntegrationTest — 5 tests, template lookup, regex+heuristic round-trip, dedup, pagination ✅
- [x] Full suite: 255 tests, 599 assertions — zero regressions ✅

---

## Part 3: Pillars 2+3 — Flutter App (Pairing, SMS, Sync)

### 3.1 Project Setup
- [ ] Create Flutter project with folder structure per plan
- [ ] Add all dependencies (dio, hive, flutter_secure_storage, mobile_scanner, etc.)
- [ ] Configure flavors: `sideload` (READ_SMS) vs `playstore` (SMS Retriever)
- [ ] Set up dependency injection (get_it + injectable)

### 3.2 Pairing Feature
- [ ] QR scanner screen (mobile_scanner)
- [ ] Manual URL + OTP input screen (fallback)
- [ ] Pairing API call + credential storage (flutter_secure_storage)
- [ ] JWT auto-refresh interceptor (Dio interceptor)
- [ ] Device fingerprint generation (Android ID + cert hash)

### 3.3 SMS Capture (Strategy Pattern)
- [ ] `SmsStrategy` interface: `Stream<SmsMessage> listen()`
- [ ] `ReadSmsStrategy` — direct READ_SMS (sideload build)
- [ ] `SmsRetrieverStrategy` — SMS Retriever API (Play Store build)
- [ ] `ManualShareStrategy` — Share Intent receiver (Play Store fallback)
- [ ] Strategy selector based on build flavor + runtime capability

### 3.4 Privacy Gate
- [ ] Download + cache filter rules from server
- [ ] Local filter engine: sender check → negative keyword check → positive keyword check
- [ ] AES-256 encryption of approved SMS payloads

### 3.5 Offline-First Sync Engine
- [ ] Hive box: `sms_queue` with status tracking
- [ ] Connectivity monitor (connectivity_plus)
- [ ] Sync worker: batch POST to `/api/v1/sms/submit`
- [ ] Retry logic: max 5 retries, exponential backoff
- [ ] Status updates: pending → syncing → approved/failed

### 3.6 Audit Trail UI
- [ ] History screen: All | Synced | Issues tabs
- [ ] Entry detail: sender, time, status badge, failure reason
- [ ] Auto-cleanup: delete approved entries > 30 days
- [ ] Manual clear for failed entries

### 3.7 Android Foreground Service
- [ ] Persistent notification: "Own Pay is monitoring payments"
- [ ] Service lifecycle: start on boot, restart on kill
- [ ] Battery optimization exclusion request

---

## Part 4: Pillar 5 — Notifications & Dashboard

### 4.1 Notification System (PHP)
- [x] `src/Service/MobileNotificationService.php` — queue payment notifications per device (credit/debit/unknown)
- [x] `src/Repository/MobileNotificationRepository.php` — notification CRUD with cursor polling + findById
- [x] `GET /api/v1/notifications/poll?since=<timestamp>` endpoint — `MobileNotificationController::poll`
- [x] `POST /api/v1/notifications/read` endpoint — `MobileNotificationController::markRead`
- [x] Hook into SMS processing: `SmsParserService` → auto-queues notification on successful parse
- [x] Auto-cleanup: `purgeOldRead(7)` — delete read notifications > 7 days

### 4.2 Dashboard APIs (PHP)
- [x] `GET /api/v1/dashboard/summary` — today/week/month totals, credit/debit counts, last transaction, unparsed + unread counts
- [x] `GET /api/v1/dashboard/transactions?page=1&per_page=20&type=credit&sender=bKash` — paginated + filtered list
- [x] `GET /api/v1/dashboard/transaction/{id}` — single transaction detail (brand-scoped)
- [x] Route registration in `public/api.php` — all 5 new routes added to mobile companion block

### 4.3 Tests
- [x] Unit test: MobileNotificationService — 9 tests (queue credit/debit/unknown, body formatting, poll structure, passthrough) ✅
- [x] Integration test: NotificationDashboardIntegrationTest — 5 tests (round-trip, cursor, unread, service poll, dashboard SQL) ✅
- [x] Full suite: 268 tests, 652 assertions — zero regressions ✅

### 4.4 Flutter Notifications & Dashboard (Deferred — Part 3)
- [ ] Poll service in foreground service (10-15s interval, configurable from server)
- [ ] `flutter_local_notifications` integration
- [ ] Notification tap → navigate to transaction detail
- [ ] Home screen: today's summary cards (total received, count, last transaction)
- [ ] Transaction list screen with pagination
- [ ] Transaction detail screen
- [ ] Offline cache: Hive box for dashboard data
- [ ] Offline banner: "Showing cached data from [timestamp]"

---

## Part 5: Polish & Release

### 5.1 Admin Management APIs (PHP) ✅
- [x] `AdminSmsTemplateController` — Full CRUD (list/show/create/update/delete) + regex tester endpoint
- [x] `AdminSmsQueueController` — Unparsed SMS queue (list, reprocess with updated templates, manual resolve)
- [x] `AdminSmsQueueController::stats` — Parse breakdown by status/method/provider/template usage
- [x] `AdminDeviceController` — Device management (list/show/revoke/delete) with SMS stats enrichment
- [x] `AdminDeviceController::notificationCleanup` — Purge old read notifications (configurable days)
- [x] `SmsDataRepository::updateParsedData` — Admin reprocess/resolve updates on parsed records
- [x] 16 new admin routes registered in `api.php` under Bearer auth
- [x] Structured error responses across all endpoints (INVALID_ID, NOT_FOUND, MISSING_FIELD, INVALID_REGEX, etc.)

### 5.2 Security Hardening (Server-Side) ✅
- [x] All admin endpoints behind Bearer auth + IP allowlist + rate limiting
- [x] Regex validation before save (prevents ReDoS / invalid patterns)
- [x] Raw SMS messages excluded from mobile API responses (security by design)
- [x] Device revocation: soft-revoke timestamp, JWT/refresh rejected on next use
- [x] Notification cleanup: configurable purge of old read items

### 5.3 Testing ✅
- [x] AdminSmsTemplateTest — 7 unit tests (regex validation, parser tester, reprocess simulation)
- [x] AdminFeaturesIntegrationTest — 4 integration tests (template CRUD, updateParsedData, cleanup, stats)
- [x] Full suite: **280 tests, 690 assertions — zero regressions** ✅

### 5.4 Flutter Build & Distribution (Deferred)
- [ ] Certificate pinning (optional, for high-security deployments)
- [ ] Obfuscate Flutter release build
- [ ] ProGuard rules for Android
- [ ] GitHub Release APK (sideload, READ_SMS enabled)
- [ ] Play Store AAB (SMS Retriever + Manual Share only)
- [ ] Play Store listing, privacy policy, SMS declaration form
- [ ] README: installation guide for merchants

---

*Last updated: 2026-04-27 | Parts 1–5 (PHP backend) complete. Flutter app deferred.*
