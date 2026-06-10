# mobile_architecture.md — Companion Mobile App Architecture & Play Store SMS Compliance (Deliverable 3)

> Companion to `ownpay_master_audit_report.md`. Covers (1) backend REST API readiness for the companion app, (2) Google Play SMS-permission compliance & approval strategy (grounded in the live policy as of May 2026), and (3) the on-device privacy gate / data-sovereignty model.

---

## PART 1 — Backend API Readiness & Endpoint Integration

### 1.1 Endpoint inventory (verified — `config/routes/api.php`)
All under the `mobile` middleware group `[CorsMiddleware, RateLimiterMiddleware, JwtAuthMiddleware]`:

| Method | Path | Controller | Purpose |
|---|---|---|---|
| POST | `/api/mobile/v1/devices` | `DeviceController@pair` | Pair device with OTP → tokens + AES key |
| POST | `/api/mobile/v1/devices/heartbeats` | `DeviceController@heartbeat` | Liveness |
| DELETE | `/api/mobile/v1/devices/{id}` | `DeviceController@revoke` | Revoke |
| POST | `/api/mobile/v1/devices/bulk-revocations` | `DeviceController@bulkRevoke` | Bulk revoke |
| POST | `/api/mobile/v1/devices/token-refreshes` | `DeviceController@refresh` | Rotate access token |
| GET | `/api/mobile/v1/devices/statuses` | `DeviceController@status` | Connection status |
| POST | `/api/mobile/v1/sms` | `SmsController@receive` | Ingest GCM-encrypted SMS (single/batch) |
| GET | `/api/mobile/v1/sms/queues` | `SmsController@queue` | Outbound SMS queue |
| GET | `/api/mobile/v1/notifications` | `NotificationController@index` | Poll notifications |
| POST | `/api/mobile/v1/notifications/acknowledgements` | `NotificationController@ack` | Ack (device-scoped) |
| GET | `/api/mobile/v1/dashboard` | `DashboardController@index` | User dashboard data |
| GET | `/api/mobile/v1/config/filter-rules` | `ConfigController@filterRules` | On-device privacy-gate config |

### 1.2 Security primitives (verified, and strong)
- **Pairing OTP handshake**: `DevicePairingService::generatePairingOtp` issues a 6-digit `random_int` OTP, stores **sha256(otp)**, 5-min expiry, rate-limited 5/300s per admin, and invalidates prior unused OTPs. `validatePairingOtp` consumes it inside a `SELECT … FOR UPDATE` transaction marking `is_used=1` — replay/concurrency safe.
- **ACCESS/REFRESH tokens**: `JwtService` HS256 (hardcoded — no alg-confusion), access TTL 24h, refresh TTL 30d, CSPRNG `jti`. Refresh rotation blacklists the old `jti` in `op_cache` and re-checks device status + fingerprint (`DevicePairingService::refreshAccessToken`).
- **Device fingerprint**: sha256 fingerprint stored at pairing; `JwtAuthMiddleware`/`refreshAccessToken`/`validateRequest` reject on mismatch. `JwtAuthMiddleware` also **requires `iss`+`aud`** (closes a prior claim-omission bypass) and checks device-revocation on every call.
- **GCM transport**: `SmsParserService::decryptSmsPayload` — AES-256-GCM, `IV(12)+ciphertext+tag(16)`, 32-byte key length validated; per-device AES key generated at pairing and stored encrypted (`FieldEncryptor`).
- **Brand isolation**: every mobile repo call is `forTenant($mid)`-scoped from the JWT `mid` claim.

### 1.3 Readiness verdict: **Architecturally complete and secure — but NOT integration-ready until FIND-019 is fixed**

#### ⛔ FIND-019 (HIGH, NEW) — Device pairing is blocked by JWT middleware (bootstrap deadlock)
- **Location**: `config/routes/api.php:40` (pair route → `mobile` group) + `config/middleware.php:72-76` (`mobile` = `…JwtAuthMiddleware`) + `src/Middleware/JwtAuthMiddleware.php:47-54`.
- **Description**: `JwtAuthMiddleware` unconditionally returns **401** when no Bearer token is present and has **no route allowlist**. But a freshly-installed device has **no token** — it authenticates pairing with the OTP in the request body (`DeviceController::pair` reads `pairing_code`/`device_id` from JSON, not a bearer). Therefore the pairing request is rejected with 401 **before** the controller runs. **No device can complete initial pairing as wired.**
- **Evidence**:
```php
// JwtAuthMiddleware.php:47-54  (runs for the 'mobile' group, incl. the pair route)
$token = $request->bearerToken();
if ($token === null || $token === '') {
    return Response::json(['success'=>false,'message'=>'JWT token required'], 401);
}
// config/routes/api.php:40
$router->post('/api/mobile/v1/devices', 'Api\\Mobile\\DeviceController@pair', 'mobile'); // JWT-guarded
```
- **Impact**: The entire companion-app onboarding flow is non-functional; the SMS-automation product cannot be activated on any device. Release-blocking for the mobile feature. (Token-refresh works only if the client sends the still-valid refresh token *as the bearer*, since an expired access-token bearer is rejected first — document this client requirement, or also exempt the refresh route.)
- **Fix**: move the bootstrap routes to an OTP-authenticated public group, or add a path allowlist to `JwtAuthMiddleware`:
```php
// config/middleware.php — add a JWT-free bootstrap group
'mobile-bootstrap' => [\OwnPay\Middleware\CorsMiddleware::class, \OwnPay\Middleware\RateLimiterMiddleware::class],
// routes/api.php
$router->post('/api/mobile/v1/devices', 'Api\\Mobile\\DeviceController@pair', 'mobile-bootstrap');
$router->post('/api/mobile/v1/devices/token-refreshes', 'Api\\Mobile\\DeviceController@refresh', 'mobile-bootstrap');
// Pairing is already authenticated by the OTP (FOR UPDATE single-use); refresh by the refresh JWT in body.
```
- **Compliance Tag**: CWE-285 (Improper Authorization — over-restriction breaking the bootstrap); OWASP-A04 (Insecure Design). **→ add to the master registry alongside FIND-003.**

#### Cross-references already in the master report
- **FIND-001** — `MfsService` parser arg-swap (dead code) does not affect the live mobile ingestion path (`SmsController → processBatch → attemptParse`, correct order).
- **FIND-017** — provider-TrxID vs `op_transactions.trx_id` namespace mismatch weakens auto-matching from device SMS.
- **FIND-018** — SMS dedup keys on `(device,sender,±1s)` not `trx_id`.
- **OTP brute-force** — mitigated: the `mobile` group includes `RateLimiterMiddleware`; the OTP is single-use + 5-min. (Confirm the limiter's per-IP keying is not the only control on the pairing route once FIND-019 moves it to `mobile-bootstrap`.)

> Net: the mobile API surface is **well-designed and largely secure** (OTP handshake, JWT rotation, fingerprinting, GCM, brand scoping). The one blocker is the FIND-019 bootstrap deadlock; once fixed, the backend is integration-ready.

---

## PART 2 — Google Play SMS Permission Compliance & Approval Strategy (May 2026)

> **Grounding:** verified against Google Play's current *"Use of SMS or Call Log permission groups"* policy and the **April 10, 2025** / **July 10, 2025** policy updates. The premise — a non-default-handler app reading transactional SMS — **is permissible via an exception use case**, but approval is manual, scrutinized, and conditional. Sources at the end of this section.

### 2.1 The single dual-purpose app
One app on Play, two surfaces sharing one identity/session: **(A)** the User Dashboard Console (account, devices, transactions, balances via the mobile API), and **(B)** the background SMS transaction-matching automator. Both serve the *same* core purpose — **reconciling carrier MFS receipt SMS to OwnPay payments** — which is the framing Play approval requires (SMS access must be core, not incidental).

### 2.2 The applicable exception use cases (no default-handler requirement)
Google Play's policy lists exception use cases that may request SMS permissions **without** being the default SMS handler. Two apply directly:
- **"SMS-based financial transactions"** — *"e.g. UPI, verifications for financial transactions."* Eligible permissions include `READ_SMS`, `RECEIVE_SMS`, `RECEIVE_MMS`, `RECEIVE_WAP_PUSH`, `SEND_SMS`.
- **"SMS-based money management"** — *"e.g. apps that track and manage budget."* Eligible: `READ_SMS`, `RECEIVE_SMS`, `RECEIVE_MMS`, `RECEIVE_WAP_PUSH`.

OwnPay's matching of bank/wallet (bKash, Nagad, Rocket, …) receipt SMS to merchant transactions fits **"SMS-based financial transactions"** as the primary declaration (with "money management" as secondary framing for the merchant-side ledger view). **Declare only `RECEIVE_SMS` + `READ_SMS`** — request the minimum; do not declare `SEND_SMS` unless an outbound-SMS feature genuinely ships.

### 2.3 Permissions Declaration Form — winning strategy
The Play Console Declaration Form (mandatory; apps without it or failing it are removed) is graded on four pillars. Map each explicitly:

1. **Core functionality.** State plainly: *"The app's core function is to automatically confirm offline mobile-money payments by reading carrier transaction-receipt SMS and matching them to the user's self-hosted OwnPay gateway."* SMS access is inseparable from the primary purpose — not analytics, not growth. (Avoid letting the "Dashboard" framing dilute this; the dashboard is the management UI *for* the SMS-automation purpose.)
2. **No less-sensitive alternative.** Pre-empt Google's #1 rejection reason. Document why each alternative fails: notification-listener (`NotificationListenerService`) is explicitly **not** used (banners are unreliable, truncated, locale-dependent, and Play disfavors it for this); manual entry defeats the automation and is error-prone at volume; gateway server-to-server APIs do not exist for the bank-SMS/personal-wallet rails OwnPay targets (the receipt SMS *is* the only machine-readable confirmation). Direct `READ_SMS`/`RECEIVE_SMS` of whitelisted senders is therefore the only viable mechanism.
3. **Prominent in-app disclosure + runtime consent.** Before requesting the runtime permission, show a full-screen disclosure (not a generic OS dialog): what is accessed (only whitelisted bank/wallet sender SMS), why (auto-confirm payments), where it goes (the user's own OwnPay server, GCM-encrypted), and that it is **never** sold/shared. Gate the runtime grant behind an explicit affirmative tap; provide a working app experience if declined (manual confirmation fallback).
4. **Data-safety section honesty.** Declare SMS-message collection, mark it processed for app functionality, **not shared with third parties**, and emphasize on-device filtering + user-controlled destination (Section 3). Consistency between the declaration, the data-safety form, and the in-app disclosure is what reviewers cross-check.

### 2.4 Honest approval-risk assessment (be real)
- Even *eligible* apps face manual review and frequent first-pass rejections ("declaration does not match core functionality"). The dual-purpose framing is a **risk multiplier** — reviewers may argue the dashboard could exist without SMS. Mitigation: make the store listing, first-run experience, and feature naming center the SMS-automation purpose; keep the dashboard visibly secondary.
- The **April 2025** clarification specifically restricts financial apps from exfiltrating non-financial/personal SMS. OwnPay must be able to demonstrate (in the demo video Google requests) that it reads **only** whitelisted financial senders and discards everything else — which the privacy gate (Section 3) enforces. This is OwnPay's strongest compliance asset.
- Provide a **review demo account + screencast** showing: pairing, the disclosure, a whitelisted bank SMS matched, and a personal/OTP SMS being ignored on-device.
- **Policy can change** — re-verify the live policy and form wording at submission; treat this strategy as current-as-of May 2026, not permanent.

**Sources:**
- [Use of SMS or Call Log permission groups — Play Console Help](https://support.google.com/googleplay/android-developer/answer/10208820?hl=en)
- [Policy announcement: April 10, 2025 — Play Console Help](https://support.google.com/googleplay/android-developer/answer/15899442?hl=en)
- [Policy announcement: July 10, 2025 — Play Console Help](https://support.google.com/googleplay/android-developer/answer/16296680?hl=en)
- [Declare permissions for your app — Play Console Help](https://support.google.com/googleplay/android-developer/answer/9214102?hl=en)

---

## PART 3 — Local Privacy Gate & Data Sovereignty

### 3.1 On-device privacy filter (verified backend support)
The backend already serves the device's filter configuration: `GET /api/mobile/v1/config/filter-rules` (`ConfigController::filterRules`, JWT-authed, brand-scoped) returns:
- **`allowed_senders`** — the whitelist, derived from the brand's *active* SMS templates' `sender_pattern` (e.g. `bKash`, `Nagad`, `Rocket`). Admin-configured via the panel.
- **`positive_keywords`** — default `received, credited, TrxID, TxnID, deposited, Tk, BDT`.
- **`negative_keywords`** — default `OTP, PIN, password, verify, verification, code` — the categories the device **must ignore locally**.
- **`check_interval_hours`** — refresh cadence.

**Device-side gate (the compliance cornerstone):** the app must apply this filter **on-device, before any network transmission**:
1. Drop any SMS whose sender is not in `allowed_senders` (whitelist-first).
2. Drop any message matching `negative_keywords` (OTP/PIN/login/verification) even from a whitelisted sender.
3. Only messages passing both gates are GCM-encrypted and POSTed to `/api/mobile/v1/sms`.
This guarantees OTPs, PINs, login codes, debit alerts, and personal texts **never leave the device** — directly satisfying the April-2025 "no non-financial/personal SMS exfiltration" rule and the data-safety declaration.

### 3.2 Data sovereignty (the differentiator)
Because OwnPay is **self-hosted**, the phone transmits the whitelisted, GCM-encrypted transaction data **exclusively to the user's own OwnPay server** (`APP_URL`, configured at pairing) — there is **no OwnPay-operated cloud, no third-party transit, no shared analytics endpoint**. The AES key is per-device, generated at pairing, never leaves the trusted channel in plaintext. This is the cleanest possible posture for the Play declaration: *"data is filtered on-device and sent only to the user's own server under their full control."*

### 3.3 Hardening recommendations for the privacy gate
- **Fail-closed default**: if the device cannot fetch `filter-rules` (offline/first-run), default to an empty whitelist (send nothing) rather than an open gate.
- **Sign the config**: serve `filter-rules` over TLS only and consider signing the payload so a MITM cannot widen the whitelist.
- **Local audit trail**: keep an on-device, biometric-locked log of what was matched/ignored (see `mobile_design.md`) so users can verify the gate's behavior — also a strong artifact for Play review.
- **Belt-and-suspenders server gate**: re-apply the negative-keyword filter server-side on ingest (defense-in-depth; a compromised/old client shouldn't be able to push OTP text into storage).
