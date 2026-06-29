# Findings & Decisions

## Requirements

- Review `docs/v2/final_report/docs/v2/audit_findings/ownpay_master_audit_report.md`
- Cross-check all major bugs listed in the report against the live codebase files.
- Create a comprehensive fixing plan for the real/confirmed bugs and place it in the same directory (`docs/v2/final_report/docs/v2/audit_findings/`).
- Create a `README.md` file in that folder to explain the contents.
- Do NOT modify any source code files (only create plan and README documentation).

## Research Findings

Below is the list of findings to cross-check in the codebase:

1. **FIND-003**: `Database::getInstance()` throws in production.
   - **Verification Status**: **CONFIRMED**.
   - **Details**: `Database::getInstance()` throws a `RuntimeException` if `self::$instance === null`. The static field `$instance` is only set in `Database::init()`, which is not called in the production bootstrap path (only in integration tests). In `config/services.php` (line 106), the `Database` singleton is registered in the container using `new Database($pdo)`, which does not call `Database::init()` or set `self::$instance`. An eager-boot block at the end of `config/services.php` retrieves the `Database` instance from the container but does not populate the static property. Consequently, any code calling `Database::getInstance()` (such as `RefundService`, `GatewayApiService`, and `CheckoutController`) throws a `RuntimeException` in production.

2. **FIND-004**: Un-gated mock-token payment-confirmation bypass in Affirm, Afterpay, Bitpay adapters.
   - **Verification Status**: **CONFIRMED**.
   - **Details**:
     - **Affirm Gateway** (`AffirmGateway.php`):
       - At lines 130-133: If `$redirectUrl` is empty (e.g. if the curl call to Affirm fails or returns an error), it sets `$checkoutId = 'mock_aff_' . uniqid()` and builds a local redirect URL with `checkout_token` containing the mock token. This fallback is not gated to sandbox/testing, so it can occur in `live` mode if there is an API connection issue or configuration error.
       - At lines 156-162: In `verify()`, if the token starts with `'mock_'`, it returns `['success' => true, 'gateway_trx_id' => $token, 'status' => 'completed']` without checking if the credentials mode is `'live'`.
       - At lines 214-219: `verifyWebhook()` returns `true` unconditionally, allowing any spoofed webhook to pass signature checks.
     - **Afterpay Gateway** (`AfterpayGateway.php`):
       - At lines 119-122: If `$redirectUrl` is empty, it falls back to `$token = 'mock_apt_' . uniqid()` and redirects the user with `orderToken`. This is un-gated and occurs in `live` mode on API failure.
       - At lines 145-151: `verify()` returns a successful payment for tokens starting with `'mock_'` without checking if the mode is `'live'`.
       - At lines 195-198: `verifyWebhook()` returns `true` unconditionally.
     - **Bitpay Gateway** (`BitpayGateway.php`):
       - At lines 107-110: If `$redirectUrl` is empty (e.g. if the curl call to BitPay fails or returns an error), it sets `$invoiceId = 'mock_btp_' . uniqid()` and redirects the user with `id` containing the mock token. This is un-gated and occurs in `live` mode on API failure.
       - At lines 132-138: `verify()` returns a successful payment for tokens starting with `'mock_'` without checking if the mode is `'live'`.
       - At lines 178-182: `verifyWebhook()` returns `true` unconditionally.

3. **FIND-001**: `MfsService` passes parser arguments in swapped order (`$sender`, `$body` instead of `$rawMessage`, `$sender`).
   - **Verification Status**: **CONFIRMED**.
   - **Details**: In `src/Service/Payment/MfsService.php` at line 65, the method `processIncomingSms` calls:

     ```php
     $parsed = $this->parser->parse($sender, $body, $merchantId);
     ```

     However, `SmsParserService::parse()` signature is defined as:

     ```php
     public function parse(string $rawMessage, string $sender, int $brandId): ?array
     ```

     So `$rawMessage` receives `$sender` and `$sender` receives `$body` (the raw message). This swapped order completely breaks pattern matching because the message sender name/shortcode is treated as the message body, and vice versa. Note that this service currently has no callers in the main workflow, making it dormant dead code, but it represents a high-severity bug if wired in.

4. **FIND-005**: Gateway `verifyWebhook()`/`refund()` stubs (no-op verification and simulated refunds).
   - **Verification Status**: **CONFIRMED**.
   - **Details**: In `modules/gateways/2checkout/TwoCheckoutGateway.php`:
     - At lines 241-259: `verifyWebhook()` receives the raw body, headers, and credentials, but only checks for the existence of `X-2CO-Signature` and immediately returns `true` without comparing or verifying the signature hash.
     - At lines 308-315: The `refund()` method contains a stub/simulation:

       ```php
       return [
           'success'   => true,
           'refund_id' => 'REF_' . $this->slug() . '_' . uniqid(),
       ];
       ```

       It does not initiate any outbound HTTP request to 2Checkout to process a real refund. Consequently, the transaction is marked as refunded in the ledger without funds actually moving.

5. **FIND-019**: Device pairing bootstrap blocked by JWT middleware.
   - **Verification Status**: **CONFIRMED**.
   - **Details**:
     - In `config/routes/api.php` at lines 40 and 54, the pairing and token-refresh endpoints are registered under the `'mobile'` middleware group:

       ```php
       $router->post('/api/mobile/v1/devices',            'Api\\Mobile\\DeviceController@pair',         'mobile');
       $router->post('/api/mobile/v1/devices/token-refreshes', 'Api\\Mobile\\DeviceController@refresh',   'mobile');
       ```

     - In `config/middleware.php` at lines 72-76, the `'mobile'` middleware stack includes `JwtAuthMiddleware::class`.
     - In `src/Middleware/JwtAuthMiddleware.php` at lines 47-54, the middleware checks `$request->bearerToken()` and, if it is null or empty, immediately aborts and returns an HTTP 401 JSON response (`JWT token required`).
     - Since a new device pairing requests a token using an OTP in the body and does not yet have a JWT, and since `JwtAuthMiddleware` does not contain any exception allowlist/route check, new devices cannot pair (they get blocked by the middleware stack with 401). Similarly, token refresh requests (which pass the refresh token in the body) would also get blocked if the bearer token is missing or expired.

6. **FIND-002**: External gateway HTTP call executed inside a DB transaction holding `FOR UPDATE`.
   - **Verification Status**: **CONFIRMED**.
   - **Details**: In `src/Service/Payment/RefundService.php` at lines 83-207, the entire refund processing flow is wrapped in a database transaction block:

     ```php
     $db->transaction(function () use (...) {
         // L85-88: Lock transaction row
         $txn = $db->fetchOne("... FOR UPDATE", ...);
         ...
         // L102-106: Lock existing refunds
         $alreadyRefundedVal = $db->fetchColumn("... FOR UPDATE", ...);
         ...
         // L170-175: Make outbound gateway refund request via bridge
         $result = $this->bridge->refund(...);
         ...
     });
     ```

     During the execution of `$this->bridge->refund(...)` (which makes synchronous external network requests with timeouts up to 15 seconds), the row locks on `op_transactions` and `op_refunds` remain held. Under concurrent refund calls or high load, this causes extensive lock contention, MySQL connection pool starvation, and potential transaction timeouts.

7. **FIND-006**: PHPUnit version 12 requires PHP >= 8.3, but the project's minimum is configured as PHP 8.2 in `composer.json`.
   - **Verification Status**: **CONFIRMED**.
   - **Details**: In `composer.json`, the minimum PHP requirement is `"php": "^8.2"` (line 7), while `"phpunit/phpunit": "^12.5"` is listed under `"require-dev"` (line 37). PHPUnit 12 officially requires PHP 8.3 or higher. Therefore, any attempt to run tests in an environment using PHP 8.2 results in a runtime error blocking automated testing/CI execution on PHP 8.2.

8. **FIND-007**: Rate limiter fails open on database error.
   - **Verification Status**: **CONFIRMED**.
   - **Details**: In `src/Middleware/RateLimiterMiddleware.php` at lines 115-119:

     ```php
     } catch (\PDOException|\RuntimeException $e) {
         // DB unavailable (install mode, outage) - skip rate limiting
         $this->logWarning('Rate limiter skipped: ' . $e->getMessage());
         return $next($request);
     }
     ```

     Any database exception (e.g. if the MySQL connection fails or is overloaded) or runtime exception causes the rate limiter to catch it, log a warning, and call `$next($request)`. This fails open, allowing unrestricted requests to hit sensitive endpoints (like login, 2FA, device pairing) during database outages.

9. **FIND-009**: Plugin with no sandbox bypasses `db.query.before` SQL validation.
   - **Verification Status**: **CONFIRMED**.
   - **Details**: In `src/Event/EventManager.php` at lines 317-330, the `db.query.before` filter handler retrieves the plugin's sandbox:

     ```php
     $sandbox = $registry->getSandbox($listener['owner']);
     if ($sandbox !== null && !$sandbox->validateSql($sqlToCheck)) {
         throw new \RuntimeException(...);
     }
     ```

     If `$sandbox` is null (e.g., if a plugin registers a filter but has no sandbox allocated in the registry), the validation is bypassed and the modified query runs unvalidated.

10. **FIND-016 & FIND-017**: Callback amount not verified against order in `GatewayApiService`, and SMS TrxID namespace mismatch.
    - **Verification Status**: **CONFIRMED**.
    - **Details**:
      - **FIND-016 (Callback Amount)**: In `src/Service/Payment/GatewayApiService.php` inside `handleCallback()` (lines 211-227), when completing a transaction, the service retrieves the amount `$amt` and fee `$feeVal` stored on the transaction in the database and routes them directly to transaction completion and ledger recording:

        ```php
        $txnId = $transaction['id'] ?? 0;
        $amt = $transaction['amount'] ?? '0.00';
        ...
        $this->transactions->complete((int) $txnId, $merchantId);
        $this->ledger->recordPaymentReceived($merchantId, (int) $txnId, (string) $amt, ...);
        ```

        It does not compare the amount returned from the gateway verification result (e.g. `$verification['amount']` or the amount in `$callbackData`) against `$amt`. A vulnerable or poorly written adapter (like `TwoCheckoutGateway` or the mock gateways) that returns verification success but allows amount tampering would cause the transaction to complete at full value in OwnPay even if the customer paid a different amount.
      - **FIND-017 (SMS TrxID Namespace Mismatch)**: In `src/Cron/SmsVerificationJob.php` at line 117, it attempts to look up a transaction by the parsed SMS transaction reference `$trxId` using:

        ```php
        $transaction = $this->transactions->forTenant($merchantId)->findByTrxId($trxId);
        ```

        The parsed `$trxId` is the mobile financial service (MFS) provider's transaction ID (e.g., BKash/Nagad trx id). However, `$this->transactions->findByTrxId` queries the `op_transactions` table by its own internal transaction ID (`trx_id` column, which starts with `'OP-'`). These namespaces are completely separate, so the direct TrxID lookup fails, and the matcher falls back to amount-based heuristic matching (`findPendingMatch`), which is less secure and prone to collisions when multiple payments of the same amount occur.

11. **FIND-008**: Webhook SSRF validation gaps (DNS-rebinding TOCTOU & IPv6 AAAA).
    - **Verification Status**: **CONFIRMED**.
    - **Details**: In `src/Security/UrlValidator.php` inside `isValidWebhookUrl()` (lines 145-184):
      - The resolution of hostname IPs is performed using `gethostbynamel($host)` (line 166), which is IPv4-only. It does not query or validate IPv6 (AAAA) records. If the host resolves via IPv6 to a private or loopback IP (like `[::1]` or `fe80::/10`), and cURL supports IPv6, the verification check is bypassed.
      - There is a Time-of-Check to Time-of-Use (TOCTOU) DNS-rebinding vulnerability: the hostname is validated via `isValidWebhookUrl()`, but the subsequent cURL request (for webhooks or callbacks) uses the raw hostname rather than pinning it to the validated resolved IP. An attacker could configure a domain with a very low TTL that initially resolves to a public IP to pass the check, but then resolves to a private IP (e.g. `127.0.0.1` or `169.254.169.254`) by the time cURL executes the request.

12. **FIND-010**: `DomainMiddleware` hardcodes `localhost` passthrough (Host-header trust).
    - **Verification Status**: **CONFIRMED**.
    - **Details**: In `src/Middleware/DomainMiddleware.php` at line 72, the middleware checks if the normalized domain matches the system-wide master domain or is exactly `'localhost'`:

      ```php
      if ($domain === $masterDomain || $domain === 'localhost') {
          return $next($request);
      }
      ```

      If a client specifies `Host: localhost` in their HTTP request header, this block triggers and bypasses the custom domain resolution/validation.

13. **FIND-011**: Invoice totals can go negative (no positivity clamp).
    - **Verification Status**: **CONFIRMED**.
    - **Details**: In `src/Service/Payment/InvoiceService.php` inside `create()` (lines 142, 152) and `update()` (lines 218, 228), unit prices and discount values are parsed directly to floats and formatted to strings without any positivity or bound validation checks:

      ```php
      $price = number_format((float) ($item['unit_price'] ?? $item['amount'] ?? 0), 2, '.', '');
      ...
      $discount = number_format((float) ($data['discount'] ?? 0), 2, '.', '');
      ```

      If an admin provides negative numbers for the unit price or a discount amount that exceeds the subtotal, the calculated invoice total can go below zero. There is no `max(0, ...)` or validation check clamping the total to a non-negative value.

14. **FIND-014**: `form_html` sanitizer keeps inline `<script>` tags.
    - **Verification Status**: **CONFIRMED**.
    - **Details**: In `src/Service/Payment/GatewayApiService.php` inside `sanitizeFormHtml()` at line 268:

      ```php
      $html = (string) preg_replace('/<script\s+[^>]*src\s*=\s*[^>]*>.*?<\/script>/is', '', $html);
      ```

      This regular expression strips external script tags carrying a `src` attribute but keeps inline `<script>` tags (which is historically done to allow auto-submission scripts like `document.forms[0].submit()`). A compromised or malicious gateway plugin could exploit this default-allow behavior to inject arbitrary inline JavaScript that executes within the customer's browser session.

15. **FIND-015**: Mobile notifications fallback to shared, unlocked temp-file.
    - **Verification Status**: **CONFIRMED**.
    - **Details**: In `src/Service/Notification/MobileNotificationService.php` at lines 226-239:

      ```php
      private function queueNotification(array $payload): void
      {
          $file = sys_get_temp_dir() . '/op_notifications.json';
          ...
          file_put_contents($file, json_encode($queue));
      }
      ```

      When the database-backed notification repository is not available, the service falls back to writing raw notification data (containing amounts, sender names, and TrxIDs) to a shared file in the system temp directory (`sys_get_temp_dir()`). The file is written without acquisition of locks or strict file permissions, exposing sensitive transaction/SMS data to other processes or users running on a shared hosting server.

16. **FIND-012**: `Authenticator` TOTP drift window is ±2 steps (±60s).
    - **Verification Status**: **CONFIRMED**.
    - **Details**: In `src/Security/Authenticator.php` at line 283, `verifyCodeWithReplayGuard()` declares the default discrepancy limit parameter:

      ```php
      int $discrepancy = 2
      ```

      This allows a drift window of ±2 time slices (±60 seconds), which widens the attack surface for TOTP replay attempts. Reducing the default discrepancy to `1` (±30 seconds) is recommended.

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Plan-only implementation | Adhering strictly to user prompt asking to "make real fixing plan of real bugs. plan it and store in the same folder with readme file. Make no mistakes." |

## Issues Encountered

| Issue | Resolution |
|-------|------------|

## Resources

- [Master Audit Report](file:///c:/laragon/www/ownpay/docs/v2/final_report/docs/v2/audit_findings/ownpay_master_audit_report.md)
