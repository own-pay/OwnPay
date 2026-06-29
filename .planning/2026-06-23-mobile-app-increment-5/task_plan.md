# Task Plan - Mobile App Increment 5 (capture consumer + FilterRules)

**Goal:** Wire the OwnPay Console capture pipeline end-to-end with no stubs:
`SmsCapture.drainPending()` → `PrivacyGate.evaluate` → drop non-passers (never persist) →
passers `AesGcmCipher.encryptToEnvelope` → enqueue `QueuedSms` (encrypted only) in Hive.
Plus a fail-closed `FilterRules` source (fetch `GET /config/filter-rules`, cache in Hive,
refresh per `check_interval_hours`). End green on `flutter analyze` + `flutter test`.

**Scope guardrails (from mobile-app/CLAUDE.md §2):** fail-closed gate; secrets only in Keystore;
never log SMS bodies/tokens/keys; money never a double; no stubs/placeholders; reuse existing
`FilterRules`/`PrivacyGate`/`AesGcmCipher`/`QueuedSms` (don't duplicate their tested logic).

---

### Phase 1 - FilterRules source (fetch + cache + fail-closed)

**Status:** complete

- `features/privacy_gate/domain/filter_rules_repository.dart` - `FilterRulesRepository` interface
  (`Future<FilterRules?> effectiveRules()`).
- `features/privacy_gate/data/filter_rules_cache.dart` - `FilterRulesCache` interface + `HiveFilterRulesCache`
  (box `AppConfig.boxFilterRules`).
- `features/privacy_gate/data/network_filter_rules_repository.dart` - fetch via `ApiClient`, **unwrap the
  `{success,data:{…}}` envelope** (see findings F1), parse `FilterRules.fromApi`, cache, fail-closed.
- Logic: fresh cache → return; missing/stale → fetch; fetch ok → cache+return; fetch fail → `null`
  (fail-closed; **never** serve a stale cache).

### Phase 2 - Offline queue store

**Status:** complete

- `features/sync/domain/sms_queue_store.dart` - `SmsQueueStore` interface (`enqueue`, `all`, `count`).
- `features/sync/data/hive_sms_queue_store.dart` - box `AppConfig.boxSmsQueue`, key = monotonic `localId`,
  value = `QueuedSms.toMap()` (encrypted payload only).

### Phase 3 - Ingest coordinator (drain → gate → encrypt → enqueue)

**Status:** complete

- `features/sms_capture/data/sms_ingest_coordinator.dart` - `SmsIngestCoordinator`.
- Serialized/re-entrant-safe `drainNow()`; `start()` subscribes to `SmsCapture.onPending`.
- Guard: no AES key (unpaired) → skip drain (preserve native buffer). Passers encrypted with key from
  `SecureStore.readAesKey()`; non-passers dropped (never persisted).

### Phase 4 - Wiring (DI + lifecycle)

**Status:** complete

- `app/di.dart` - register PrivacyGate, cache, repo, queue store, coordinator.
- `main.dart` - `coordinator.start()` + initial drain on cold start; drain on `AppLifecycleState.resumed`.

### Phase 5 - Tests + green bar + docs

**Status:** complete

- `test/privacy_gate/filter_rules_repository_test.dart` - unwrap, fresh-cache, stale→fail-closed, offline→fail-closed.
- `test/sms_capture/sms_ingest_coordinator_test.dart` - drop vs encrypt+enqueue, idempotency, fail-closed,
  no-plaintext, no-key guard, onPending trigger.
- `flutter analyze` clean + `flutter test` all green. Update ROADMAP.md + HANDOFF.md. Flag pairing bug (F1)
  - device-boundary items.

---

### Phase 6 - Fix the F1 nested-`data` bug centrally (user-requested follow-up)

**Status:** complete

- Root cause (F1): server wraps every success as `{success,data:{…}}`; `ApiClient` passed the whole body
  through, so `device_repository` (top-level reads) couldn't parse pair/refresh.
- Fix: unwrap `data` once in `core/network/api_client.dart` (fixes pair + refresh + future callers).
- TDD regression guard: `test/pairing/device_repository_test.dart` (pair/refresh extract tokens from the
  real nested envelope via the real ApiClient + mock Dio) - RED before fix, GREEN after.
- `test/network/api_client_test.dart` - pins the unwrap contract (nested→inner, no-data/list→passthrough).
- Simplify `network_filter_rules_repository._parse` (drop the now-redundant local unwrap); update its test
  to feed unwrapped bodies (what the real ApiClient now returns).
- Docs: correct `docs/API_CONTRACT.md` (envelope + shapes show inner `data`), `ARCHITECTURE.md` §6
  (ApiClient unwraps), HANDOFF/ROADMAP (bug FIXED), findings/progress/memory.
- NOTE (new, separate - flag, don't fix here): `device_repository.refresh()` sends **no device
  fingerprint**, but the backend `DeviceController::refresh` requires `X-Device-Fingerprint` → live
  refresh would 422 regardless of this fix. Flagged for the user.

### Phase 7 - Wire the refresh device fingerprint (user: "fix the fix")

**Status:** complete

- Backend traced (no assumptions): `DeviceController::pair` passes the request `device_id` as the
  `$fingerprint` arg → `pairDevice` stores `jwt_fingerprint = sha256(device_id)`. `refreshAccessToken`
  compares `sha256(provided fingerprint)` to that. `JwtAuthMiddleware` does NOT check the fingerprint, and
  `DevicePairingService::validateRequest` is never called → fingerprint is **refresh-only**.
- `Request::input('fingerprint')` reads the JSON body → send `fingerprint` in the refresh body (no
  ApiClient change). Value MUST be the same `SecureStore.getOrCreateDeviceId()` used as `device_id` at pairing.
- Fix: `device_repository.dart::refresh()` adds `'fingerprint': getOrCreateDeviceId()` to the body.
- TDD: extend `device_repository_test.dart` refresh test to capture the posted body and assert it carries
  `fingerprint` = the paired device id - RED before, GREEN after.
- Docs: mark fingerprint RESOLVED in API_CONTRACT §3(2), HANDOFF, ROADMAP Phase 1, memory.

## Errors Encountered

| Error | Attempt | Resolution |
|-------|---------|------------|
| (none yet) | | |
