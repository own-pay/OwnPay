# Enterprise Remediation Design — AnirbanPay

**Date:** 2026-03-16
**Approach:** Aggressive Refactor
**Goal:** Transform from hybrid legacy/SOA into a fully enterprise-grade fintech platform

---

## Phase 1: Security Hardening

### 1.1 Parameterize all SQL WHERE clauses
**Problem:** 6 call sites use string concatenation in `updateData()` WHERE conditions.
**Files:**
- `index.php:308,313,319,321,326,459,488,508` — cron transaction/webhook processing
- `src/Controller/SmsDataController.php:312,349,478,523` — balance verification
- `index.php:300` — unparameterized `WHERE status = "pending"`

**Fix:** Convert all to named `$whereParams`:
```php
// Before:
$condition = 'id ="' . $row['id'] . '"';
updateData($table, $columns, $values, $condition);

// After:
$condition = 'id = :where_id';
$whereParams = [':where_id' => $row['id']];
updateData($table, $columns, $values, $condition, $whereParams);
```

### 1.2 Replace file_get_contents() with cURL
**Problem:** `index.php:248` uses `file_get_contents()` for remote URL without SSL validation.
**Fix:** Create `src/Service/HttpClient.php`:
```php
class HttpClient {
    public static function get(string $url, int $timeout = 10): ?string
    // Uses cURL with CURLOPT_SSL_VERIFYPEER, timeout, error handling
}
```
Replace the `file_get_contents` call and any future remote fetches.

### 1.3 ZIP upload hardening
**File:** `src/Controller/SystemUpdateController.php:259-328`
**Fix:** Add before extraction:
- MIME validation: `finfo_open(FILEINFO_MIME_TYPE)` must return `application/zip`
- Path traversal scan: iterate `$zip->getNameIndex($i)`, reject any entry containing `..`
- Per-entry size limit (50MB per file)

### 1.4 Session security
**File:** `src/Controller/AuthController.php`
**Fix:**
- Add `session_regenerate_id(true)` after successful login (line 33, before cookie set)
- Ensure admin cookies use `SameSite=Strict`

### 1.5 CSP header — remove unsafe-inline
**File:** `index.php:104`
**Fix:** Replace `'unsafe-inline'` with `'nonce-{$csp_nonce}'`:
```php
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$csp_nonce' https://cdn.jsdelivr.net https://cdn.tailwindcss.com https://cdnjs.cloudflare.com; style-src 'self' 'nonce-$csp_nonce' https://cdn.jsdelivr.net https://fonts.googleapis.com; ...");
```
Then audit all inline `<script>` and `<style>` tags to ensure they have `nonce="<?= $csp_nonce ?>"`.

### 1.6 Rate limit on login
**Files:** `src/Controller/AuthController.php`, `src/Middleware/RateLimiterMiddleware.php`
**Fix:** Before processing login, create IP-based rate check:
```php
$rateLimiter = new RateLimiterMiddleware();
$ipKey = 'login_ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$result = $rateLimiter->check(crc32($ipKey), '', 'POST', 5); // 5 attempts per minute
if (!$result['allowed']) { /* return 429 */ }
```

### 1.7 Fix escape_string() double-encoding
**File:** `app/core/functions.php:217-226`
**Fix:**
- Rename current `escape_string()` to `sanitize_html()` — keeps `htmlspecialchars()`, removes `addslashes()`
- Create `clean_input()` — just `trim()` for use with parameterized queries
- Find/replace all callers (~50 sites) based on context:
  - SQL query params: remove `escape_string()` entirely (PDO handles it)
  - HTML output: use `sanitize_html()`
  - Keep `escape_string()` as alias for backwards compatibility during transition

---

## Phase 2: Database & Core Functions

### 2.1 Remove "--" magic string for NULL
**Files:** `app/core/functions.php:300,337`
**Fix:**
- `insertData()`: change `$finalValues[$colName] = "--"` to `$finalValues[$colName] = null`
- `updateData()`: change `$value = "--"` to `$value = null`
- Global search/replace: find all `=== '--'` and `=== ""` null-check patterns, consolidate to null-aware checks using `??` and `empty()`
- DB migration: UPDATE all tables SET column = NULL WHERE column = '--'

### 2.2 Type-safe getData()
**File:** `app/core/functions.php:228`
**Fix:**
```php
function getData(string $tableName, string $condition = '', string $select = '* FROM', array $params = []): string
```
- Rename `$coloum_name` → `$condition`
- Add table name validation against allowlist
- Add return type `string` (always returns JSON)

### 2.3 Add deleteData() helper
**File:** `app/core/functions.php`
**Fix:** Add parameterized delete function:
```php
function deleteData(string $tableName, string $condition, array $whereParams = []): bool
```

---

## Phase 3: Architecture Refactor

### 3.1 Split adapter.php into middleware services
**File:** `app/core/adapter.php` (1000+ lines)
**Create:**
- `src/Middleware/SessionMiddleware.php` — session_start, cookie validation, user loading from DB
- `src/Middleware/CsrfMiddleware.php` — token generation, validation on POST
- `src/Middleware/PermissionMiddleware.php` — permission/brand loading, access checks
- `src/Middleware/TwoFactorMiddleware.php` — 2FA state management

**adapter.php** becomes a ~50 line orchestrator:
```php
$session = new SessionMiddleware();
$session->handle();
$csrf = new CsrfMiddleware();
$csrf->handle();
// ...
```

### 3.2 RequestContext replaces globals
**Create:** `src/Http/RequestContext.php`
```php
final class RequestContext {
    public function __construct(
        public readonly string $dbPrefix,
        public readonly array $user,
        public readonly array $brand,
        public readonly array $permissions,
        public readonly string $csrfToken,
        public readonly bool $isLoggedIn,
        public readonly string $role,
    ) {}
}
```
- Middleware pipeline builds RequestContext
- All Controller `::handle()` methods accept `RequestContext $ctx` instead of using `global`
- Gradual migration: keep globals set by adapter for dashboard views, but Controllers use $ctx

### 3.3 Consolidate cron into CronJobRunner
**Files:** `index.php:224-510` → `src/Cron/CronJobRunner.php`
**Fix:**
- Move auto-update check logic to `UpdaterService`
- Move pending transaction verification to `ReconciliationService`
- Move webhook retry to `WebhookService`
- CronJobRunner orchestrates with proper error handling and logging
- index.php cron route becomes: `(new CronJobRunner())->run()`

---

## Phase 4: Fintech Compliance

### 4.1 Integrate IdempotencyService
**Files:** `src/Controller/Frontend/ApiController.php`, `src/Service/IdempotencyService.php`
**Fix:**
- Check `Idempotency-Key` header on payment creation
- Store key + response in `idempotency` table
- Return cached response for duplicate keys
- 409 Conflict if key reused with different payload

### 4.2 Webhook signature verification
**File:** `src/Controller/Frontend/IpnController.php`
**Fix:**
- Each gateway config stores a `webhook_secret`
- IPN handler verifies HMAC-SHA256: `hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)`
- Reject unverified webhooks with 401

### 4.3 PII protection
**Files:** `src/Security/FieldEncryptor.php`, `src/Security/PiiMasker.php`
**Fix:**
- Encrypt customer email/phone before storing in `webhook_log`
- Mask PII in all `error_log()` calls using `LogSanitizer`
- Add data retention policy: auto-purge webhook_log entries older than 90 days

### 4.4 Brand/tenant isolation
**Create:** `src/Repository/TenantScope.php` trait
```php
trait TenantScope {
    protected function appendBrandFilter(string $condition, array &$params, string $brandId): string {
        $params[':brand_id'] = $brandId;
        return $condition . ' AND brand_id = :brand_id';
    }
}
```
- Apply to all Repository classes
- Audit every `getData()` call for missing brand_id filter

---

## Phase 5: Testing & Operations

### 5.1 PHPUnit test suite
**Setup:** `composer require --dev phpunit/phpunit`
**Priority tests:**
- `tests/Unit/Service/CurrencyServiceTest.php` — financial math (money_add, money_sub, money_round)
- `tests/Unit/Controller/AuthControllerTest.php` — login flow, rate limiting
- `tests/Unit/Service/IdempotencyServiceTest.php` — key dedup
- `tests/Unit/Middleware/RateLimiterTest.php` — window enforcement
- `tests/Unit/Security/FieldEncryptorTest.php` — encrypt/decrypt round-trip

### 5.2 Structured logging (Monolog)
**Setup:** `composer require monolog/monolog`
**Create:** `src/Service/Logger.php`
```php
class Logger {
    public static function app(): \Monolog\Logger    // Business events
    public static function security(): \Monolog\Logger // Auth, access
    public static function payment(): \Monolog\Logger  // Transactions
}
```
- JSON formatter for log aggregation
- PII masking via LogSanitizer processor
- File rotation: daily, keep 30 days

### 5.3 Wire HealthController to routing
**File:** `src/Http/Controller/HealthController.php` (exists)
**Fix:** Add route in `index.php`:
```php
if ($route === 'health') {
    (new HealthController())->handle();
    exit;
}
```
Returns: `{"status":"ok","db":true,"disk_free":"...","php":"8.x","timestamp":"..."}`

---

## Critical Constraints
- Backend API response format must NOT change (frontend depends on `{status, title, message, csrf_token}`)
- `getData()`/`updateData()`/`insertData()` signatures must remain backwards-compatible during transition
- Dashboard PHP views continue using globals until Phase 3 is complete
- All changes must maintain dark mode and mobile responsiveness
