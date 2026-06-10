# OwnPay Technical Fixing Plan

This document outlines the detailed technical plans for resolving the 11 verified security, logic, and scalability bugs identified in the OwnPay Master Audit Report.

---

## 1. FIND-003: `Database::getInstance()` throws in production (CRITICAL)

### Root Cause
`Database::getInstance()` relies on a static `$instance` property which is only initialized when `Database::init()` is called (which happens only during integration tests). In the production boot pipeline (`src/Kernel.php` and `config/services.php`), the `Database` wrapper is resolved via the PSR-11 container using `new Database($pdo)`, which does not set the static `$instance`. Callers in critical runtime flows (such as `RefundService::create()`, `GatewayApiService::handleCallback()`, and `CheckoutController`) use `Database::getInstance()`, causing them to throw a `RuntimeException` in production.

### Proposed Fixes

#### Option A: Strict Dependency Injection (Preferred)
Refactor callers of `Database::getInstance()` to receive the `Database` instance via constructor injection:
1. **`src/Service/Payment/RefundService.php`**:
   - Add `\OwnPay\Core\Database $db` as a dependency in the constructor.
   - Replace `\OwnPay\Core\Database::getInstance()` with `$this->db`.
2. **`src/Service/Payment/GatewayApiService.php`**:
   - Add `\OwnPay\Core\Database $db` as a dependency in the constructor.
   - Replace `\OwnPay\Core\Database::getInstance()` with `$this->db`.
3. **`src/Controller/Checkout/CheckoutController.php`**:
   - Add `\OwnPay\Core\Database $db` to constructor arguments.
   - Replace `\OwnPay\Core\Database::getInstance()` with `$this->db`.
4. **`config/services.php`**:
   - Update container registration factories for `RefundService` and `GatewayApiService` to pass `ensureType($c->get(\OwnPay\Core\Database::class), \OwnPay\Core\Database::class)` into their constructors.

#### Option B: Eager Singleton Binding (Stop-Gap / Compatibility)
Add a setter to explicitly populate the singleton instance when the wrapper is constructed:
1. **`src/Core/Database.php`**:
   - Add a public static setter method:
     ```php
     public static function setInstance(self $instance): void
     {
         self::$instance = $instance;
     }
     ```
2. **`config/services.php`**:
   - Update the `Database::class` container factory to set the instance right after creation:
     ```php
     $c->singleton(\OwnPay\Core\Database::class, static function (\OwnPay\Container $c): \OwnPay\Core\Database {
         $db = new \OwnPay\Core\Database(ensureType($c->get(\PDO::class), \PDO::class));
         \OwnPay\Core\Database::setInstance($db);
         return $db;
     });
     ```

---

## 2. FIND-004: Un-gated mock-token payment-confirmation bypass in Affirm, Afterpay, Bitpay (CRITICAL)

### Root Cause
The `AffirmGateway`, `AfterpayGateway`, and `BitpayGateway` adapters generate a `mock_` token fallback in `initiate()` if the real cURL request fails or returns an empty redirect URL. These adapters verify this mock token in their respective `verify()` methods, returning a `completed` status unconditionally without checking if the gateway mode is set to `'live'`. Webhook signature checks (`verifyWebhook()`) in these adapters are also stubbed out to return `true` unconditionally.

### Proposed Fixes
1. **Gate mock tokens to non-live environments**:
   Update `verify()` in `AffirmGateway.php`, `AfterpayGateway.php`, and `BitpayGateway.php` to immediately reject `mock_` tokens if the mode is `'live'`:
   ```php
   if (str_starts_with($token, 'mock_')) {
       $mode = $this->getString($credentials['mode'] ?? 'sandbox');
       if ($mode === 'live') {
           return [
               'success' => false,
               'status'  => 'failed',
           ];
       }
       return [
           'success'        => true,
           'gateway_trx_id' => $token,
           'status'         => 'completed',
       ];
   }
   ```
2. **Throw errors in `initiate()` in live mode**:
   If the API request fails to return a valid URL in live mode, immediately throw an exception instead of falling back to emitting mock tokens:
   ```php
   if ($redirectUrl === '') {
       $mode = $this->getString($credentials['mode'] ?? 'sandbox');
       if ($mode === 'live') {
           throw new \RuntimeException('Payment initiation failed: Empty response from gateway.');
       }
       $checkoutId = 'mock_aff_' . uniqid();
       $redirectUrl = $params['redirect_url'] . '?checkout_token=' . $checkoutId . '&trx_id=' . urlencode($trxId);
   }
   ```
3. **Harden webhooks**:
   Remove `return true;` from `verifyWebhook()`. Implement real HMAC signature check routines utilizing the gateway's webhooks signing key or fetch transaction details directly from the provider backchannel before confirming payment.

---

## 3. FIND-001: Swapped argument order in `MfsService::processIncomingSms` call (HIGH)

### Root Cause
In `src/Service/Payment/MfsService.php` (line 65), the parameters are passed to `SmsParserService::parse()` in the incorrect order:
```php
$parsed = $this->parser->parse($sender, $body, $merchantId);
```
However, the signature in `SmsParserService::parse()` is:
```php
public function parse(string $rawMessage, string $sender, int $brandId): ?array
```
This maps the sender identifier (e.g. `"bKash"`) to the `$rawMessage` body and the actual message text body to the `$sender`. This prevents regexes and template matchers from identifying template strings.

### Proposed Fix
Correct the parameter sequence in `MfsService.php`:
```diff
- $parsed = $this->parser->parse($sender, $body, $merchantId);
+ $parsed = $this->parser->parse($body, $sender, $merchantId);
```
Write a unit test specifically verifying parameter mapping and mock matching inside `tests/Unit/Service/MfsServiceTest.php` to prevent regression.

---

## 4. FIND-005: Gateway verifyWebhook and refund stubs in TwoCheckout (HIGH)

### Root Cause
`TwoCheckoutGateway::verifyWebhook()` returns `true` unconditionally after verifying header presence. Furthermore, its `refund()` method returns a mock success payload without invoking the 2Checkout REST API.

### Proposed Fix
1. **Implement timing-safe HMAC check in `verifyWebhook()`**:
   2Checkout INS webhooks calculate a signature using an MD5 hash of concatenated fields and the vendor secret key. Parse the payload, construct the hash, and compare it using `hash_equals()`:
   ```php
   public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
   {
       $secret = $this->getString($credentials['secret_key'] ?? '');
       if ($secret === '') {
           return false;
       }
       // Parse body fields, build string according to 2Checkout INS validation protocol
       // $calculatedHash = hash_hmac('md5', $dataString, $secret);
       // return hash_equals($signatureFromHeader, $calculatedHash);
   }
   ```
2. **Implement real `refund()` outbound request**:
   Make a real POST request to the 2Checkout API endpoint (`/rest/6.0/orders/{orderRef}/refund/`) using the merchant credentials, and parse the resulting response to confirm successful execution before returning success status.

---

## 5. FIND-019: Device pairing bootstrap blocked by JWT middleware (HIGH)

### Root Cause
The device pairing route (`POST /api/mobile/v1/devices`) and token refresh route (`POST /api/mobile/v1/devices/token-refreshes`) are assigned to the `'mobile'` middleware group. This group includes `JwtAuthMiddleware`, which intercepts incoming requests and returns an HTTP 401 response if no bearer JWT is present. During initial pairing, the device has no JWT (it registers using a temporary OTP token). During refresh, the bearer token may be expired. This blocks companion app onboarding entirely.

### Proposed Fix
1. **Define a new middleware stack**:
   In `config/middleware.php`, register a `'mobile-bootstrap'` middleware group:
   ```php
   'mobile-bootstrap' => [
       \OwnPay\Middleware\CorsMiddleware::class,
       \OwnPay\Middleware\RateLimiterMiddleware::class,
   ],
   ```
2. **Re-route endpoints**:
   In `config/routes/api.php`, adjust the middleware group for pairing and token refresh:
   ```diff
-  $router->post('/api/mobile/v1/devices',            'Api\\Mobile\\DeviceController@pair',         'mobile');
-  $router->post('/api/mobile/v1/devices/token-refreshes', 'Api\\Mobile\\DeviceController@refresh',   'mobile');
+  $router->post('/api/mobile/v1/devices',            'Api\\Mobile\\DeviceController@pair',         'mobile-bootstrap');
+  $router->post('/api/mobile/v1/devices/token-refreshes', 'Api\\Mobile\\DeviceController@refresh',   'mobile-bootstrap');
   ```

---

## 6. FIND-002: External cURL execution inside DB transaction holding `FOR UPDATE` (MEDIUM)

### Root Cause
In `RefundService::create()`, the entire logic (including the cURL gateway refund request `$this->bridge->refund(...)`) is wrapped inside a `$db->transaction()` call. Row locks are acquired at the very beginning of the transaction using `FOR UPDATE`. This holds database connection resources and locks active for the duration of the HTTP latency.

### Proposed Fix
Apply a saga-like pattern to split the transaction:
1. **Phase 1 (Lock & Log)**: Check refund limits and merchant balance, write the refund record with `status = 'pending'`, and commit the transaction to release row locks immediately.
2. **Phase 2 (Outbound network request)**: Dispatch the cURL refund call to the gateway outside the transaction scope.
3. **Phase 3 (Reconciliation)**: Open a second short transaction to record the final outcome:
   - On success: Update the refund status to `completed`, debit the merchant balance in the double-entry ledger, and mark the parent transaction as `refunded` if the amount matches.
   - On failure: Update the refund status to `failed` and log details.

---

## 7. FIND-006: PHPUnit requires PHP >= 8.3 vs composer.json requirement of PHP 8.2 (MEDIUM)

### Root Cause
`composer.json` specifies a minimum PHP version requirement of `"php": "^8.2"`, but registers `"phpunit/phpunit": "^12.5"` under `"require-dev"`. PHPUnit 12 requires PHP 8.3 or higher, causing the test runner to throw a compatibility exception when executed on PHP 8.2 environments.

### Proposed Fix
- **Option A (Downgrade PHPUnit)**: Pin a compatible version of PHPUnit for PHP 8.2 support in `composer.json`:
  ```diff
- "phpunit/phpunit": "^12.5"
+ "phpunit/phpunit": "^11.5"
  ```
- **Option B (Upgrade PHP requirements)**: Upgrade minimum PHP floor to `"php": "^8.3"` across the board. If choosing this, the version checker in `src/Controller/Install/InstallerController.php:668` must be updated to reject PHP < 8.3.

---

## 8. FIND-007: Rate limiter fails open on database/Redis error (MEDIUM)

### Root Cause
In `RateLimiterMiddleware::handle()`, the catch block logs a warning but calls `$next($request)` anyway. This fails open on database issues or connection drops, leaving sensitive endpoints unprotected:
```php
} catch (\PDOException|\RuntimeException $e) {
    $this->logWarning('Rate limiter skipped: ' . $e->getMessage());
    return $next($request);
}
```

### Proposed Fix
Implement a selective fail-closed strategy:
1. Identify if the current endpoint requires strict rate limiting (e.g. `/login`, `/2fa`, `/api/mobile/v1/devices`).
2. If the request is targeting an auth-sensitive route and rate limiting fails, return an HTTP 503 Service Unavailable error response (fail-closed).
3. For public/non-sensitive reads (e.g. invoice pages, assets), continue failing open.
```php
} catch (\PDOException|\RuntimeException $e) {
    $this->logWarning('Rate limiter skipped: ' . $e->getMessage());
    if ($isLoginRoute || $path === '/api/mobile/v1/devices') {
        return Response::json([
            'success' => false,
            'message' => 'Service temporarily unavailable. Limiter backend error.'
        ], 503);
    }
    return $next($request);
}
```

---

## 9. FIND-009: Null sandbox bypasses `db.query.before` SQL validation (MEDIUM)

### Root Cause
In `EventManager::applyFilter()`, if a plugin registers a filter on `db.query.before` but does not have an active sandbox configured in the registry, `$sandbox` evaluates to `null` and the SQL statement is processed without query checking.

### Proposed Fix
Adopt a default-deny posture:
```php
$sandbox = $registry->getSandbox($listener['owner']);
if ($sandbox === null) {
    throw new \RuntimeException(
        "Database query modified by plugin '{$listener['owner']}' blocked: No active sandbox context defined."
    );
}
if (!$sandbox->validateSql($sqlToCheck)) {
    throw new \RuntimeException(...);
}
```

---

## 10. FIND-016 & FIND-017: Callback amount verification & SMS TrxID namespace mismatch (MEDIUM)

### Proposed Fixes

#### FIND-016 (Callback Amount Verification)
In `GatewayApiService::handleCallback()`, verify the amount returned by the verification bridge matches the order amount:
```php
$verifiedAmount = $verification['amount'] ?? null;
if ($verifiedAmount !== null && bccomp((string)$verifiedAmount, (string)$transaction['amount'], 2) !== 0) {
    return ['success' => false, 'error' => 'Transaction amount mismatch'];
}
```

#### FIND-017 (SMS TrxID Namespace Mismatch)
1. **Schema Update**: Add a `provider_trx_id` VARCHAR indexable column to the `op_transactions` table to store MFS provider references (e.g., bKash transaction IDs).
2. **Update repositories**: Update `TransactionRepository` with a `findByProviderTrxId(string $providerTrxId)` lookup query.
3. **Correct matcher lookup**: Update `SmsVerificationJob::run()` to look up transactions by provider ID:
   ```php
   if ($trxId !== null) {
       $transaction = $this->transactions->forTenant($merchantId)->findByProviderTrxId($trxId);
   }
   ```

---

## 11. FIND-008: Webhook SSRF validation gaps (DNS-rebinding TOCTOU & IPv6 AAAA) (LOW)

### Proposed Fix
1. **Resolve AAAA records**: Update `UrlValidator::isValidWebhookUrl()` to resolve and validate both IPv4 and IPv6 addresses:
   ```php
   $ips = dns_get_record($host, DNS_A | DNS_AAAA);
   // Loop through records, check both IPv4 and IPv6 against private range blocks
   ```
2. **Mitigate TOCTOU DNS-rebinding**: In the outbound request component, resolve the hostname once to a validated public IP, then instruct cURL to pin the request to that specific IP using `CURLOPT_RESOLVE`:
   ```php
   $curlResolve = ["{$host}:{$port}:{$validatedIp}"];
   curl_setopt($ch, CURLOPT_RESOLVE, $curlResolve);
   ```
   This ensures that no subsequent unchecked DNS lookup occurs when cURL makes the actual network connection.

---

## 12. FIND-010: `DomainMiddleware` hardcodes `localhost` passthrough (Host-header trust) (LOW)

### Proposed Fix
Update `DomainMiddleware::handle()` to only permit `'localhost'` bypass if the request originates from loopback addresses (127.0.0.1 or ::1):
```php
$masterDomain = $this->resolveMasterDomain();
$isLocalhostLoopback = ($domain === 'localhost' && in_array($request->ip(), ['127.0.0.1', '::1', 'localhost'], true));
if ($domain === $masterDomain || $isLocalhostLoopback) {
    return $next($request);
}
```
This prevents remote clients from bypassing white-label custom domain filters by passing spoofed `Host: localhost` headers.

---

## 13. FIND-011: Invoice totals can go negative (no positivity clamp) (LOW)

### Proposed Fix
In `InvoiceService::create()` and `InvoiceService::update()`, add boundaries to protect subtotal calculations:
1. Clamp line item prices to be strictly non-negative:
   ```php
   $price = number_format(max(0.00, (float) ($item['unit_price'] ?? $item['amount'] ?? 0)), 2, '.', '');
   ```
2. Enforce that `$discount` is non-negative and clamp the final total to at least `0.00` BDT:
   ```php
   $discount = number_format(max(0.00, (float) ($data['discount'] ?? 0)), 2, '.', '');
   $total = bcadd($subtotal, $tax, 2);
   $total = bcsub($total, $discount, 2);
   if (bccomp($total, '0.00', 2) < 0) {
       $total = '0.00';
   }
   ```

---

## 14. FIND-014: `form_html` sanitizer keeps inline `<script>` tags (LOW)

### Proposed Fix
In `GatewayApiService::sanitizeFormHtml()`, parse the HTML response and explicitly restrict inline scripts to auto-submit actions only, stripping any inline event handlers or arbitrary executable JavaScript statements. This ensures that even if a gateway plugin is compromised, it cannot execute XSS payloads.

---

## 15. FIND-015: Mobile notifications fallback to shared, unlocked temp-file (LOW)

### Proposed Fix
In `MobileNotificationService::queueNotification()`, enforce secure file system behavior if database fallbacks are needed:
1. Set explicit file permissions (`0600` - read/write by owner only) on the temporary file.
2. Utilize `flock()` to coordinate atomic writes and prevent file corruption during concurrent operations:
   ```php
   $fp = fopen($file, 'c+');
   if (flock($fp, LOCK_EX)) {
       // Read, decode, append, encode, write, and truncate
       flock($fp, LOCK_UN);
   }
   fclose($fp);
   ```
3. Better: Remove the shared temp file fallback entirely and make database storage a hard requirement, raising a system exception when the repository is not bound.

---

## 16. FIND-012: `Authenticator` TOTP drift window is ±2 steps (INFORMATIONAL)

### Proposed Fix
In `src/Security/Authenticator.php`, reduce the default `$discrepancy` limit to `1` (±30 seconds drift) to restrict the replay attack surface:
```diff
      public function verifyCodeWithReplayGuard(
          string $secret,
          string $code,
          int $lastUsedWindow = 0,
-        int $discrepancy = 2,
+        int $discrepancy = 1,
          ?int $currentTimeSlice = null
      ): int
```
