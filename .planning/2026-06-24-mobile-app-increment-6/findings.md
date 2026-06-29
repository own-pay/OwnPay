# Findings - Increment 6 (sync engine)

## Design decisions

- **TZ fix scope:** only `QueuedSms.toApi()` (the wire) → `toUtc().toIso8601String()`. NOT `toMap()` -
  Hive round-trip stores/parses LOCAL and `DateTime ==` requires matching `isUtc`, so converting toMap to
  UTC would break the existing `map round-trip preserves fields` test. Wire = UTC; local cache = local.
- **No `syncing` status transition:** the worker is serialized (single in-flight), so marking rows
  `syncing` adds no safety but risks crash-stuck rows (syncable = {pending,failed} wouldn't reclaim them).
  Rows go pending/failed → approved | failed. The `SyncStatus.syncing` enum value stays reserved/unused.
- **Result mapping:** `accepted → markApproved(+serverRef)`. Any other per-row status (rejected/error/
  unknown/missing) → `markFailed(retry++)` - no terminal "rejected" status exists, so it becomes a
  retryable `failed` row that the user sees in the audit UI; after `maxRetries` it stops being syncable
  (stuck failed, user can clear). No infinite retry.
- **Auth vs transport failure:** `AuthFailure` (401 the interceptor couldn't refresh) → fire
  `onReauthRequired`, STOP, and do NOT bump retry (auth, not the row, is broken; rows sync after re-pair).
  `NetworkFailure`/`ServerFailure` → markFailed the batch (retry++ per ARCHITECTURE §4.5), stop, let a
  later trigger retry (connectivity-up / periodic / backoff).
- **Backoff:** worker-level, not per-row timestamps (no `nextAttemptAt` field on QueuedSms → don't bloat
  tested code). Triggers (connectivity-up, post-enqueue, resume) drive re-attempts; `RetryPolicy.maxRetries`
  caps total attempts. Connectivity-up retries immediately (good reason); periodic uses backoff if added.
- **Re-pair signal:** `SyncWorker` takes an optional `onReauthRequired` callback (inc 7 wires it to the
  re-pair flow). Tested by passing a recording callback.

## Reuse / contracts

- `ApiClient.post` now UNWRAPS `{success,data:{…}}` → worker reads `results` straight off the returned map
  (`data.results`). Errors → typed `Failure` (AuthFailure/ServerFailure/ValidationFailure/NetworkFailure).
- `QueuedSms.toApi()` = {local_id, encrypted_payload, sender, received_at} (no plaintext, no status).
- `RetryPolicy(maxRetries:5, base:5s, max:30m)`; `canRetry`, `backoffFor`.
- `SmsQueueStore` (inc 5): `enqueue`, `all`. Hive key = localId.

## Testing approach

- Worker: fake `SmsQueueStore` (in-memory, honors syncable/markApproved/markFailed) + mock `ApiClient`
  (mocktail) + mock `SecureStore` (server url). Cover: accepted→approved, rejected→failed+retry,
  5xx→failed+retry, 401→onReauth+stop+no-retry-bump, maxRetries exhausted→not syncable, multi-batch drain,
  serialized/idempotent, wire excludes plaintext.
- `HiveSmsQueueStore`: real Hive via `Hive.init(<temp dir>)` (NOT initFlutter - no platform needed on VM);
  cover enqueue/syncable filter/markApproved/markFailed retry++/purgeApproved. tearDown closes+deletes.

## TODO verify

- connectivity_plus ^7.1.1 `onConnectivityChanged` signature (v6+ emits `List<ConnectivityResult>`, not a
  single value) - confirm via context7 before wiring the trigger.
