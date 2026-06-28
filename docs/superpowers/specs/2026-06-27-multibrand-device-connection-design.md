# Multi-Brand Device Connection & Premium Pairing UX — Design Spec

> **Status:** Draft for review · **Date:** 2026-06-27 · **Branch:** `fixing`
> **Scope decision:** PHP backend + admin panel only. The Flutter app (`mobile-app/`) is **out of scope** — it pairs identically regardless of brand scope, so no client change is required.

---

## 1. Summary

Make the OwnPay companion-device system **multi-brand aware** and give the admin pairing flow a **real-time, premium feel**:

1. A device paired from the **All-Brands** view verifies SMS for **every** brand. A device paired from a **specific brand** verifies **only** that brand. (Brand scope is implicit in *where* you pair — no new toggle.)
2. The admin **Pair New Device** dialog updates **itself** the moment a phone finishes pairing, showing a **"Device connected"** success state — no manual refresh.
3. The device list shows **live connection status** (Online / Idle / Revoked) with a **Refresh** control and light auto-refresh.
4. A **Revoked** device **freezes**: its live status stops updating and can never flip back to "Connected" without re-pairing.

---

## 2. Current state (verified in code)

- **Pairing** — `DevicePairingService::generatePairingOtp()` / `pairDevice()`; admin `DeviceController::generateOtp()` (`POST /admin/devices/generate-otp`) returns OTP + QR SVG; the device pairs via `POST /api/mobile/v1/devices` and is stored in `op_paired_devices` bound to **one** `merchant_id`.
- **Brand scope at pairing already works the way we want:** `generateOtp()` uses `BrandContext::getWriteMerchantId()` — which returns `getPlatformId()` in the All-Brands view and the active brand id otherwise. So an All-Brands pairing already stores the device under the platform-owner id; a brand pairing stores it under that brand.
- **SMS verification is hard-scoped to one brand at every stage:** `SmsController::receive()` reads `merchant_id` from the device JWT → `SmsParserService::processBatch($deviceId, $mid, …)` stores rows in `op_sms_parsed` with that `merchant_id` → `SmsVerificationJob::run()` groups pending rows by `merchant_id` and matches `forTenant($mid)`. A **brand-scoped device therefore already verifies only its own brand** (requirement already satisfied).
- **The all-brands gap:** an All-Brands device's SMS is stored under the *platform* `merchant_id`, and the job matches it against the *platform owner's* transactions — of which there are none. So an all-brands device currently verifies nothing.
- **Groundwork already in the data layer (currently unused):**
  - `TransactionRepository::findByProviderTrxId(string)` does a **global** (unscoped) lookup when no tenant is set — i.e. via `forAllTenants()`.
  - `TransactionRepository::findPendingMatchGlobal(amount, gateway, receivedAt)` **already exists and already enforces the money-safety guard**: with a `receivedAt` window it returns a match **only when exactly one** pending transaction matches across all brands (`count !== 1 → null`). It is **defined but never called** today.
  - `BrandContext::getPlatformId()` resolves the platform-owner id via `is_platform = 1` (never hard-coded).
- **Admin modal** (`templates/admin/devices/index.twig` + `public/assets/js/pages/devices.js`): Phase 1 (generate) and Phase 2 (code + QR + countdown) only. **No "connected" state, no polling.** `GET /admin/devices/check-status` exists (returns `{paired: bool}` for the brand) but the JS never calls it.
- **Revoke** works (`DeviceController::revoke` / `bulkRevoke`; `DevicePairingService::revoke`). `JwtAuthMiddleware` (H-04) already denies revoked devices at the API, so revoked devices already cannot submit SMS.

---

## 3. Decisions (from brainstorming)

| # | Decision |
|---|---|
| D1 | **Scope:** backend + admin panel only; Flutter unchanged. |
| D2 | **Brand scope is implicit by pairing view** — All-Brands pairing → all brands; brand pairing → that brand only. No new UI control. |
| D3 | **Revoked = frozen** — the live status guarantee (display side). SMS rejection for revoked devices is already enforced and is not re-implemented. |
| D4 | **Verification model:** *resolve-on-match in the cron* (Approach A) — leave submission/storage untouched; teach `SmsVerificationJob` to match platform-scoped SMS globally and rebind to the resolved brand. |

---

## 4. Goals / Non-goals

**Goals**
- All-brands device verifies across every brand; brand device stays scoped to its brand.
- Money-safe cross-brand matching: never auto-complete when the brand is ambiguous.
- Admin pairing dialog auto-advances to a "Device connected" state on success.
- Live per-device status with manual + auto refresh; revoked devices frozen.
- Full audit/ledger trail preserved for cross-brand completions.

**Non-goals**
- No Flutter app changes.
- No new "scope" column or toggle on devices.
- No change to the SMS submission/parse path or the on-device privacy gate.
- No real-time transport beyond polling (no websockets/SSE — matches the existing poll-based architecture and strict checkout CSP).

---

## 5. Design — multi-brand SMS verification (backend)

### 5.1 Scope is implicit (D2)
No schema change. A device's `op_paired_devices.merchant_id` is the platform id (all-brands) or a brand id (brand-scoped), already set correctly at pairing via `getWriteMerchantId()`. "Platform-scoped" ≡ `merchant_id === BrandContext::getPlatformId()`.

### 5.2 `SmsVerificationJob::run()` changes (Approach A)
The job keeps its current per-`merchant_id` grouping. For each pending SMS it branches on whether the row is **platform-scoped**:

- **Brand-scoped row** (`merchant_id !== platformId`) — **unchanged**: `trx_id` via `forTenant($mid)->findByProviderTrxId()`, then amount/gateway via `findPendingMatch($mid, …)`. Complete + ledger under `$mid`.
- **Platform-scoped row** (`merchant_id === platformId`, i.e. an all-brands device) — **match globally:**
  1. **`trx_id` first (unique, safe):** `forAllTenants()->findByProviderTrxId($trxId)`.
  2. **Amount + gateway fallback (guarded):** `findPendingMatchGlobal($amount, $gateway, $receivedAt)` — already returns a row **only when exactly one** brand matches in the time window; ambiguous → `null` (we then leave the SMS pending / flag `admin_review`, never guess which brand gets paid). This fallback is attempted **only when `received_at` is present** (the parser stores it, so it almost always is); without a time window a global amount match is **refused outright** rather than risk paying a guessed brand.
  3. On a confirmed match, the **resolved brand** is `(int) $transaction['merchant_id']`. **Rebind** the `op_sms_parsed` row to that brand (update its `merchant_id` and link the transaction), then `TransactionService::complete($txId, $resolvedBrand)` and `LedgerService::recordPaymentReceived($resolvedBrand, …)` — the same calls used today, under the resolved brand.

`SmsVerificationJob` gains a dependency to resolve the platform id (inject `BrandContext`, or a thin `is_platform` lookup). The existing `db->transaction(...)` wrapper around link + complete + ledger is retained for atomicity.

### 5.3 Net behavior
For any brand X: verification is fed by **both** X's own device **and** the all-brands device; X's own device never affects any other brand. Matches your requirement exactly.

---

## 6. Design — "Device connected" moment (admin modal)

Detection is **timestamp-based, no schema change**:

- `DeviceController::generateOtp()` also returns the server `generated_at` (microsecond `DateHelper` timestamp) in its JSON.
- **New** `GET /admin/devices/pairing-status?since=<generated_at>` → resolves the current **write**-brand (same `getWriteMerchantId()` used to issue the OTP, so it works for both all-brands and brand pairings) and returns the newest **active** device with `paired_at >= since`:
  - found → `{connected: true, device_name, platform, paired_at}`
  - none → `{connected: false}`
- **JS:** while Phase 2 (code/QR) is visible, poll `pairing-status` every **3s**. Stop when connected (→ Phase 3) or when the OTP countdown hits 0 (→ existing "expired" state). Phase 2 gains a subtle "Waiting for your phone…" pulse.
- **Phase 3 (new, premium):** success check animation, **"\<device name\> connected"** with platform, and the underlying device table refreshes (re-fetch statuses). A "Done" button closes the modal.

Repo addition: `PairedDeviceRepository::findNewestActiveSince(int $merchantId, string $since): ?array`.

---

## 7. Design — live status + refresh (device list)

- **New** `GET /admin/devices/statuses` → uses the **same brand scoping as the device list** (`getActiveBrandId()`; in the All-Brands view this shows every device) and returns a per-device array: `device_id`, `device_name`, `platform`, `status`, `last_heartbeat`, `paired_at`, and a derived **`online`** boolean.
- **Online derivation:** `status === 'active'` **AND** `last_heartbeat` within `ONLINE_THRESHOLD_SECONDS` (default **180s**). Mapping: active + recent → **Online** (green, pulsing dot); active + stale → **Idle** (grey); `revoked` → **Revoked** (red).
- **JS:** the "Connected Devices" table gets a **Refresh** button (manual re-fetch) **plus** light auto-refresh every **20s** while the Devices tab is active. Renders the live dot + relative "last seen" (e.g. "2 min ago").

---

## 8. Design — revoked = frozen (D3)

- A revoked device renders **Revoked** and is **excluded from the online/last-seen update path**: `statuses` returns `online: false` for it unconditionally, and the client freezes its last-seen at the revocation point. It can never show Online/Connected again; re-pairing creates a fresh record.
- This is the **display** guarantee only. SMS rejection for revoked devices remains enforced by `JwtAuthMiddleware` (H-04) and is not duplicated.

---

## 9. API / contract changes

| Endpoint | Change |
|---|---|
| `POST /admin/devices/generate-otp` | Response gains `generated_at`. |
| `GET /admin/devices/pairing-status` | **New.** Query `since`; returns `{connected, device_name?, platform?, paired_at?}`. |
| `GET /admin/devices/statuses` | **New.** Returns per-device live-status array (incl. derived `online`). |

All three live in the existing `admin` middleware group (auth + CSRF). `GET /admin/devices/check-status` remains for backward compatibility; the richer `statuses` supersedes it in the UI.

No mobile (`/api/mobile/v1/*`) contract changes.

---

## 10. Data model

**No schema change.** Reuses `op_paired_devices` (`merchant_id`, `status`, `last_heartbeat`, `paired_at`) and `op_sms_parsed` (`merchant_id`, `match_status`, `transaction_id`). Cross-brand resolution mutates only existing columns (rebind `op_sms_parsed.merchant_id`, set `transaction_id`, `match_status`).

---

## 11. Security & authorization

- Reaching the All-Brands view already requires `brands.access_all` (or superadmin), enforced in `BrandController::switchBrand`. An all-brands device is therefore paired as a privileged admin — the existing fail-closed pairing logic (device bound to the OTP's `created_by`, never silently escalated) is unchanged.
- Cross-brand completion runs server-side in the cron, inside a DB transaction, with the existing ledger + `mobile.sms.matched` audit trail, now attributed to the resolved brand.
- New admin endpoints are read-only status queries in the authenticated `admin` group; no new mutation surface.

---

## 12. Edge cases

- **Ambiguous amount across brands** → `findPendingMatchGlobal` returns null → SMS stays pending (eligible for `admin_review`); never auto-paid to a guessed brand.
- **`trx_id` present but unmatched** → falls through to the amount/gateway guard; if still unmatched, stays pending (current behavior).
- **No `receivedAt` on a platform-scoped amount match** → the global amount fallback is **not attempted** (refused), because the no-window path cannot enforce the single-brand guard. `trx_id` matching is unaffected. Brand-scoped matching keeps its existing behavior.
- **Two devices pair within one modal session** → `pairing-status` returns the newest since `generated_at`; the success state shows that device. Acceptable.
- **Platform owner has its own (test) transactions** → global matchers operate on real pending transactions regardless of owner; the resolved brand is whatever the matched transaction's `merchant_id` is.
- **Revoked device still holding a valid JWT** → already blocked by H-04; status endpoints additionally report it frozen.

---

## 13. Testing strategy

- **Unit — `SmsVerificationJob`:** (a) brand-scoped row unchanged; (b) platform-scoped `trx_id` global match completes under the resolved brand; (c) platform-scoped single amount match completes; (d) **ambiguous multi-brand amount → no completion, stays pending**; (e) rebind sets `op_sms_parsed.merchant_id` to the resolved brand.
- **Unit — status derivation:** online within threshold, idle when stale, revoked → frozen/`online:false`.
- **Integration — admin endpoints:** `pairing-status` transitions false→true when a device pairs after `since`; `statuses` shape + online flag; revoked freeze.
- **Regression:** existing `DevicePairingServiceTest`, `SmsParsingIntegrationTest`, `SmsParserServiceTest` stay green.
- Bar: `php vendor/bin/phpunit` green; `$LastExitCode -eq 0`.

---

## 14. Documentation updates

- `ARCHITECTURE.md` §5.2 (Companion Device Pairing): add the **all-brands resolve-on-match** rule, the **money-safety ambiguity guard**, and the **revoked-freeze** display guarantee.
- If the new endpoints are part of any documented admin API surface, note them; otherwise admin-internal.

---

## 15. Out of scope

- Flutter app (`mobile-app/`) changes.
- Websocket/SSE push, device-side notifications of brand scope.
- Any change to the on-device privacy gate, SMS parsing tiers, or submission path.
