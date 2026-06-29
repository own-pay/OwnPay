# Progress - Increment 6 (sync engine)

## Session 2026-06-24

### Context

- Increment 5 + the ApiClient envelope fix + refresh-fingerprint fix are all done & green (62 tests).
- Starting inc 6 sync engine. Plan + findings written.

### Done (all phases complete - sync engine)

- P1: `QueuedSms.toApi()` → UTC `received_at` (TDD: endsWith('Z') RED→GREEN). toMap left local (round-trip).
- P2: `SmsQueueStore` + `HiveSmsQueueStore` gained syncable/markApproved/markFailed(retry++)/purgeApproved.
  Real-Hive temp-box test (8) - enqueue/filter/retry/purge/durability.
- P3: `SyncWorker` (serialized syncNow; batch→POST→map results; accepted→approved/else→failed+retry; auth→
  onReauthRequired+stop+no-bump; transport→failed+retry). Test (7): accepted/rejected/5xx/401, multi-batch,
  not-paired, no-plaintext, exhausted-skip. (RED on missing class → GREEN.)
- P4: `SyncWorker.start()` connectivity-up trigger (verified connectivity_plus v7 API via context7 =
  `Stream<List<ConnectivityResult>>`). Coordinator post-enqueue kick (`onEnqueued`, +1 test). DI registers
  SyncWorker + wires the kick. `main` starts worker + syncs on cold-start/resume.
- P5: docs (ROADMAP, HANDOFF, ARCHITECTURE §4.5, API n/a), memory.

### Test results

- `flutter analyze` → No issues. `flutter test` → **All passed (79)** (+17 this increment). No android/ change.

### Increment 6b (audit UI) - COMPLETE

- `AuditEntry` (metadata-only, no payload), `AuditCubit` (load/filter/clearFailed/retryNow), `AuditView`
  (SegmentedButton, status chips Confirmed/Failed/Queued, confirmed clear-failed, empty states).
- `SyncWorker implements Syncer` (presentation depends on the `Syncer` domain interface, not the worker).
- `SmsQueueStore.deleteFailed()` (+ Hive impl + real-Hive test). `/audit` route + Home AppBar entry
  (`context.push`); `main` cold-start `purgeApproved`. Shared `test/audit/audit_fakes.dart`.
- Verified: analyze clean; `flutter test` **All passed (90)** (+11). No android/ change.
- Docs: ROADMAP, HANDOFF, memory. (Did NOT re-attest: `.active_plan` was intentionally repointed to a
  different (WC gateway) plan by the user - left as-is; planning hooks are inactive anyway.)

### Next = Increment 7 (dashboard 7a → re-pair/lifecycle 7b → settings 7c → notifications 7d)
