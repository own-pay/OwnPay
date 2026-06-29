# Mobile-app bug hunt - findings

> **STATUS: RESOLVED (2026-06-28).** All actionable findings fixed (F9,F10,F5,F6,F2,F3,F7,F11,F12 +
> dead-code removal). F1 + cert-pin design deliberately kept (tested/intentional). F4,F13 were
> non-bugs (verified vs backend). Verified: `flutter analyze` clean · **133 tests pass** · debug +
> release(R8) APKs build (16.6/19.0/20.4 MB). See the FIX PLAN section for the per-finding resolution.

Scope: `mobile-app/` Flutter app (57 Dart + 5 Kotlin). Hunting real correctness/security/lifecycle
bugs, not style. Severity: [CRIT] data-loss/security/privacy-gate · [HIGH] wrong behavior ·
[MED] edge-case/robustness · [LOW] minor.

## Confirmed findings

(filled as I go - file:line, what, why, fix)

### F9 [HIGH ✓CONFIRMED] client treats server `duplicate` verdict as a failure

`sync_worker.dart:170` `_SmsResult.accepted => status == 'accepted'` - but the backend
(`SmsParserService::processOne`) returns **three** statuses: `accepted`, `duplicate`, `rejected`,
and a `duplicate` is a SUCCESS (the SMS is already stored; the server's own `SmsController::receive`
counts `accepted || duplicate` as success, line 175). Backend dedupes on
`(device_id, sender, received_at ±1s)` (`SmsDataRepository::isDuplicate`), so duplicates are common:
any retry after a lost POST response, or a double native-delivery, comes back `duplicate`. The client
then `markFailed`+retry++ each time → after `maxRetries` (5) the row is stuck `failed`, shows as an
"Issue" in the audit UI forever, and is re-POSTed up to 5× - even though it was delivered fine.
**Fix:** `bool get accepted => status == 'accepted' || status == 'duplicate';` (mirror the server).
Add a sync_worker test with a `duplicate` result → row approved, draining continues.

### F4 [NON-ISSUE ✓verified] local_id reuse after purge is harmless

`_allocId` reseeds `_nextId` from `max(box.keys)+1` (null on restart), so local_ids restart from 1
after the queue empties+purges. BUT the backend does NOT key on local_id - dedupe is
`(device_id, sender, received_at ±1s)` and local_id is only a correlation token echoed in `results`
(unique within a batch, which is all that matters). No data loss. Leave as-is.

### F10 [HIGH] fail-closed window drains-and-drops → permanent loss of payment SMS

`sms_ingest_coordinator.dart:84-99`. `_drainOnce` guards the no-AES-key case by returning early
(leaving the native buffer intact), but for the rules case it loads `effectiveRules()` (which returns
`null` when the cache is stale/absent AND the server is unreachable - fail-closed, by design) and then
**still** calls `drainPending()` (destructive - `SmsBridge.drain` does `remove(KEY_BUFFER)`), and the
gate drops every message because rules are null. Net: any SMS captured while rules can't be loaded is
pulled from the durable buffer and permanently discarded. Trigger: phone offline > `check_interval_hours`
(default 24h) when a payment SMS arrives, or first-run before the first rules fetch. This silently loses
the exact artifact the app exists to capture. Fix: mirror the AES-key guard - if `effectiveRules()`
returns null, return BEFORE draining (leave messages buffered for when rules are available). Re-eval
later is equally privacy-safe (the real rules would drop an OTP anyway), and the 500-cap still bounds
storage. Tradeoff to note: raw bodies sit in the app-private (MODE_PRIVATE, no-backup) buffer a bit
longer. Recommend deferring; flag for user sign-off since it touches the fail-closed contract.

### F5 [MED] coordinator drains-then-clears; encrypt/enqueue failure loses the batch

Confirmed at the native boundary: `SmsBridge.drain` reads then `remove(KEY_BUFFER)` and returns the
items to Dart; the "exactly-once, no loss" claim only holds if Dart always enqueues successfully.
`sms_ingest_coordinator.dart:87` `drainPending()` pulls AND clears the native buffer, THEN the loop
encrypts + enqueues (102–110) with **no per-message try/catch**. If `encryptToEnvelope` (e.g. a
malformed stored AES key → `keyFromHex` throws) or `enqueue` (Hive I/O) throws, the current + all
remaining buffered messages are gone (buffer already cleared) - violates CLAUDE.md §5 "a failed SMS
is never silently dropped." Fix: guard each message; on failure re-buffer or stop before clearing.

### F6 [MED] exponential backoff is never applied

`RetryPolicy.backoffFor` is dead code - `SyncWorker` never schedules a delay; it retries whenever a
trigger fires (connectivity-up/resume/kick) with no minimum spacing. Contradicts the worker's own
docstring ("back off and retry later"). On a flapping connection this re-posts as fast as triggers
arrive (capped only by maxRetries=5). VERIFY backoffFor unused (grep). Fix: track lastAttempt per
row (or a worker-level next-allowed time) and honor backoffFor.

### F7 [LOW] `SyncStatus.syncing` is never assigned (dead enum value)

### F8 [LOW] `syncable()` calls `all()` (decodes every row) per batch → O(n²) over a drain run

### F1 [LOW] privacy gate substring sender match

`privacy_gate.dart:33` `sender.contains(pat)` - a whitelist entry that is a substring of an
attacker-chosen alpha sender id ("bkash" ⊂ "bkash-win") passes the whitelist. Documented as
intentional (shortcodes), and negative keywords backstop it, but it widens the allow set. Consider
anchored/exact match for alpha ids, substring only for numeric shortcodes.

### F2 [MED] check_interval_hours = 0 → rules always "stale"

`filter_rules.dart:39 isStale` uses `>= Duration(hours: checkIntervalHours)`; server value 0 (parsed
verbatim by `_asInt`) makes every check stale → refetch on every drain. VERIFY repo usage. Fix:
clamp to a sane floor (e.g. max(1, hours)).

### F3 [LOW] `getOrCreateDeviceId` read-then-write race (theoretical; two concurrent first-callers

could mint different ids). Low likelihood (pairing is a single user action).

### F11 [MED] FGS-from-boot may throw on Android 14+ (no guard)

`SmsMonitorService.startInForeground` calls `startForeground(... DATA_SYNC)` with no try/catch.
`BootReceiver` starts it from `BOOT_COMPLETED`; on Android 14+ a background-started `dataSync` FGS can
throw `ForegroundServiceStartNotAllowedException`, and a `startForegroundService` that fails to call
`startForeground` in time crashes. Capture (SmsReceiver) still works, but the ongoing notification
won't return after reboot, and a crash is possible. Device-verify on API 34+. Fix: wrap in try/catch;
consider a non-dataSync path or deferred start.

### F12 [LOW] notification ack-failure → duplicate notification after restart

`notification_poller.dart`: `_seen` is in-memory; if `acknowledge` fails after `show`, the item stays
un-acked on the server but is suppressed locally (in `_seen`) and never re-acked (the `fresh.isEmpty`
early-return skips ack). On restart `_seen` is empty → the same notification is shown again. Fix:
re-ack seen-but-unacked items each poll, or persist the seen-set.

### F13 [NON-ISSUE ✓verified] POST_NOTIFICATIONS is requested

`disclosure_cubit.dart:46` calls `_gate.requestNotifications()` on the SMS-granted path. (Edge: not
requested on the "Not now" path, but capture/notifications are off there anyway.) Not a bug.

### Minor/LOW

- Native `SmsBridge.eventSink` is read/written across threads without volatile/sync - a missed nudge
  is harmless (drain also runs on resume/cold-start). LOW.
- `AppConfig.defaultFilterRulesRefresh` is unused (FilterRules carries its own fallback). Nit.
- Cert pinner pairs `badCertificateCallback=>true` with TOFU `validateCertificate` - accepts ANY cert
  (self-signed/expired/wrong-host) as long as it matches the first-seen pin. Deliberate for self-hosted
  servers; note the design (no hostname/expiry/CA checks in release). Not a bug.

## Checked & OK (read, judged sound)

crypto/aes_gcm_cipher (envelope byte-compatible w/ server openssl path) · gate_decision · filter_rules
(except F2) · secure_store (except F3) · queued_sms (UTC wire) · api_client/_unwrap/_mapError ·
auth_interceptor (single-flight, no recursion via auth:false, no retry loop) · api_result · device_repo
(pair/refresh/revoke) · failure · raw_sms (redacted toString) · platform_sms_capture · SmsReceiver
(multipart concat) · app_config · network_notification_repository · network_dashboard_repository
(cache never overwritten on failure) · dashboard_snapshot (_money never double) · local_notifier (v22) ·
di (no cycle) · session_status/router redirect · settings_cubit (revoke-before-wipe) · local_wipe ·
filter_rules_cache · consent_store · app_notification · pairing_cubit · audit_entry (metadata-only,
no payload) · dashboard_cubit · app/dashboard_cache.

## FIX PLAN (user: fix all, zero bugs, zero dead code, production)

- F9: `_SmsResult.accepted => 'accepted' || 'duplicate'` + test.
- F10: coordinator returns BEFORE consuming the buffer when `effectiveRules()==null`.
- F5: replace destructive `drainPending` with non-destructive `peekPending()` + `ackProcessed(n)`
  (native SmsBridge peek/ack; remove first n). Coordinator acks only the successfully-processed prefix
  (gate-dropped counts as processed; encrypt/enqueue throw stops + leaves the rest buffered). Decode AES
  key eagerly; corrupt key → return (leave buffered).
- F6: worker-level backoff cooldown `_nextAttempt = now + backoffFor(minRetryInFailedBatch)`; skip
  `_syncOnce` while backing off; clear on success. Inject `_now`. Remove dead `RetryPolicy.canRetry`.
- F7: status serialized by NAME (order-independent); remove unused `SyncStatus.syncing`.
- F2: clamp refresh hours floor (`<1 → 24`) in `isStale`.
- F1: sender match = exact (ci) OR (numeric-shortcode AND contains) - kills alpha-substring spoofing.
- F3: single-flight `getOrCreateDeviceId` (cache the in-flight Future).
- F11: try/catch around `startForeground`.
- F12: poller re-acks ALL still-pending ids each cycle (idempotent) → no stuck-unacked / restart dupes.
- Dead code: remove `AppConfig.defaultFilterRulesRefresh`, `RetryPolicy.canRetry`; native eventSink @Volatile.
- F8 (O(n²)) & cert-pin design note: not bugs - leave (documented).

## Open questions / to verify
