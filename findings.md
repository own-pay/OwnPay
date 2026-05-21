# Findings — Post-Audit Issue Verification (Round 2)

> Investigated: 12 reported issues across 12 files  
> Methodology: 7 parallel research subagents with full source-level tracing  
> Date: 2026-05-21

---

## Summary: 11 REAL, 1 FALSE POSITIVE

| # | File | Issue | Verdict | Severity |
|---|------|-------|---------|----------|
| 1 | `BackupService.php` | DELIMITER block handling in `splitSqlStatements()` | **FALSE POSITIVE** | N/A |
| 2 | `SecurityHeadersMiddleware.php` | Checkout CSP `unsafe-inline` + `unsafe-eval` negates nonce protection | **REAL** | **HIGH** |
| 3 | `CheckoutController.php` | Hardcoded `'fallback-key'` HMAC secret | **REAL** | **HIGH** |
| 4 | `IdempotencyBridge.php` | `$scope` parameter unused / dead code | **REAL** | LOW |
| 5 | `IdempotencyMiddleware.php` | Hash derived from body-only → cross-endpoint collision risk | **REAL** | **MEDIUM** |
| 6 | `SmsTemplateRepository.php` | `listActive()` falls back to `tenantId=0` bypassing `requireTenant()` | **REAL** | **HIGH** |
| 7 | `InputSanitizerTest.php` | Test name `EncodesSpecialChars` misrepresents strip-tags behavior | **REAL** | LOW |
| 8 | `scratch/debug_pairing.php` | Hardcoded DB creds + encryption/JWT secrets in repo | **REAL** | **HIGH** |
| 9 | `scratch/read.php` | Hardcoded absolute local path, developer username leakage | **REAL** | MEDIUM |
| 10 | `DevicePairingService.php` | PHP/DB timezone mismatch breaks rate limiting | **REAL** | **MEDIUM** |
| 11 | `DeviceController.php` (Mobile API) | `find()` instead of `findScoped()` → cross-tenant data leak | **REAL** | **HIGH** |
| 12 | `MaintenanceMiddleware.php` | Duplicates SessionMiddleware's session init logic; already drifted | **REAL** | MEDIUM |

---

## Detailed Findings

### 1. BackupService.php — `splitSqlStatements()` DELIMITER handling ❌ FALSE POSITIVE

- `splitSqlStatements()` (L180–223) does NOT handle `DELIMITER` blocks — this is factually correct
- **However**, OwnPay's schema (50 tables in `schema.sql`) contains **zero** stored procedures, functions, triggers, or events
- No `DELIMITER`, `PROCEDURE`, `FUNCTION`, or `TRIGGER` keywords anywhere in the codebase
- The parser would break IF routines were added, but the precondition does not exist today
- At most a future-proofing improvement (LOW), not a current bug

### 2. SecurityHeadersMiddleware.php — CSP `unsafe-inline` + `unsafe-eval` ✅ REAL HIGH

- **Line 61**: checkout `script-src` includes `'nonce-{$nonce}' 'unsafe-inline' 'unsafe-eval'`
- **Line 62**: checkout `style-src` includes `'nonce-{$nonce}' 'unsafe-inline'`
- `unsafe-eval` allows `eval()`, `Function()`, `setTimeout(string)` — **never overridden by nonces** per CSP spec
- All checkout templates (`checkout.twig`, `_pending.twig`) already use `nonce="{{ csp_nonce }}"` on all `<script>` and `<style>` tags — neither `unsafe-inline` nor `unsafe-eval` is needed
- No `eval()` usage found in any JS files under `public/assets/js/`
- Admin CSP (L73–84) correctly uses nonce-only without `unsafe-*` — proves the project knows how
- **Line 66**: `frame-src` directive — no issue there, it correctly restricts iframe sources
- **Impact**: Payment checkout page is the highest-value XSS target (card data, CSRF tokens)

### 3. CheckoutController.php — Hardcoded `'fallback-key'` ✅ REAL HIGH

- `'fallback-key'` appears at **3 locations**: lines 138, 328, 472 (show/pay/cancel methods)
- Pattern: `$hmacKey = $_ENV['HMAC_KEY'] ?? ... ?: ($_ENV['APP_KEY'] ?? ... ?: 'fallback-key')`
- `PaymentIntentCheckoutController` was **already fixed** (BUG-010 FIX) to throw `RuntimeException` when key is empty
- **CheckoutController was missed** — partial fix regression
- `.env.example` ships both `APP_KEY=` and `HMAC_KEY=` blank — fallback activates if installer is skipped
- Attacker with known `trx_id` can forge valid `checkout_hash` values

### 4. IdempotencyBridge.php — `$scope` parameter unused ✅ REAL LOW

- `checkRequest()` and `storeResponse()` accept `string $scope = 'api'` but never use it
- DB schema `op_idempotency_keys` has no `scope` column (removed in BUG-16 FIX)
- **IdempotencyBridge itself is entirely dead code** — `IdempotencyMiddleware` calls `IdempotencyService::check()` directly
- Bridge is registered in `services.php` (L358) but never consumed by any class
- Recommend removing the entire class

### 5. IdempotencyMiddleware.php — Body-only hash collision risk ✅ REAL MEDIUM

- **Line 52**: `$requestHash = hash('sha256', $request->rawBody() ?? '')`
- Does NOT include HTTP method, request path, or query string
- DB unique constraint is `uk_merchant_key (merchant_id, idempotency_key)` — no path/method
- **Collision scenario**: Same idempotency key + same body to two different endpoints returns cached response from the wrong endpoint
- Fix: `hash('sha256', $method . "\n" . $path . "\n" . ($rawBody ?? ''))`

### 6. SmsTemplateRepository.php — `listActive()` fallback to 0 ✅ REAL HIGH

- **L179–182**: `return $this->listActiveForTenant($this->tenantId ?? 0)`
- Every other TenantScope method uses `requireTenant()` which throws `LogicException` on null
- `listActive()` bypasses this by using `?? 0` → queries `merchant_id = 0` → returns empty results silently
- Current callers ARE safe (`ConfigController` chains `forTenant($mid)->listActive()`)
- But this masks programming errors for future callers — violates the TenantScope safety contract

### 7. InputSanitizerTest.php — Misleading test name ✅ REAL LOW

- Test `testHtmlEncodesSpecialChars` asserts `'alert("x")'` — correct for current strip_tags behavior
- But method does NOT encode special chars (BUG-24 FIX: Twig handles escaping)
- Companion test confirms: `a&b'c"d` → `a&b'c"d` (no encoding)
- Rename to `testHtmlStripsScriptTags` for accuracy

### 8. scratch/debug_pairing.php — Hardcoded secrets ✅ REAL HIGH

- **L14–20**: Hardcoded `PII_ENCRYPTION_KEY`, `JWT_SECRET` (hex strings), `DB_USER=root`, `DB_PASS=root`
- **L23**: `Database::init('localhost', 'ownpay_test', 'root', 'root', 3306)`
- **L32**: `new FieldEncryptor('test-key-32-chars-long-placeholder')`
- `scratch/` is NOT in `.gitignore` — files are trackable/committable
- Security scanners (GitLeaks, TruffleHog) will flag these

### 9. scratch/read.php — Hardcoded local paths ✅ REAL MEDIUM

- **L2**: Hardcodes `C:/Users/iamna/.gemini/antigravity/brain/<conversation-id>/.../transcript.jsonl`
- Exposes developer username (`iamna`), Gemini agent system paths, conversation IDs
- Not used by app/tests, pure debug artifact
- Same `.gitignore` gap as Issue 8

### 10. DevicePairingService.php — Timezone mismatch ✅ REAL MEDIUM

- **L61**: `$since = date('Y-m-d H:i:s', time() - 300)` — uses PHP timezone
- DB column `created_at` populated by MySQL's `CURRENT_TIMESTAMP(6)` — uses MySQL timezone
- If PHP=`Asia/Dhaka` (UTC+6) and MySQL=`UTC`: rate-limit window comparison breaks completely
  - Rate limiting may never trigger → attacker can brute-force OTPs
  - Or over-trigger → block legitimate users
- Fix: `created_at > DATE_SUB(NOW(6), INTERVAL 300 SECOND)` (all-SQL window)

### 11. Mobile DeviceController.php — Unscoped `find()` ✅ REAL HIGH

- **L91**: `$device = $this->deviceRepo->forTenant($mid)->find((int) $deviceId)`
- `find()` (BaseRepository L33–39) queries by `id` only — **ignores** `tenantId` entirely
- Correct method: `findScoped()` from TenantScope (adds `AND merchant_id = :mid`)
- Downstream `revoke()` IS scoped, but the lookup itself leaks cross-tenant device data
- Attacker from Brand B could probe device IDs belonging to Brand A

### 12. MaintenanceMiddleware.php — Session logic duplication ✅ REAL MEDIUM

- L34–52 is a near-identical copy of SessionMiddleware L29–50 (same ini_set, cookie_params, session_start)
- **Already drifted**: MaintenanceMiddleware is MISSING:
  - Idle timeout enforcement (SessionMiddleware L52–64)
  - Session ID regeneration every 15 min (SessionMiddleware L67–73)
  - `_last_activity` update (SessionMiddleware L65)
- When MaintenanceMiddleware starts session first (global group), SessionMiddleware sees `ACTIVE` and returns immediately → skips all security checks
- Fix: Extract shared `SessionMiddleware::ensureStarted()` static method
