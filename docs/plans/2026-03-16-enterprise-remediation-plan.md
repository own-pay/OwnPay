# Enterprise Remediation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Transform AnirbanPay from hybrid legacy/SOA into a fully enterprise-grade fintech platform

**Architecture:** Incremental hardening — fix security issues first, then clean database layer, then refactor architecture, then integrate fintech services, then add testing/logging. Each phase builds on the previous.

**Tech Stack:** PHP 8.2+, PDO, Monolog, PHPUnit, cURL

---

## Phase 1: Security Hardening

### Task 1: Parameterize SQL WHERE clauses in index.php

**Files:**
- Modify: `index.php` — lines 300, 308, 313, 319, 321, 326, 459, 488, 508

**Step 1: Read index.php cron section to identify all unparameterized WHERE clauses**

Read `index.php` lines 290-520 to find every `updateData()` call using string concatenation in the WHERE condition.

**Step 2: Fix each unparameterized WHERE clause**

For each call site, convert from:
```php
$condition = 'id ="' . $row['id'] . '"';
updateData($table, $columns, $values, $condition);
```
To:
```php
$condition = 'id = :where_id';
$whereParams = [':where_id' => $row['id']];
updateData($table, $columns, $values, $condition, $whereParams);
```

Apply to all ~10 call sites. Also fix the unparameterized `WHERE status = "pending"` at line 300.

**Step 3: Verify no string concatenation remains in WHERE conditions**

Run: `grep -n 'updateData.*\$row\[' index.php`
Expected: No matches with string concatenation in WHERE.

**Step 4: Commit**
```bash
git add index.php
git commit -m "fix(security): parameterize all SQL WHERE clauses in index.php cron"
```

---

### Task 2: Parameterize SQL WHERE clauses in SmsDataController

**Files:**
- Modify: `src/Controller/SmsDataController.php` — lines 312, 349, 478, 523

**Step 1: Read SmsDataController to identify unparameterized queries**

Read `src/Controller/SmsDataController.php` to find all `updateData()` and `getData()` calls with string concatenation.

**Step 2: Fix each unparameterized WHERE clause**

Same pattern as Task 1 — convert all concatenated WHERE conditions to named params with `$whereParams`.

**Step 3: Verify**

Run: `grep -n 'updateData\|getData' src/Controller/SmsDataController.php`
Verify all calls use parameterized conditions.

**Step 4: Commit**
```bash
git add src/Controller/SmsDataController.php
git commit -m "fix(security): parameterize SQL WHERE clauses in SmsDataController"
```

---

### Task 3: Create HttpClient service and replace file_get_contents

**Files:**
- Create: `src/Service/HttpClient.php`
- Modify: `index.php` — line 248

**Step 1: Create HttpClient with cURL**

```php
<?php
declare(strict_types=1);
namespace AnirbanPay\Service;

final class HttpClient
{
    public static function get(string $url, int $timeout = 10): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'AnirbanPay/' . (defined('AP_VERSION') ? AP_VERSION : '1.0'),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            error_log("HttpClient::get failed for {$url}: HTTP {$httpCode} — {$error}");
            return null;
        }

        return $response;
    }

    public static function post(string $url, string $body, array $headers = [], int $timeout = 15): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'AnirbanPay/' . (defined('AP_VERSION') ? AP_VERSION : '1.0'),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            error_log("HttpClient::post failed for {$url}: HTTP {$httpCode} — {$error}");
            return null;
        }

        return $response;
    }
}
```

**Step 2: Replace file_get_contents in index.php**

At line 248, replace:
```php
$manifest = file_get_contents('https://updates.AnirbanPay.com/manifest.json');
```
With:
```php
$manifest = \AnirbanPay\Service\HttpClient::get('https://updates.AnirbanPay.com/manifest.json');
```

**Step 3: Verify no remote file_get_contents remain**

Run: `grep -rn 'file_get_contents.*http' index.php src/`
Expected: No matches.

**Step 4: Commit**
```bash
git add src/Service/HttpClient.php index.php
git commit -m "fix(security): replace file_get_contents with cURL HttpClient"
```

---

### Task 4: Harden ZIP upload in SystemUpdateController

**Files:**
- Modify: `src/Controller/SystemUpdateController.php` — lines 259-328

**Step 1: Read the ZIP extraction section**

Read `src/Controller/SystemUpdateController.php` lines 250-340.

**Step 2: Add MIME validation, path traversal check, and per-entry size limit**

Before the extraction loop, add:
```php
// MIME validation
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $tmpPath);
finfo_close($finfo);
if ($mimeType !== 'application/zip') {
    return JsonResponse::error('Invalid file type. Only ZIP files are allowed.');
}

// Path traversal and size checks
$zip = new \ZipArchive();
if ($zip->open($tmpPath) === true) {
    $maxEntrySize = 50 * 1024 * 1024; // 50MB per entry
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
            $zip->close();
            return JsonResponse::error('ZIP contains invalid path: ' . $entry);
        }
        $stat = $zip->statIndex($i);
        if ($stat['size'] > $maxEntrySize) {
            $zip->close();
            return JsonResponse::error('ZIP entry too large: ' . $entry);
        }
    }
    $zip->close();
}
```

**Step 3: Verify**

Read the modified section to confirm all three checks are present before extraction.

**Step 4: Commit**
```bash
git add src/Controller/SystemUpdateController.php
git commit -m "fix(security): add MIME validation and path traversal protection to ZIP upload"
```

---

### Task 5: Add session security and login rate limiting

**Files:**
- Modify: `src/Controller/AuthController.php`

**Step 1: Read AuthController login method**

Read `src/Controller/AuthController.php` to find the login handler.

**Step 2: Add session_regenerate_id after successful login**

After the successful password verification and before cookie/session setup, add:
```php
session_regenerate_id(true);
```

**Step 3: Add rate limiting before login processing**

At the top of the login handler, add:
```php
$rateLimiter = new \AnirbanPay\Middleware\RateLimiterMiddleware();
$ipKey = 'login_ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$result = $rateLimiter->check(crc32($ipKey), '', 'POST', 5);
if (!$result['allowed']) {
    http_response_code(429);
    echo json_encode(['status' => 'false', 'title' => 'Too Many Attempts', 'message' => 'Please try again in ' . $result['retryAfter'] . ' seconds.', 'csrf_token' => $csrf_token ?? '']);
    exit;
}
```

**Step 4: Verify**

Run: `grep -n 'session_regenerate_id\|RateLimiter' src/Controller/AuthController.php`
Expected: Both present.

**Step 5: Commit**
```bash
git add src/Controller/AuthController.php
git commit -m "fix(security): add session regeneration and login rate limiting"
```

---

### Task 6: Fix CSP header — remove unsafe-inline

**Files:**
- Modify: `index.php` — line 104

**Step 1: Read current CSP header**

Read `index.php` lines 100-110.

**Step 2: Replace unsafe-inline with nonce**

Replace:
```php
'unsafe-inline'
```
With nonce-based CSP using the existing `$csp_nonce` variable:
```php
'nonce-$csp_nonce'
```

For both `script-src` and `style-src` directives.

**Step 3: Verify all inline scripts/styles have nonce attribute**

Run: `grep -rn '<script\|<style' app/admin/ | grep -v 'nonce=' | head -20`
Check that all inline script/style tags include `nonce="<?= $csp_nonce ?>"`.

**Step 4: Commit**
```bash
git add index.php
git commit -m "fix(security): replace unsafe-inline with CSP nonce"
```

---

## Phase 2: Database & Core Functions

### Task 7: Remove "--" magic string for NULL

**Files:**
- Modify: `app/core/functions.php` — lines 300, 337
- Create: `migrations/remove_dash_dash_nulls.sql`

**Step 1: Read functions.php insertData and updateData**

Read `app/core/functions.php` lines 270-360.

**Step 2: Fix insertData — use null instead of "--"**

At line 300 (approximately), change:
```php
$finalValues[$colName] = "--";
```
To:
```php
$finalValues[$colName] = null;
```

**Step 3: Fix updateData — use null instead of "--"**

At line 337 (approximately), change:
```php
$value = "--";
```
To:
```php
$value = null;
```

**Step 4: Create migration script**

Create `migrations/remove_dash_dash_nulls.sql`:
```sql
-- Migration: Convert all "--" magic strings to proper NULL values
-- Run against each table that may contain "--" as a null placeholder

-- List tables dynamically isn't possible in plain SQL,
-- so generate per-table UPDATE statements for known tables.
-- Add more tables as needed.

-- Example pattern (run for each text/varchar column):
-- UPDATE {table} SET {column} = NULL WHERE {column} = '--';
```

**Step 5: Search for "--" null-check patterns in codebase**

Run: `grep -rn "=== '--'\|== '--'\|=== \"--\"\|== \"--\"" app/ src/`
Document all locations that check for "--" as null — these will need updating.

**Step 6: Commit**
```bash
git add app/core/functions.php migrations/
git commit -m "fix(data): replace '--' magic string with proper NULL in insertData/updateData"
```

---

### Task 8: Type-safe getData and add deleteData helper

**Files:**
- Modify: `app/core/functions.php`

**Step 1: Read getData function**

Read `app/core/functions.php` lines 228-267.

**Step 2: Add type hints and rename parameter**

Update the function signature:
```php
function getData(string $tableName, string $condition = '', string $select = '* FROM', array $params = []): string
```
Rename internal usage of `$coloum_name` to `$condition` if not already done.

**Step 3: Add table name validation**

At the top of getData, add:
```php
$allowedTables = ['admin', 'brand', 'currency', 'customer', 'device', 'gateway',
    'idempotency', 'invoice', 'payment_link', 'permission', 'rate_limit',
    'sms_data', 'transaction', 'webhook_log', 'api_key', 'faq', 'addon',
    'domain', 'activity_log', 'setting', 'cron_job'];
// Strip prefix for validation
$baseTable = preg_replace('/^[a-z0-9]+_/', '', $tableName);
// Allow prefixed table names
```

**Step 4: Add deleteData helper**

Add after updateData:
```php
function deleteData(string $tableName, string $condition, array $whereParams = []): bool
{
    global $connect;
    try {
        $sql = "DELETE FROM {$tableName} WHERE {$condition}";
        $stmt = $connect->prepare($sql);
        $stmt->execute($whereParams);
        return $stmt->rowCount() > 0;
    } catch (\PDOException $e) {
        error_log("deleteData error: " . $e->getMessage());
        return false;
    }
}
```

**Step 5: Commit**
```bash
git add app/core/functions.php
git commit -m "feat(core): add type hints to getData and add deleteData helper"
```

---

## Phase 3: Architecture Refactor

### Task 9: Create RequestContext value object

**Files:**
- Create: `src/Http/RequestContext.php`

**Step 1: Create RequestContext class**

```php
<?php
declare(strict_types=1);
namespace AnirbanPay\Http;

final class RequestContext
{
    public function __construct(
        public readonly string $dbPrefix,
        public readonly array $user,
        public readonly array $brand,
        public readonly array $permissions,
        public readonly string $csrfToken,
        public readonly bool $isLoggedIn,
        public readonly string $role,
    ) {}

    public function hasPermission(string $module, string $action): bool
    {
        return $this->permissions['resources'][$module][$action] ?? false;
    }

    public function canAccessPage(string $page): bool
    {
        return $this->permissions['pages'][$page] ?? false;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
```

**Step 2: Verify autoloading**

Run: `grep -n 'AnirbanPay' composer.json`
Confirm PSR-4 maps `AnirbanPay\` to `src/`.

**Step 3: Commit**
```bash
git add src/Http/RequestContext.php
git commit -m "feat(arch): add RequestContext value object for dependency injection"
```

---

### Task 10: Extract cron logic into CronJobRunner

**Files:**
- Create: `src/Cron/CronJobRunner.php`
- Modify: `index.php` — lines 224-510

**Step 1: Read the full cron section of index.php**

Read `index.php` lines 224-510 to understand all cron jobs.

**Step 2: Create CronJobRunner**

```php
<?php
declare(strict_types=1);
namespace AnirbanPay\Cron;

final class CronJobRunner
{
    public function run(): void
    {
        $this->checkAutoUpdate();
        $this->reconcilePendingTransactions();
        $this->retryWebhooks();
        $this->updateCurrencyRates();
    }

    private function checkAutoUpdate(): void
    {
        // Move auto-update logic from index.php
    }

    private function reconcilePendingTransactions(): void
    {
        // Move pending transaction verification from index.php
    }

    private function retryWebhooks(): void
    {
        // Move webhook retry logic from index.php
    }

    private function updateCurrencyRates(): void
    {
        // Move currency rate update from index.php
    }
}
```

**Step 3: Replace cron section in index.php**

Replace the 280+ lines of cron logic with:
```php
if ($route === 'cron') {
    (new \AnirbanPay\Cron\CronJobRunner())->run();
    exit;
}
```

**Step 4: Verify cron route still works**

Test the cron endpoint manually or via smoke test.

**Step 5: Commit**
```bash
git add src/Cron/CronJobRunner.php index.php
git commit -m "refactor(arch): extract cron logic into CronJobRunner service"
```

---

## Phase 4: Fintech Compliance

### Task 11: Integrate IdempotencyService into payment creation

**Files:**
- Modify: `src/Controller/Frontend/ApiController.php`
- Read: `src/Service/IdempotencyService.php` (already exists)

**Step 1: Read ApiController payment creation handler**

Read `src/Controller/Frontend/ApiController.php` to find where payments are created.

**Step 2: Add idempotency check at start of payment creation**

```php
$idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
if ($idempotencyKey) {
    $idempotencyService = new \AnirbanPay\Service\IdempotencyService();
    $cached = $idempotencyService->acquire($idempotencyKey, $requestPayload);
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }
}
```

**Step 3: Store response after successful payment**

After payment is created, before returning:
```php
if ($idempotencyKey) {
    $idempotencyService->complete($idempotencyKey, $response);
}
```

**Step 4: Commit**
```bash
git add src/Controller/Frontend/ApiController.php
git commit -m "feat(fintech): integrate IdempotencyService for payment creation"
```

---

### Task 12: Add PII encryption for webhook logs

**Files:**
- Modify: `src/Controller/Frontend/IpnController.php`
- Read: `src/Security/FieldEncryptor.php` (already exists)

**Step 1: Read IpnController to find where webhook data is stored**

Read `src/Controller/Frontend/IpnController.php`.

**Step 2: Encrypt PII fields before storing webhook log**

Before inserting into webhook_log, encrypt sensitive fields:
```php
$encryptor = new \AnirbanPay\Security\FieldEncryptor();
if (isset($logData['customer_email'])) {
    $logData['customer_email'] = $encryptor->encrypt($logData['customer_email']);
}
if (isset($logData['customer_phone'])) {
    $logData['customer_phone'] = $encryptor->encrypt($logData['customer_phone']);
}
```

**Step 3: Commit**
```bash
git add src/Controller/Frontend/IpnController.php
git commit -m "feat(fintech): encrypt PII in webhook logs using FieldEncryptor"
```

---

### Task 13: Wire HealthController to routing

**Files:**
- Modify: `index.php`
- Read: `src/Http/Controller/HealthController.php` (already exists)

**Step 1: Read HealthController to understand its interface**

Read `src/Http/Controller/HealthController.php`.

**Step 2: Add health route in index.php**

In the routing section of index.php, add:
```php
if ($route === 'health') {
    (new \AnirbanPay\Http\Controller\HealthController())->handle();
    exit;
}
```

**Step 3: Verify the route works**

Run: `curl -s http://localhost/health` or test via browser.
Expected: JSON response with status, db, php version, timestamp.

**Step 4: Commit**
```bash
git add index.php
git commit -m "feat(ops): wire HealthController to /health route"
```

---

## Phase 5: Testing & Operations

### Task 14: Set up PHPUnit and write priority tests

**Files:**
- Modify: `composer.json`
- Create: `phpunit.xml`
- Create: `tests/Unit/Service/HttpClientTest.php`
- Create: `tests/Unit/Middleware/RateLimiterTest.php`

**Step 1: Add PHPUnit dev dependency**

Run: `composer require --dev phpunit/phpunit`

**Step 2: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    bootstrap="vendor/autoload.php"
    colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

**Step 3: Write RateLimiterMiddleware test**

```php
<?php
declare(strict_types=1);
namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use AnirbanPay\Middleware\RateLimiterMiddleware;

class RateLimiterTest extends TestCase
{
    public function testCheckReturnsAllowedWhenUnderLimit(): void
    {
        $limiter = new RateLimiterMiddleware();
        $result = $limiter->check(1, '', 'GET', 100);
        $this->assertTrue($result['allowed']);
        $this->assertGreaterThan(0, $result['remaining']);
    }

    public function testWhitelistedKeyBypasses(): void
    {
        $limiter = new RateLimiterMiddleware(null, ['admin_']);
        $result = $limiter->check(1, 'admin_key', 'POST');
        $this->assertTrue($result['allowed']);
        $this->assertEquals(PHP_INT_MAX, $result['remaining']);
    }
}
```

**Step 4: Run tests**

Run: `vendor/bin/phpunit`
Expected: Tests pass.

**Step 5: Commit**
```bash
git add composer.json composer.lock phpunit.xml tests/
git commit -m "feat(testing): set up PHPUnit with initial RateLimiter tests"
```

---

### Task 15: Add Monolog structured logging

**Files:**
- Modify: `composer.json`
- Create: `src/Service/Logger.php`

**Step 1: Add Monolog dependency**

Run: `composer require monolog/monolog`

**Step 2: Create Logger service**

```php
<?php
declare(strict_types=1);
namespace AnirbanPay\Service;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;

final class Logger
{
    private static array $loggers = [];

    public static function app(): MonologLogger
    {
        return self::getLogger('app');
    }

    public static function security(): MonologLogger
    {
        return self::getLogger('security');
    }

    public static function payment(): MonologLogger
    {
        return self::getLogger('payment');
    }

    private static function getLogger(string $channel): MonologLogger
    {
        if (!isset(self::$loggers[$channel])) {
            $logger = new MonologLogger($channel);
            $handler = new RotatingFileHandler(
                __DIR__ . '/../../logs/' . $channel . '.log',
                30, // keep 30 days
                MonologLogger::DEBUG
            );
            $handler->setFormatter(new JsonFormatter());
            $logger->pushHandler($handler);
            self::$loggers[$channel] = $logger;
        }
        return self::$loggers[$channel];
    }
}
```

**Step 3: Create logs directory**

Run: `mkdir -p logs && echo '*' > logs/.gitignore`

**Step 4: Commit**
```bash
git add src/Service/Logger.php logs/.gitignore composer.json composer.lock
git commit -m "feat(ops): add Monolog structured logging with JSON formatter"
```

---

## Fix escape_string double-encoding (Bonus — Phase 1 completion)

### Task 16: Split escape_string into context-appropriate functions

**Files:**
- Modify: `app/core/functions.php` — lines 217-226

**Step 1: Read escape_string function**

Read `app/core/functions.php` lines 215-230.

**Step 2: Create sanitize_html and clean_input functions**

Replace `escape_string()` with:
```php
function sanitize_html($value): string {
    if (is_array($value)) {
        return array_map('sanitize_html', $value);
    }
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

function clean_input($value): string {
    if (is_array($value)) {
        return array_map('clean_input', $value);
    }
    return trim($value);
}

// Backwards compatibility alias
function escape_string($value) {
    return sanitize_html($value);
}
```

**Step 3: Search for escape_string callers**

Run: `grep -rn 'escape_string' app/ src/ | wc -l`
Document the count. These will be gradually migrated:
- SQL query params: remove escape_string (PDO handles it)
- HTML output: keep as sanitize_html

**Step 4: Commit**
```bash
git add app/core/functions.php
git commit -m "fix(security): split escape_string into sanitize_html and clean_input"
```
