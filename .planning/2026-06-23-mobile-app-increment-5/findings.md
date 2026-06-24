# Findings — Increment 5

## F1 — CRITICAL: server wraps API payloads under `data` (contract doc is wrong/flat)
- `Response::apiSuccess($data)` (`src/Http/Response.php:92`) emits `{"success":true,"data":{…}}`.
- `ConfigController::filterRules` (`src/Controller/Api/Mobile/ConfigController.php`) returns
  `apiSuccess($data)` → real body is `{"success":true,"data":{version,allowed_senders,…}}`.
- But `docs/API_CONTRACT.md` §12 documents a **flat** shape (fields at top level). The §0 header warned
  "verify exact field names against the controllers." Verified — the doc is stale.
- **Impact on Inc 5:** if I pass the whole response to `FilterRules.fromApi`, it finds no `allowed_senders`
  at top level → empty whitelist → **permanent fail-closed even when the server returns valid rules**
  (silent — the whole SMS feature would never forward anything). So the repo MUST unwrap `data`.
- **Defensive design:** unwrap `data` if present, else use the body as-is (tolerates the flat doc shape).
- **Adjacent latent bug (OUT OF SCOPE — flag, don't fix silently):** `DeviceController::pair`/`refresh`
  also use `apiSuccess`, so the real pair/refresh bodies nest tokens under `data`. But
  `features/pairing/data/device_repository.dart` reads `value['access_token']` at the **top level** →
  pairing/refresh parse the wrong level and will fail against a real server ("pairing response was
  incomplete" / refresh returns false → re-pair loop). Untested (no device/pairing unit test exists);
  only surfaces on live-server interop (the user's verification boundary). Root-cause fix = unwrap `data`
  centrally in `ApiClient` (would fix pair/refresh/filter-rules at once, no existing tests to break).
  Flagged for the user; not changing "verified" Inc 1/2 infra inside the Inc 5 increment.

## F2 — Fail-closed semantics (locked per SECURITY.md §2 + CLAUDE.md §2.3)
- Fail-closed triggers: rules **missing / first-run / expired(stale) / fetch-error** ⇒ empty whitelist ⇒
  drop everything. `PrivacyGate.evaluate(sms, null)` → `droppedNoRules`. Staleness is NOT checked by the
  gate — the **repository** must enforce it: a stale cache that can't be refreshed returns `null`
  (do NOT serve stale rules).
- Tradeoff noted for the user: during a long offline period (cache stale > `check_interval_hours`),
  legit financial SMS drained in that window are dropped per spec. Documented behavior; a future grace
  period / buffer-preserve is a conscious product decision, not something to slip in now.

## F3 — Existing pieces to REUSE (don't duplicate their tested logic)
- `core/crypto/aes_gcm_cipher.dart` — `encryptToEnvelope({plaintext, keyBytes})`, `keyFromHex(hex)`
  (32-byte key, base64 `IV‖ct‖tag`). 6 tests already green.
- `features/privacy_gate/domain/{privacy_gate,filter_rules,gate_decision}.dart` — `PrivacyGate.evaluate`,
  `FilterRules.{fromApi,fromCache,toCache,isStale,isFailClosed,empty}`. 16 tests green.
- `features/sync/domain/queued_sms.dart` — `QueuedSms` holds **encrypted payload only** (no `body` field);
  `toMap`/`fromMap` (Hive-friendly primitives), `toApi`. 7 tests green.
- `features/sms_capture/{domain/sms_capture.dart,data/platform_sms_capture.dart}` — `drainPending()`
  pulls+clears native buffer (exactly-once); `onPending` nudge stream.
- `core/storage/secure_store.dart` — `readAesKey()` (hex-64), `readServerUrl()`.
- `core/network/api_client.dart` — `get(url,{auth})`/`post(...)` → `ApiResult<Map<String,dynamic>>`
  (full body; does NOT unwrap `data`). Callers pass fully-qualified URL = `serverUrl + AppConfig.apiPrefix`.

## F4 — Conventions to match
- DI: manual `get_it` in `app/di.dart` (no codegen). Domain interface + data impl (ARCHITECTURE §2).
- Tests: hand-written fakes for domain interfaces (see `test/permissions/permission_fakes.dart`);
  `mocktail` available for concrete infra (`ApiClient`, `SecureStore`). Existing tests avoid real Hive →
  abstract Hive behind interfaces (`FilterRulesCache`, `SmsQueueStore`) and use in-memory fakes.
- Strict lints (`analysis_options.yaml`): `avoid_print`/`avoid_dynamic_calls` = error; `strict-casts`,
  `strict-raw-types`; `unawaited_futures`, `prefer_final_locals`, `prefer_const_*`.
- Hive boxes opened lazily + cached (see `HiveConsentStore`). `Hive.initFlutter()` already in `main`.
- Money never a double (N/A here — no money handled in Inc 5).

## F6 — received_at timezone fidelity (pre-existing; bites in Inc 6, not Inc 5)
- `RawSms.fromChannel` → `DateTime.fromMillisecondsSinceEpoch(ts)` = a **local** DateTime (correct
  instant). `QueuedSms.toApi()` sends `received_at: receivedAt.toIso8601String()` — and Dart omits the
  offset/`Z` for **local** DateTimes → e.g. `2026-06-23T16:30:00.000` (naive). API_CONTRACT example uses
  an explicit offset (`…+06:00`). A server that assumes UTC would be off by the device offset → wrong
  transaction-match windows. CLAUDE.md §5 wants ISO-8601 **with timezone**.
- Pre-existing (QueuedSms/RawSms from inc 3–4, already tested); not in Inc 5's persistence path. The Inc 6
  sync worker is where it's POSTed — fix there: `receivedAt.toUtc().toIso8601String()` (unambiguous `Z`)
  or include the offset. Out of Inc 5 scope; flagged in HANDOFF Inc 6.

## F5 — Lifecycle
- `main.dart` runs `Hive.initFlutter()` → `configureDependencies()` → resolves start route. Add coordinator
  `start()` + initial `drainNow()` here. App shell `OwnPayConsoleApp` is Stateless → add a lifecycle
  observer (thin adapter in `main`/`core/services`) to `drainNow()` on `resumed`. Keep coordinator pure
  Dart (unit-testable) — the Flutter lifecycle adapter stays out of the coordinator.
