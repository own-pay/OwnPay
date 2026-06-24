# Progress — Increment 5

## Session 2026-06-23

### Done
- Read HANDOFF / CLAUDE / ROADMAP / ARCHITECTURE / API_CONTRACT / SECURITY (mobile-app).
- Read all wiring pieces: AesGcmCipher, PrivacyGate/FilterRules/GateDecision, QueuedSms/RetryPolicy,
  RawSms, SmsCapture/PlatformSmsCapture, AppConfig, ApiClient/ApiResult/AuthInterceptor, SecureStore,
  di.dart, main.dart, app.dart, ConsentStore (Hive pattern), DeviceRepository (ApiClient usage),
  DisclosureCubit, existing tests (privacy_gate, sync, permissions fakes/cubit).
- Verified backend response envelope: `apiSuccess` nests under `data` (see findings F1) — repo must unwrap.
- Confirmed planning hook (`hooks.py`) is NOT registered in settings.local.json and is print-only
  (non-blocking) even if it were.

### Decisions
- Architecture per findings F1–F5. New: FilterRulesRepository(+cache), SmsQueueStore, SmsIngestCoordinator.
- Unwrap `data` defensively in the rules repo; flag the adjacent pairing/refresh bug (don't silently fix).
- Stale cache that can't refresh → fail-closed null (never serve stale).

### Test results
- Phase 1 GREEN: `filter_rules_repository_test.dart` 8/8 pass (RED first: missing classes → impl → green).
  Covers data-envelope unwrap, flat-body fallback, fresh-cache short-circuit, stale→fail-closed,
  no-server→fail-closed, unusable-body→fail-closed, offline first-run→fail-closed.

- Phase 3 GREEN: `sms_ingest_coordinator_test.dart` 7/7 (RED first). Covers drop-vs-encrypt+enqueue
  (with decrypt round-trip), fail-closed→nothing, drain idempotency (sequential + concurrent coalesce),
  no-plaintext, no-AES-key guard (buffer preserved), onPending nudge.
- Phase 2/4 GREEN: `HiveSmsQueueStore` (monotonic localId, encrypted-only rows), DI registrations,
  `main.dart` lifecycle (start() + cold-start drain + resume observer).
- Fixed strict-lint: switched the 3 new ctors to positional initializing formals (codebase convention;
  named params can't be private) + 2 const literals in repo test.

### Final verification (2026-06-23)
- `flutter analyze` → **No issues found!**
- `flutter test` → **All tests passed! (53)** = 38 prior + 15 new (8 repo + 7 coordinator).
- APK build NOT run: no `android/` changes (per verification bar it's only required when native changes).

### Increment 5 — COMPLETE.

## Session 2 (2026-06-23) — Phase 6: fix the F1 envelope bug (user-requested)
- TDD: wrote `test/pairing/device_repository_test.dart` against the REAL ApiClient + mock Dio → RED
  (pair/refresh returned false: read tokens at the wrong level), reproducing F1 exactly.
- Fix: `core/network/api_client.dart` now `_unwrap`s the `{success,data:{…}}` envelope centrally → GREEN.
- Also fixed the error path: `_mapError` read a non-existent top-level `message`; now reads the server's
  `error` string → 4xx surfaces real messages. Added `test/network/api_client_test.dart` (7: unwrap nested/
  no-data/list + POST, error-mapping 4xx/401/5xx).
- Simplified `network_filter_rules_repository._parse` (dropped redundant local unwrap); updated its test to
  feed unwrapped bodies; removed the now-obsolete flat-body test.
- Verified: `flutter analyze` → No issues; `flutter test` → **All passed (62)** (+9 net).
- Docs: API_CONTRACT.md §1/§3/§7/§12 (envelope corrected), ARCHITECTURE.md §6, HANDOFF/ROADMAP, memory.
- NEW flag (separate, NOT fixed): `DeviceRepository.refresh()` sends no `X-Device-Fingerprint` → backend
  422 → live refresh blocked. Documented in API_CONTRACT §3(2) + HANDOFF. Needs user's call.

## Session 3 — Phase 7: wire the refresh device fingerprint ("fix the fix")
- Backend traced (evidence, no guessing): `DeviceController::pair` passes body `device_id` as the
  `$fingerprint` arg → `pairDevice` stores `jwt_fingerprint = sha256(device_id)`; `refreshAccessToken`
  compares `sha256(<sent fingerprint>)`. `JwtAuthMiddleware` does NOT check the fingerprint and
  `validateRequest` is never called → fingerprint is **refresh-only**. `Request::input` reads JSON body.
- TDD: extended `device_repository_test.dart` refresh test to capture the posted body + assert
  `fingerprint == 'device-1'` → RED (was null). Fix: `refresh()` sends `getOrCreateDeviceId()` as
  `fingerprint` → GREEN.
- Verified: `flutter analyze` → No issues; `flutter test` → **All passed (62)** (test modified, not added).
- Docs: API_CONTRACT §3(2) + request example, HANDOFF (fingerprint ✅ + live-test note), ROADMAP Phase 1 +
  inc-5 entry, memory. Client auth flow now complete for live testing — no known client-side blockers.

### Next session = Increment 6 (sync worker). Device-boundary items unchanged (on-device SMS, foreground
### service, boot restart, live-server AES/JWT interop on a real device + SIM + reachable server).
