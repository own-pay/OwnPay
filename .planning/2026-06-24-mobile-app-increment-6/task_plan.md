# Task Plan - Mobile App Increment 6 (sync worker)

**Goal:** Drain the encrypted `sms_queue` to the server and reflect outcomes, no stubs. Completes the
capture→sync pipeline. End green on `flutter analyze` + `flutter test`.

**This turn = the sync ENGINE** (queue ops + worker + triggers + exhaustive tests + the `received_at` TZ
fix). The **audit-trail UI** (presentation: All|Synced|Issues, clear-failed) is the next focused unit (6b).

Non-negotiables (mobile-app/CLAUDE.md §2): encrypted payload only on the wire (never plaintext); secrets
only in Keystore; never log SMS/tokens/keys; no silent drops - a failed SMS stays queued and is retried;
money never a double. Reuse `RetryPolicy`, `QueuedSms`, `ApiClient` (now unwraps `data`).

---

### Phase 1 - `received_at` timezone fix (wire only)

**Status:** complete

- `QueuedSms.toApi()` → `received_at: receivedAt.toUtc().toIso8601String()` (unambiguous `Z`). Leave
  `toMap()` (local cache) untouched so the Hive round-trip test stays valid. TDD: assert `endsWith('Z')`.

### Phase 2 - Extend `SmsQueueStore` (interface + Hive impl)

**Status:** complete

- `syncable({required int maxRetries, int limit})` (status ∈ {pending,failed} ∧ retry<max, oldest first),
  `markApproved(localId, serverRef)`, `markFailed(localId, reason)` (status=failed, retry++),
  `purgeApproved(DateTime olderThan)` → count. Keyed by `localId`.
- Real-Hive unit test (temp dir) for the new logic (retry++ / filter / purge).

### Phase 3 - `SyncWorker` (core, fully unit-tested)

**Status:** complete

- `features/sync/data/sync_worker.dart`: serialized `syncNow()` (like `SmsIngestCoordinator`). Loop: take a
  batch of `syncable` → `ApiClient.post('…/sms', {messages:[toApi…]})` → read `results` (ApiClient already
  unwraps) → per `local_id`: `accepted → markApproved`; else → `markFailed(retry++)`. Keep draining while a
  batch fully succeeds; stop on the first failed row or transport error.
- `Err(AuthFailure)` → call `onReauthRequired` once, **stop, do NOT bump retry** (not the row's fault).
  `Err(other)` → markFailed the batch (retry++), stop (backoff handles the next attempt).

### Phase 4 - Triggers + DI wiring

**Status:** complete

- `start()` subscribes to `connectivity_plus` onConnectivityChanged (sync when back online); post-enqueue
  kick from `SmsIngestCoordinator`; resume + cold-start in `main`. Register in `app/di.dart`. (Verify the
  connectivity_plus v7 API via context7 before wiring.)

### Phase 5 - Green + docs

**Status:** complete

- analyze clean + test green. Update ROADMAP Phase 3, HANDOFF (inc 6 engine done; UI next), ARCHITECTURE
  §4.5 if needed, memory.

---

## Increment 6b - audit-trail UI (user: "yes")

### Phase 6 - Audit domain + cubit (+ `deleteFailed` queue op)

**Status:** complete

- `features/audit/domain/audit_entry.dart`: `AuditEntry` value object built from `QueuedSms` - carries
  ONLY safe metadata (localId, sender, receivedAt, status, retryCount, failureReason, serverRef);
  **structurally no `encryptedPayload`** (SECURITY.md §7/§9 - defense-in-depth).
- `features/audit/presentation/audit_cubit.dart`: `AuditFilter{all,synced,issues}` + `AuditState`
  (loading, entries, filter; `visible`/`failedCount`) + `AuditCubit` (load/setFilter/clearFailed/retryNow).
- `SmsQueueStore.deleteFailed()` (+ Hive impl + the 2 existing test fakes + Hive test case).
- TDD: `test/audit/audit_cubit_test.dart` - load (newest-first), filter mapping, clearFailed deletes only
  failed + reloads, retryNow calls `SyncWorker.syncNow` + reloads.

### Phase 7 - Audit screen/view + widget tests

**Status:** complete

- `audit_screen.dart`: `AuditScreen` (BlocProvider → `..load()`) + `AuditView` (SegmentedButton filter,
  metadata list with status chips, empty states, Retry-now action, Clear-failed w/ confirm).
- `test/audit/audit_view_test.dart`: renders metadata, filter switches, **never renders the payload**,
  clear-failed/retry wired, empty states.

### Phase 8 - Routing + entry point + purge wiring

**Status:** complete

- `/audit` route; a Home AppBar action → `/audit`; `main` cold-start `purgeApproved(now − syncedRetention)`.

### Phase 9 - Green + docs

**Status:** complete

- analyze + test green; ROADMAP Phase 3 audit line → done; HANDOFF (6b done; next = inc 7); memory.

## Errors Encountered

| Error | Attempt | Resolution |
|-------|---------|------------|
| (none yet) | | |
