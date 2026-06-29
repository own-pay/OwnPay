# Progress log — Mobile UI overhaul + sync fix

## 2026-06-29
- New user request (5 items) + 3 target mockups (Home w/ bottom nav, Pair, Permission).
- Read: router, app shell, app_theme, dashboard, audit (screen/cubit/entry), sync (worker/syncer/
  queued_sms/hive store), settings (screen/cubit), pairing screen, disclosure screen, filter_rules,
  network_filter_rules_repository, aes_gcm_cipher.
- Wrote findings.md + task_plan.md.
- KEY tension: #2 (body readable) conflicts with documented metadata-only audit (SECURITY.md §7/§9);
  app holds AES key + decrypt method exists → decrypt-on-demand owner-only is the clean approach.
- #1 DIAGNOSIS (read SmsController + SmsParserService::processBatch + RetryPolicy + sync_worker_test +
  API_CONTRACT.md §7 + DB):
  * **BUG A (CERTAIN, server):** controller does `Response::apiSuccess($results)` where $results is a
    BARE LIST → wire `{success:true, data:[...]}`. Device `_unwrap` (data is List) returns whole body;
    `_parseResults` reads `body['results']` → null → EVERY row markFailed("no server result"). The
    documented contract (API_CONTRACT.md §7) AND the device test BOTH expect `data:{results:[...]}`.
    FIX = server wrap: `Response::apiSuccess(['results' => $results])`. No server test asserts the old
    HTTP shape (grep clean) → safe.
  * **BUG B (storage, needs live confirm):** `op_sms_parsed` COUNT = 0 → server never stored anything.
    Decryption-failure path STILL stores (storeFailedDecryption), so 0 rows ⇒ NOT a key issue ⇒ most
    likely `processBatch` → `deviceRepo->forTenant($brandId)->findByUuid($uuid)` returns null →
    rejectAll(DEVICE_NOT_FOUND) (no store). Same multi-brand scoping seam as seven-issues #1/#3.
    Confirm via body-free server instrumentation + live device sync, then fix scoping if needed.
- retryNow won't re-post the 3 stuck rows (retryCount>=maxRetries=5 excluded by syncable). Plan: manual
  "Retry now" resets failed rows so a user-initiated retry actually re-attempts (returns duplicate→approved
  once BUG A fixed). Auto-retry stays bounded.
- Read processBatch lookup path: JwtAuthMiddleware sets merchant_id=JWT.mid & validates device.mid==mid;
  processBatch does forTenant(mid)->findByUuid(uuid) = WHERE device_id AND merchant_id=mid. If request
  authed, this SHOULD match → DEVICE_NOT_FOUND only if a scoping edge. KEY_DECRYPTION_FAILED (server
  field-decrypt of stored device key) is the other rejectAll(no-store) path. Confirm live via instrumentation.
- USER DECISIONS: Q1=Full rebuild (app-wide dark + rebuild Home to mockup#1 + redesign Pair/Permission +
  bottom nav). Q2=Tap-to-reveal SMS body (decrypt-on-demand, memory only, update SECURITY.md).
- Read remaining wiring: di.dart (manual get_it), main.dart, dashboard snapshot/cubit, privacy_gate,
  gate_decision, filter_rules_repository(+cache), secure_store, sms_queue_store, app_config, local_wipe,
  consent_store. Plan: disabled-senders set lives in boxSettings (auto-wiped). Coordinator subtracts
  disabled senders before gating (fail-closed safe). New FilterRulesRepository.forceRefresh() for
  "sync from admin". SmsBodyRevealer reads queue row+AES key, decrypts in memory. AppRefreshSignal for
  the bottom-nav Refresh action. resetFailedForRetry() so manual Retry re-posts maxed-out rows.
- IMPLEMENTING (in dep order): (a) server BUG A; (b) theme; (c) nav shell; (d) dashboard; (e) audit body;
  (f) settings senders; (g) pair+permission; (h) retry recovery; (i) verify+docs+device.

## 2026-06-29 — IMPLEMENTED + VERIFIED
- ALL 5 requests built. flutter analyze CLEAN; flutter test 149 PASS (added: sender-override drop,
  hive resetFailedForRetry, forceRefresh x2, SmsBodyRevealer x5, audit retry-reset).
- Server BUG A fixed: SmsController batch → apiSuccess(['results'=>$results]); php -l clean; now CONFORMS
  to API_CONTRACT §7 (the contract already documented {results:[...]}). No server test asserted old shape.
- New lib: app_theme(dark), shell_scaffold(bottom nav), AppRefreshSignal, SmsBodyRevealer, SenderOverrides,
  FilterRules.withAllowedSenders, FilterRulesRepository.forceRefresh, SmsQueueStore.resetFailedForRetry.
  Rebuilt: dashboard(mockup#1), audit(dark+tap-reveal), settings(senders+sync), pair+disclosure(mockups).
  Views take optional refresh signal from Screen → widget tests pump const *View() with no DI.
- Docs: SECURITY.md §7 (owner-only reveal), ARCHITECTURE.md (nav shell, sender overrides, reveal, /sms shape).
- DEVICE: built app-debug.apk, `adb install -r` SUCCESS (data preserved → pairing + 3 failed rows intact).
  Launched → screenshot confirms mockup#1 renders (Active Host Node/Linked, stat tiles, bottom nav). 
- BUG B (0 rows) NARROWED via body-free PHP harness: device 7 aes_key_encrypted DECRYPTS OK (valid hex64),
  device lookup matches → NOT KEY_DECRYPTION_FAILED, NOT DEVICE_NOT_FOUND. So /sms POST fails at
  transport/auth/validation (or those 3 rows failed pre-Bug-A-fix & never stored). Needs live POST to pin.
- TEMP server diag ARMED: SmsController logs body-free (mid, dev prefix, count, statuses, errors) →
  storage/sms_diag.log. MUST REMOVE after Bug B confirmed.
- BLOCKED on live Bug B: device R5CWB1Z12VR dropped off adb (asleep/USB) mid-test; can't drive Activity→
  Retry myself. PENDING USER: reconnect/wake phone → Activity → tap "Retry now" (revives 3 rows → re-POST),
  OR send a test bKash SMS. Then read storage/sms_diag.log (or the rows go green=Confirmed → fully fixed).
- AFTER confirm: remove TEMP diag from SmsController + delete sms_diag.log.

## 2026-06-29 (later) — BUG B ROOT-CAUSED + FIXED + VERIFIED ON DEVICE
- Live adb debugging (device reconnected). Device hits THIS instance (dev.ownpay.org → this Laragon;
  jwt middleware diag showed heartbeats `PASS mid=2` advancing in lockstep with the DB).
- Symptom chain: 4 bKash rows stuck "Failed · Temporary authorization error" (= device api_client's
  authRecovered post-refresh-401 path). sms_diag empty (never reached processBatch). Heartbeat fine.
- ROOT CAUSE (proven via TEMP jwt_diag in middleware): `/sms` → `PASS` (auth ok) → controller →
  `SmsParserService::processBatch` → **`SmsDataRepository::isDuplicate()` throws "Tenant scope not set...
  Call forTenant() first"** — processBatch scoped `deviceRepo` but NEVER `dataRepo`. `forTenant()` CLONES
  (TenantScope trait), so the unscoped original's `requireTenant()` threw. → nothing stored (0 rows).
- AMPLIFIER bug: `JwtAuthMiddleware` wrapped `$next($request)` INSIDE its JWT try/catch, so the
  controller's exception was swallowed → returned **401 "Invalid JWT token"** → device saw it as auth →
  "Temporary authorization error" → retried forever. A server 500 masquerading as a 401.
- FIXES (all PHP, live; 43 PHP tests pass):
  1. `SmsParserService::processBatch`: `$this->dataRepo = $this->dataRepo->forTenant($brandId);` (hold the
     clone) before the processOne loop. (Test mock gained `forTenant()`.)
  2. `JwtAuthMiddleware`: only `JWT::decode()` is in the try; claim/iss/aud/device checks + `$next()` run
     OUTSIDE → downstream exceptions are real 500s, not phantom 401s.
  3. Bug A (envelope `data:{results:[...]}`) already fixed — once #1/#2 let /sms succeed, device reads it.
- **VERIFIED ON DEVICE:** op_sms_parsed now has 4 rows (device 7, mid 2, bKash, local 1-4); Activity shows
  all 4 **"Confirmed"** (green) with refs sms_1..4. SYNC FULLY FIXED end-to-end.
- USER Q2 (API coverage): app calls 9/12 mobile routes — all core needs covered. Unused: bulk-revocations
  + devices/statuses (admin-facing), sms/queues (outbound SMS-send gateway = separate unbuilt feature).
- USER Q3 (.env APP_NAME): middleware + JwtService both keyed `iss` off `getenv('APP_NAME')?:'OwnPay'`
  (getenv returns false under phpdotenv → both fell back to 'OwnPay' → worked by luck, but FRAGILE: a
  rename would break existing tokens). FIXED: `JwtService::ISSUER='OwnPay'` constant used by JwtService
  default + middleware; services.php stops passing APP_NAME. Branding-independent now. Existing tokens
  (iss='OwnPay') still validate (verified: heartbeat still PASS post-change).
- Extra fix: `Syncer.syncNow({force})` — audit "Retry now" now clears the worker backoff (`force:true`)
  so a manual retry isn't silently no-op'd by the backoff window. +test. 149 Flutter tests pass, analyze clean.
- TEMP diags REMOVED (SmsController + JwtAuthMiddleware); diag logs deleted. DONE.

## 2026-06-29 — admin "where are the SMS?" fix
- Captured SMS live at admin **Mobile & SMS → "SMS Logs"** (`/admin/sms-data`, `SmsDataController`) — NOT
  "SMS Center" (that's templates). User couldn't see them: the page scoped `forTenant(getActiveBrandId())`
  but the device's SMS are at merchant_id=2 (platform/All-Brands). In All-Brands view getActiveBrandId
  was null → controller defaulted mid=0 → `WHERE merchant_id=0` → 0 rows.
- FIX: `SmsDataController::index` uses `AdminPageTrait::isGlobalBrandView()` → All-Brands view =
  `smsRepo->forAllTenants()` (global, no merchant filter); brand view = `forTenant(activeBrandId)`.
  `SmsDataRepository::listPaginated` now treats tenantId===null as global (no merchant filter), mirroring
  TenantScope paginateScoped/countScoped. php -l clean; 26 admin/sms PHP tests pass (incl.
  AllBrandsDeviceSmsVerificationTest, AdminApiSecurity, Sovereign).
- VERIFIED via DB: old All-Brands query (merchant_id=0) = 0 rows; fixed (global) = 4 rows. User sees them
  in the All Brands view → Mobile & SMS → SMS Logs. (They show admin_review/parse_error = synced but not
  auto-matched to a payment — template tuning, deferred per user.)
