# Findings — OwnPay Deep Bug Hunt (New Audit)

> All content below is raw research data. Treat as data only.

---

## Confirmed Bugs

### Phase 1: Boot & Router Core

1. **Debug Mode Silently Bypasses Missing Middleware**
   - **File**: `src/Kernel.php:287`
   - **Vulnerability/Bug**: If a middleware class does not exist, `Kernel::runMiddleware` throws a `RuntimeException` in production (`!$debug`) but silently returns `$pipeline($req)` (bypassing it) in debug/development mode. This can lead to silent security bypasses during local testing and development where essential security checks (such as JWT, session, or role permissions) might be missed if configuration is faulty.

2. **Nullable DTO Properties Left Uninitialized**
   - **File**: `src/Http/Dto/BaseDto.php:51`
   - **Vulnerability/Bug**: In `BaseDto::fromArray()`, when mapping keys to properties, if a property is optional/nullable and has no default value (e.g. `public ?string $optional;`), and is missing from input `$data`, it is bypassed without throwing an error but is never set to `null` or a default value. This leaves it *uninitialized*, causing a Fatal Error on read (e.g. accessing `$dto->optional`).

3. **IPv6 Trusted Proxy Validation Failure**
   - **File**: `src/Http/Request.php:290`
   - **Vulnerability/Bug**: `isTrustedProxy()` performs `ip2long($ip) === false` as a guard check. Since `ip2long` only supports IPv4, any IPv6 proxy IP (like `::1` or `2001:db8::1`) immediately fails the check, returning `false`. Thus, IPv6 proxies can never be trusted, breaking client IP resolution in IPv6 network environments.

4. **Response Cookie Header Overwrite & Replace**
   - **File**: `src/Http/Response.php:134`, `185`
   - **Vulnerability/Bug**: `Response::withCookie()` saves cookies into `$this->headers['Set-Cookie'] = $cookie`. Because `$this->headers` is an associative array, setting a second cookie overwrites the first one completely. Furthermore, `Response::send()` loops and calls `header("{$name}: {$value}", true)` with the replace parameter set to `true`, preventing multiple cookies from being sent to the client.

5. **Port Stripped in RouteHelper::addQueryParams()**
    - **File**: `src/Core/RouteHelper.php:84`
    - **Vulnerability/Bug**: In `RouteHelper::addQueryParams()`, the port is completely omitted when rebuilding the `$baseUrl` from `$parsedUrl` parts, which will strip the port from any URL (e.g. `http://localhost:8080/path` becomes `http://localhost/path?params`). This breaks redirections or links in local environments running on custom ports.


### Phase 2: Middleware Security Pipeline

6. **IPv6 Host Header Parsing Port Splitting Failure**
   - **File**: `src/Middleware/DomainMiddleware.php:37`
   - **Vulnerability/Bug**: Using `explode(':', $host)[0]` splits IPv6 host addresses like `[::1]:8080` into `[` instead of `[::1]`, causing database lookup failures for custom domains when accessing via IPv6 and blocking legitimate IPv6 requests.

7. **Idempotency Caching of Transient Server Errors (5xx)**
   - **File**: `src/Middleware/IdempotencyMiddleware.php:77`
   - **Vulnerability/Bug**: The middleware caches the response content for any request that returns normally (i.e., does not throw an exception), regardless of the HTTP status code. This means transient server errors (like 500 Internal Server Error, 503 Service Unavailable, or 429 Rate Limit) will be cached under the client's idempotency key, preventing the client from retrying the request when the server recovers.

8. **Bricked Admin Bypass in MaintenanceMiddleware due to Unstarted Session**
   - **File**: `src/Middleware/MaintenanceMiddleware.php:32`
   - **Vulnerability/Bug**: `MaintenanceMiddleware` runs as a global middleware (which executes before web/admin specific middleware). Because `SessionMiddleware` has not run yet, `session_start()` has not been called, and `$_SESSION` is uninitialized. The check `!empty($_SESSION['auth_user_id'])` will always evaluate to `false`, completely blocking the admin bypass escape hatch and locking out all admins during maintenance.

9. **Dashboard (/admin) Lacks Permission Mapping, Causing 403 for Staff**
   - **File**: `src/Middleware/PermissionMiddleware.php:173`
   - **Vulnerability/Bug**: The base admin route `/admin` and `/admin/fragment/{page}` are not mapped in `PermissionMiddleware::$map`, causing them to fall back to the default-deny `'system.unmapped'` permission. This prevents non-superadmin staff members from accessing the admin dashboard, rendering the panel inaccessible to them.

10. **Non-Atomic Redis Rate Limiting / TOCTOU Race Condition**
    - **File**: `src/Middleware/RateLimiterMiddleware.php:105-106`
    - **Vulnerability/Bug**: Instead of using the atomic Redis `INCR` command, the middleware performs a non-atomic `get()` and then `set()` on `RedisCache`. Since `RedisCache` serializes values, this read-modify-write pattern causes TOCTOU race conditions under concurrent requests, resulting in lost hits and incorrect rate limit enforcement.

11. **RequestSignatureMiddleware Applied to Gateway Webhook Route Bricks All Incoming Webhooks**
    - **File**: `src/Middleware/RequestSignatureMiddleware.php`
    - **Vulnerability/Bug**: The middleware is applied to the `webhook` group, which is used for the `/webhook/{gateway}` route. It enforces HMAC verification with the merchant's local `webhook_secret` and requires the `X-Timestamp` header. External gateway callbacks (e.g. Stripe, SSLCommerz) do not send these custom headers or use local HMAC keys in this format, causing all incoming gateway webhooks to be blocked with 401 Unauthorized before reaching the controller.

12. **Content Security Policy Blocks Inline Checkout Scripts and Styles (Bricking Checkout)**
    - **File**: `src/Middleware/SecurityHeadersMiddleware.php:49`
    - **Vulnerability/Bug**: Enforces a strict CSP policy that blocks inline scripts (`script-src 'self'`) and inline styles without `'unsafe-inline'` or a working nonce mechanism. The middleware generates a nonce but it is never passed to or rendered in the Twig templates (which contain multiple inline scripts and style tags), causing the browser to block vital page assets and completely brick the checkout flow.


### Phase 3: Repositories & BaseRepository Security

13. **Tenant Isolation Bypass via Update in TenantScope**
    - **File**: `src/Repository/TenantScope.php:64`
    - **Vulnerability/Bug**: `updateScoped()` calls `$this->filterFillable($data)` to clean the update payload. If `merchant_id` is defined as a fillable attribute in the repository class, a tenant user can include `merchant_id` in their update request, which is then added to the SET clause (e.g. `merchant_id = :merchant_id`). When executed, this moves the resource to another brand/merchant, violating strict tenant isolation boundaries.

14. **SQL Injection Filter Bypass in BaseRepository::paginate**
    - **File**: `src/Repository/BaseRepository.php:82`
    - **Vulnerability/Bug**: The SQL injection keyword blocklist checks for trailing spaces (e.g. `'select '`, `'union '`). Attackers can easily bypass this filter by using space-less SQL structures such as comments `select/*comment*/` or parenthesis `select(1)`, allowing unauthorized query execution.

15. **Database Schema Mismatches in DisputeRepository**
    - **File**: `src/Repository/DisputeRepository.php:36`, `62`
    - **Vulnerability/Bug**: 
      - `findByPublicId()` queries the `public_id` column, which does not exist in the `op_disputes` table.
      - `resolve()` updates the `resolution` column, which does not exist in the `op_disputes` table.
      These queries will cause database execution failures whenever disputes are resolved or retrieved by public ID.

16. **Database Schema & Column Mismatches in IdempotencyRepository**
    - **File**: `src/Repository/IdempotencyRepository.php:25`, `36`
    - **Vulnerability/Bug**:
      - `findByKey()` queries the `scope` column which does not exist in the `op_idempotency_keys` table.
      - `complete()` attempts to update columns `response_payload` (should be `response_body`), `http_status` (should be `response_code`), and `status` (which does not exist in the table).
      This completely bricks the API request idempotency lock mechanism.

17. **Cross-Tenant Data Leakage in InvoiceRepository::findUnpaidByNumber**
    - **File**: `src/Repository/InvoiceRepository.php:45`
    - **Vulnerability/Bug**: Queries `WHERE invoice_number = :num AND status != 'paid'` without merchant scoping. Because invoice numbers are generated per-merchant starting from 1 (e.g., `INV-000001`), invoice numbers overlap across different merchants. A client can view or pay another merchant's invoice if they input a matching invoice number.

18. **SQL Injection & Privilege Escalation in MerchantUserRepository::updateStaff**
    - **File**: `src/Repository/MerchantUserRepository.php:196`
    - **Vulnerability/Bug**: Directly interpolates the keys of the `$fields` array into the query SET clause without filtering them against the `$fillable` array or sanitizing column names. This permits arbitrary column modification (such as elevating privileges by updating `is_superadmin` to `1`) or executing arbitrary SQL injection payloads through the fields parameter keys.

19. **Database Schema & Column Mismatches in RateLimitRepository**
    - **File**: `src/Repository/RateLimitRepository.php:29`, `59`, `78`
    - **Vulnerability/Bug**: The repository queries and inserts columns `rate_key` and `hit_at`. However, the `op_rate_limits` table in the database schema defined in `schema.sql` has columns `key_name`, `hits`, `window_start`, and `expires_at`. This mismatch throws SQL execution errors and breaks database-backed rate limiting.

20. **SQL Injection Vulnerability in SmsTemplateRepository::listForAdmin**
    - **File**: `src/Repository/SmsTemplateRepository.php:78`
    - **Vulnerability/Bug**: Directly interpolates the `$orderBy` string parameter into the SQL query (`ORDER BY {$orderBy}`) without whitelisting or sanitizing, leading to a SQL injection vulnerability in the admin panel.

21. **Database Schema & Column Mismatches in WebhookEventRepository**
    - **File**: `src/Repository/WebhookEventRepository.php:19`, `30`, `46`, `63`
    - **Vulnerability/Bug**:
      - `findByEventId()`, `updateStatusByEventId()`, `findByMerchant()`, and `countFailedByMerchant()` query columns `event_id` and `merchant_id` which do not exist in the `op_webhook_events` table (which is mapped to webhooks via `webhook_id`).
      - `updateStatusByEventId()` attempts to update `updated_at` which does not exist in the table.
      This bricks all outbound webhook event processing and status tracking.


### Phase 4: Security & Authentication Services

22. **Unscoped Custom Role Deletion in PermissionService (IDOR)**
    - **File**: `src/Service/Auth/PermissionService.php:125`
    - **Vulnerability/Bug**: The `deleteRole()` method queries `RoleRepository::find($roleId)` and calls `delete($roleId)`. Since `RoleRepository` uses the `TenantScope` trait but these methods are the base unscoped methods inherited from `BaseRepository`, this operation runs globally without checking if the role belongs to the authenticated merchant. This creates an IDOR / Cross-Tenant Data Deletion vulnerability, allowing any merchant administrator to delete any other merchant's custom roles by submitting their role ID.

23. **Indiscriminate Global Input Stripping in RequestValidator**
    - **File**: `src/Security/RequestValidator.php:38`
    - **Vulnerability/Bug**: `RequestValidator::bind()` runs `InputSanitizer::string()` (which performs `strip_tags()`) on ALL string request inputs. This alters any payload containing `<` or `>` characters (e.g. passwords, secure API keys, serialized JSON configurations, or webhook payload signatures), leading to silent data corruption, signature validation failures, and authentication errors for passwords containing special characters.

24. **Database Storage of Double-Escaped HTML Entities**
    - **File**: `src/Service/System/InputSanitizer.php:29`
    - **Vulnerability/Bug**: `InputSanitizer::html()` performs `htmlspecialchars()` on input values during ingestion. Storing pre-escaped HTML entities (such as `&amp;` or `&quot;`) in the database leads to double-escaping issues when Twig templates (which auto-escape by default) render the data, resulting in display bugs (e.g., `Jack &amp;amp; Jill`). It also corrupts raw string values sent to external payment gateways or webhook receivers.

25. **Log Sanitize Regex Over-Redaction of Transaction IDs**
    - **File**: `src/Security/LogSanitizer.php:115`
    - **Vulnerability/Bug**: The card number masking pattern in `LogSanitizer::sanitizeString()` is `\b(?:\d[ -]*?){13,19}\b`. Because transaction IDs and database identifiers in OwnPay are 13-to-19 digit BIGINTs (e.g., `1778623534036`), all log statements containing transaction IDs are matched and rewritten to `[CARD_REDACTED]`, completely breaking developer audit logs and troubleshooting capabilities for transaction failures.

26. **Pre-auth 2FA Request Logging as Success**
    - **File**: `src/Security/Authenticator.php:67`
    - **Vulnerability/Bug**: When a user inputs valid credentials but has 2FA enabled, `Authenticator::attempt()` immediately calls `logAttempt($email, $ip, $userAgent, true)` with `success = true` before the TOTP code is ever provided or verified. This falsifies login logs (making it look like the user fully logged in when they didn't) and circumvents brute-force detection since successful logs are not counted as failures.


### Phase 5: Brand Context, Domain Resolver & Theme Engines

27. **Active Brand Session Notice/Warning in CLI/API Context**
    - **File**: `src/Service/Brand/BrandContext.php:41`, `46`, `67`, `70`, `80`, `89`, `94`
    - **Vulnerability/Bug**: In non-web environments (such as REST API routes or CLI cron commands), the global `$_SESSION` superglobal is uninitialized. Directly referencing it or trying to write to it throws `Undefined variable $_SESSION` warnings/errors, causing CLI scripts and API request processing to crash or output warnings.

28. **Missing Safe Fallback on Null Merchant Theme Resolution**
    - **File**: `src/Service/Brand/BrandThemeService.php:48-61`
    - **Vulnerability/Bug**: If a brand theme lookup queries a merchant ID that does not exist or has been deleted, `$this->db->fetchOne()` returns `null`. The code does not check for this and directly attempts to decode `$merchant['settings']` and access `$merchant['name']`, causing a fatal `TypeError` or runtime crash under strict typing.

29. **Invalid Host Port Split in Domain Service**
    - **File**: `src/Service/Domain/DomainService.php:60`, `94`
    - **Vulnerability/Bug**: `gethostbyname()` is called directly on `$_SERVER['HTTP_HOST']`. If the application is accessed via a development port (e.g. `ownpay.test:8080`), `gethostbyname()` fails to parse the port, fails to resolve DNS, and returns the original string. This instructs users to point A records to a non-IP string and breaks the automated `verifyARecord` check.

30. **Strict Typing TypeError in Form Settings Dropdowns**
    - **File**: `src/View/SettingsRenderer.php:113`
    - **Vulnerability/Bug**: `SettingsRenderer::select()` passes option labels directly to `self::e(string $value)`. If a plugin configures numeric options (such as day intervals `[30, 60, 90]` or status integer IDs), the PHP engine passes integers to `self::e()`. Under strict types, this triggers a fatal `TypeError` and crashes the plugin settings page.


### Phase 6: Checkout Controllers & Payments Logic

31. **Checkout Session Cancellation Security Bypass**
    - **File**: `src/Controller/Checkout/CheckoutController.php:455-462`
    - **Vulnerability/Bug**: The `checkout_hash` signature check is only executed if `checkout_hash` is provided in the input payload. If the parameter is omitted from the request, the signature validation check is skipped entirely, allowing any external caller with the checkout token to unilaterally cancel active transactions.

32. **Payment Intent Cancellation Security Bypass**
    - **File**: `src/Controller/Checkout/PaymentIntentCheckoutController.php:584-595`
    - **Vulnerability/Bug**: Similar to `CheckoutController::cancel()`, the signature validation is wrapped in `if ($submittedHash)`. If an attacker omits the `checkout_hash` parameter, the signature validation is bypassed, allowing unauthorized payment intent cancellations.

33. **Undefined Variable Runtime Crash in Invoice Checkout**
    - **File**: `src/Controller/Checkout/InvoiceCheckoutController.php:46`
    - **Vulnerability/Bug**: In `InvoiceCheckoutController::show()`, if the invoice is in a non-payable state (such as `draft`, `paid`, or `void`), the controller calls `renderExpired($twig, $label)`. However, the `$twig` variable is not defined until line 62, resulting in an immediate `Undefined variable $twig` fatal error/crash.

34. **PDO Prepared Limit/Offset Parameter Casting Crash**
    - **File**: `src/Repository/BaseRepository.php:99-100`, `src/Service/Payment/InvoiceService.php:28-29`
    - **Vulnerability/Bug**: In environments configured with `PDO::ATTR_EMULATE_PREPARES => false`, passing `:lim` and `:off` parameters via the `$params` execution array causes a database runtime error. PDO binds these values as strings (e.g., `'25'` or `'0'`), which MySQL syntax rules forbid for `LIMIT` and `OFFSET` clauses. This causes immediate SQL syntax error crashes on paginated listings (e.g., invoices list).

35. **Checkout Handshake Form HTML Bypass**
    - **File**: `src/Controller/Checkout/CheckoutController.php:383`, `src/Controller/Checkout/PaymentIntentCheckoutController.php:452`
    - **Vulnerability/Bug**: Both controllers enforce `!empty($result['redirect_url'])` as a strict success condition. This completely ignores the `form_html` parameter defined in `GatewayAdapterInterface` and returned by gateway adapters (e.g., fallback manual post forms). If a gateway returns only `form_html`, the controllers log an error and return a failure response, bricking standard form-based payment gateways.

36. **Strict-typing Return Type Violations in Payment/Dispute Services**
    - **File**: `src/Service/Payment/DisputeService.php:23`
    - **Vulnerability/Bug**: Methods like `DisputeService::open()` define a strict return type of `array` but return the result of `findScoped()`. Since `findScoped()` returns `?array`, if a record is not found or is null, a fatal `TypeError` is thrown at runtime under strict types.

37. **JWT Secret Compatibility/Type Mismatch in Test Suite**
    - **File**: `src/Service/Auth/JwtService.php:74`, `tests/Service/DevicePairingServiceTest.php:304`
    - **Vulnerability/Bug**: `JwtService::encode` declares `array $scopes = []` as its third parameter. However, the test suite calls it by passing a string (the JWT secret, e.g., `$jwtSecret` or `$this->secret`) as the third parameter. Under strict types, this mismatch throws a fatal `TypeError` and crashes the test suite runner.


### Phase 7: Mobile API, SMS Parsing & Device Pairing

38. **Device bulkRevoke Logic Parameter Mismatch**
    - **File**: `src/Controller/Api/Mobile/DeviceController.php:107-110`
    - **Vulnerability/Bug**: In `DeviceController::bulkRevoke`, the loop passes `(string) $id` (the database integer primary key `id` of the paired device) to `$this->devices->revoke()`. However, `DevicePairingService::revoke()` expects the first parameter to be the string `deviceUuid`. It attempts to look up the device via `findByDeviceId($deviceUuid)` which searches for a UUID matching the integer string (e.g. `"1"`), returning `null` and failing silently.

39. **Global SMS Sender Whitelist Tenant Leakage**
    - **File**: `src/Controller/Api/Mobile/ConfigController.php:47`
    - **Vulnerability/Bug**: In `ConfigController::filterRules`, the active templates are fetched using `$this->smsTemplates->forTenant($mid)->listActive()`. However, `SmsTemplateRepository::listActive()` (lines 165-170) is completely unscoped and returns all active SMS templates globally regardless of tenant ID. This leaks SMS senders of all merchants/brands to the companion app of any single brand.


### Phase 8: Admin Controllers

40. **Encrypted TOTP Secret Passed to verifyTotp in AuthController**
    - **File**: [AuthController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/AuthController.php#L113)
    - **Vulnerability/Bug**: The controller passes `$user['totp_secret_enc']` (the encrypted DB value) directly to `TwoFactorMiddleware::verifyTotp()`. When encryption is active, this passes the AES-256-GCM ciphertext rather than the decrypted base32 secret, bricking the 2FA authentication flow for all users. It should resolve the decrypted secret via `MerchantUserRepository::getTotpSecret()`.

41. **Invalid Host Port Split in DomainController Server IP Resolution**
    - **File**: [DomainController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/DomainController.php#L44)
    - **Vulnerability/Bug**: The controller resolves the server IP using `gethostbyname($req->header('Host'))`. If the host contains a port (e.g. `localhost:8000` or `ownpay.test:8080`), `gethostbyname` fails and returns the input string with the port, displaying a corrupt IP in the admin DNS configuration UI. It should extract the hostname first.

42. **Global Setting Write in FaqController**
    - **File**: [FaqController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/FaqController.php#L42)
    - **Vulnerability/Bug**: The controller calls `$this->settings->set('general', 'faqs', ...)` which modifies the global, system-wide FAQ setting. Since this route is accessible to any merchant staff with `settings.manage` permission (under `PermissionMiddleware`), non-superadmin staff from individual brands can modify the system-wide global FAQs, leading to cross-brand config tampering.

43. **Hardcoded BDT Currency in LedgerController Balance Calculation**
    - **File**: [LedgerController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/LedgerController.php#L34)
    - **Vulnerability/Bug**: The controller retrieves the merchant's ledger balance by hardcoding `'BDT'` as the currency parameter: `$ledgerService->calculateBalance($mid, 'BDT')`. If a merchant operates in another currency (like USD or EUR), this returns an incorrect/zero balance or performs calculations in the wrong currency.

44. **Global `/login` Hardcoded Redirect Bypasses Configured Admin URL Slug**
    - **File**: [DashboardController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/DashboardController.php#L168) (also line `213`), [PermissionMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/PermissionMiddleware.php#L34) (also line `48`), and [TwoFactorMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/TwoFactorMiddleware.php#L48)
    - **Vulnerability/Bug**: These files contain hardcoded redirects to `/login` when authentication is missing or fails. This bypasses the custom configured admin login slug setting (`admin_login_slug`), causing 404 Not Found errors or leaking the existence/default location of the login portal if a custom slug has been set for security obfuscation.

45. **Role ID Validation Bypass in StaffController**
    - **File**: [StaffController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/StaffController.php#L70) (also line `139`)
    - **Vulnerability/Bug**: Both `create` and `edit` actions accept a `role_id` parameter from POST requests without verifying that the role actually belongs to the active brand/merchant (`$mid`). A malicious staff member with `staff.manage` permission could perform privilege escalation by assigning a role ID belonging to another brand or a more privileged role (e.g. administrator).

46. **Encrypted TOTP Secret Leaked/Used in TwoFactorSetupController QR URI and View**
    - **File**: [TwoFactorSetupController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/TwoFactorSetupController.php#L43) (also lines `54`, `59`)
    - **Vulnerability/Bug**: The controller retrieves `$user['totp_secret_enc']` directly from the database row and uses it to construct the authenticator QR URI (`$qrUri`) and passes it as the `totp_secret` view variable. If the database field is encrypted, this leaks and uses the AES-256-GCM ciphertext rather than the decrypted base32 secret. This completely bricks the 2FA onboarding setup, as the user's authenticator app scans a corrupted key. It should decrypt the secret using `MerchantUserRepository::getTotpSecret()`.


### Phase 9: Public & Non-Mobile API Controllers & Webhooks

47. **Device Revocation Argument Mismatch and Silenced PHPStan in Admin API DeviceController**
    - **File**: [DeviceController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/DeviceController.php#L30)
    - **Vulnerability/Bug**: In `DeviceController::revoke()`, the controller executes `$this->devices->revoke($mid, $id)`. However, `DevicePairingService::revoke()` expects `string $deviceUuid` as the first parameter, and `int $merchantId` as the second parameter. Passing them backwards and passing the database integer ID `$id` instead of a UUID will cause the method to search for a UUID matching the integer string (e.g. `"1"`), failing silently. A `/** @phpstan-ignore-next-line */` comment was added to suppress this type and argument order mismatch.

48. **Domain Verification Argument Mismatch in Admin API DomainController**
    - **File**: [DomainController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/DomainController.php#L25)
    - **Vulnerability/Bug**: In `DomainController::verify()`, the controller executes `$this->domains->verifyDomain($mid, $domainId)`. However, the `DomainService::verifyDomain()` signature expects `int $domainId` as the first parameter and `int $merchantId` as the second parameter. Passing them backwards causes the database lookup to fail and always returns a "Domain not found" error, completely bricking domain verification via the API.

49. **Missing Column `attempt` in SMS Retry in CommLogRepository / SmsQueueController**
    - **File**: [CommLogRepository.php](file:///c:/laragon/www/ownpay/src/Repository/CommLogRepository.php#L100) (called by [SmsQueueController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/SmsQueueController.php#L30))
    - **Vulnerability/Bug**: In `CommLogRepository::retrySms()`, the repository updates the `op_comm_log` table setting `status = 'pending', attempt = 0`. However, the `op_comm_log` table schema contains no `attempt` column (only `id`, `merchant_id`, `channel`, `recipient`, `subject`, `body`, `provider`, `status`, `error`, `sent_at`, and `created_at`). This causes an SQL error ("Unknown column 'attempt'") and crashes the API SMS retry requests.

50. **SQL Error and TypeError due to Missing Column and Argument Type Mismatch in SmsTemplateController / SmsTemplateRepository**
    - **File**: [SmsTemplateController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/SmsTemplateController.php#L22) (also line `33`)
    - **Vulnerability/Bug**: 
      - In `SmsTemplateController::index()`, the controller calls `$this->tplRepo->listForAdmin($mid, 'event ASC')`. Since the `op_sms_templates` table does not contain an `event` column, this throws a SQL error and crashes the SMS template listing page.
      - In `SmsTemplateController::update()`, the controller calls `$this->tplRepo->updateTemplate($id, $mid, $body['body'] ?? '', (bool) ($body['enabled'] ?? true))`. However, the `SmsTemplateRepository::updateTemplate()` signature expects `updateTemplate(int $id, int $merchantId, array $data)`. Passing 4 parameters where the 3rd is a string and the 4th is a boolean triggers a fatal PHP `TypeError` under strict types, crashing the update request. A `/** @phpstan-ignore-next-line */` comment was added to silence PHPStan, hiding this broken call.


### Phase 10: Plugins, Gateway Adapters & Auxiliary Jobs

51. **Directory Prefix Bypass in Plugin Sandbox Path Validation**
    - **File**: [PluginSandbox.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginSandbox.php#L36)
    - **Vulnerability/Bug**: In `PluginSandbox::validateFilePath()`, the sandbox validates file access boundary via `str_starts_with($real, $this->pluginDir)`. Since `$this->pluginDir` is not terminated with a trailing directory separator, this allows path traversal and information leakage across sibling plugins. For example, a plugin with base directory `/modules/gateways/stripe` can access `/modules/gateways/stripe-payment/somefile.php` because the second path starts with the first path string, violating isolation boundaries between different gateway modules.

52. **Race Condition in Queue Worker Concurrent Execution (Double Job Processing)**
    - **File**: [QueueWorkerJob.php](file:///c:/laragon/www/ownpay/src/Cron/QueueWorkerJob.php#L57)
    - **Vulnerability/Bug**: In `QueueWorkerJob::run()`, a job is fetched, and then an update query `UPDATE op_job_queue SET status = 'processing', started_at = NOW() WHERE id = :id AND status = 'pending'` is run to "lock" the job. However, the worker never checks the returned row count of this update. If multiple queue worker cron/processes run concurrently, both could select the same pending job, and both will attempt to lock it. One will successfully update the row (returning 1), while the other updates 0 rows because the status is no longer 'pending'. Since the return value of the update is ignored, BOTH workers will proceed to execute the same job handler, causing duplicate job execution (such as double ledger transactions or duplicate customer emails).

53. **Successful Webhook Retries Never Marked as Delivered in Communication Log**
    - **File**: [WebhookRetryJob.php](file:///c:/laragon/www/ownpay/src/Cron/WebhookRetryJob.php#L57)
    - **Vulnerability/Bug**: In `WebhookRetryJob::run()`, if retrying a failed webhook delivery succeeds (`$success` is true), the worker only increments the counter (`$succeeded++`) but never updates the database row in `op_comm_log`. Because `status` remains `'failed'` and `next_retry_at` is not advanced or cleared, the same delivery record will be fetched and delivered again on the very next cron execution, resulting in endless duplicate webhook notifications to the merchant's callback URL until either the system maxes out the memory or the endpoint starts returning a failure.

54. **SQL Restore Split Corruption on Text Fields Containing Semicolon + Newline**
    - **File**: [BackupService.php](file:///c:/laragon/www/ownpay/src/Update/BackupService.php#L163) (also in [UpdateService.php](file:///c:/laragon/www/ownpay/src/Update/UpdateService.php#L353))
    - **Vulnerability/Bug**: In `BackupService::restoreDatabase()` and `UpdateService::runMigrations()`, the database SQL file is parsed by simply exploding the SQL content using `";\n"` as a delimiter. If any database column (such as transaction metadata, SMS message bodies, error messages, or settings) contains a semicolon followed by a newline, the string literal will be split in half. This causes both halves of the split string to be executed as separate invalid SQL statements, leading to database syntax errors and massive data corruption or failure during a system restore/rollback.


### Phase 11: Views, Twig Templates & Frontend UI

55. **Script Breakout XSS via Unescaped JSON Object Injection (`json_encode|raw`)**
    - **Files**: 
      - [checkout.twig](file:///c:/laragon/www/ownpay/templates/checkout/checkout.twig#L58-L59) (Lines 58, 59)
      - [checkout-status.twig](file:///c:/laragon/www/ownpay/templates/checkout/checkout-status.twig#L106-L108) (Lines 106-108)
    - **Vulnerability/Bug**: In multiple script contexts, raw json-encoded variables are printed directly:
      ```twig
      window.OP_CHECKOUT_CONFIG={{ config|json_encode|raw }};
      var redirectUrl = {{ merchant_redirect_url|json_encode|raw }};
      ```
      Since PHP's `json_encode` without special flags does not escape HTML-sensitive characters like `<` and `>`, any user-supplied or merchant-supplied values (e.g., payment description, billing info, metadata, or the `merchant_redirect_url` string) containing `</script><script>alert(1)</script>` will close the current block, break out of the script tag, and inject malicious scripts. This allows arbitrary JavaScript execution in the customer's browser.

56. **Inline Attribute Single-Quote Breakout & XSS Injection**
    - **File**: [_gateway-grid.twig](file:///c:/laragon/www/ownpay/templates/checkout/partials/_gateway-grid.twig#L7) (also Lines 31, 55)
    - **Vulnerability/Bug**: Gateway names are directly interpolated within inline HTML `onclick` attributes:
      ```twig
      onclick="pickGW(this,'card','{{ gw.slug }}','{{ gw.name }}','{{ gw.mode ?? 'api' }}')"
      ```
      If a gateway is named `Stripe's Gateway`, the single quote breaks the inline JS string literal, throwing a `SyntaxError: missing ) after argument list` and crashing checkout. More critically, a malicious merchant or a brand administrator with gateway management permission can name a manual gateway `Gateway' + alert(document.cookie) + '`, causing the browser to execute arbitrary JavaScript in the customer's checkout room and steal payment card details.


