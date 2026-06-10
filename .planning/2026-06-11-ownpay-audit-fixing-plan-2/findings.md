# Findings & Discoveries

Below is the verification status and details for each finding we are fixing:

1. **FIND-003**: `Database::getInstance()` throws in production.
   - **Status**: Already fixed. The container singleton factory now calls `Database::setInstance($db)` on boot.

2. **FIND-004**: Un-gated mock-token payment-confirmation bypass in Affirm, Afterpay, Bitpay.
   - **Status**: Already fixed. Adapters have been hardened to reject `mock_` tokens in `'live'` mode, and throw on empty gateway responses.

3. **FIND-001**: `MfsService` passes parser arguments in swapped order.
   - **Status**: Already fixed. The parameter order was swapped to `$body, $sender, $merchantId`.

4. **FIND-005**: Gateway `verifyWebhook()`/`refund()` stubs in 2Checkout.
   - **Status**: Already fixed. 2Checkout adapter has timing-safe HMAC checks, and live refunds are blocked.

5. **FIND-019**: Device pairing bootstrap blocked by JWT middleware.
   - **Status**: Already fixed. A `'mobile-bootstrap'` middleware group without `JwtAuthMiddleware` was created, and pairing routes were redirected to it.

6. **FIND-002**: External gateway cURL call executed inside a DB transaction holding `FOR UPDATE` locks.
   - **Status**: Fixed. Refactored `RefundService::create()` using a saga-like pattern: Phase 1 creates a pending refund and commits to release FOR UPDATE locks; Phase 2 executes the outbound gateway cURL call; Phase 3 reconciles and commits in a second short transaction.

7. **FIND-006**: PHPUnit version 12 requires PHP >= 8.3 vs composer.json PHP 8.2 requirement.
   - **Status**: Fixed. Upgraded the project floor requirement to PHP `^8.3` in `composer.json` and locked `phpunit/phpunit` to `12.5.29` as requested. Also updated `InstallerController` and `RequirementsChecker` PHP requirements to `≥ 8.3`.

8. **FIND-007**: Rate limiter fails open on database/Redis error.
   - **Status**: Fixed. Modified `RateLimiterMiddleware::handle()` to return a `503 Service Unavailable` error response on database failure for sensitive auth endpoints (login, 2fa, pairing, etc.).

9. **FIND-009**: Plugin with no sandbox bypasses `db.query.before` SQL validation.
   - **Status**: Fixed. Hardened `EventManager::applyFilter()` to throw a `RuntimeException` if a plugin modifies a query without a defined active sandbox.

10. **FIND-016 & FIND-017**: Callback amount verification and SMS TrxID namespace mismatch.
    - **Status**: Fixed. In `GatewayApiService::handleCallback()`, verified callback amount matches the transaction amount. Created `provider_trx_id` column in `op_transactions` and set up lookup via `findByProviderTrxId` in `TransactionRepository` for `SmsVerificationJob::run()`.

11. **FIND-008**: Webhook SSRF validation gaps (DNS-rebinding TOCTOU & IPv6 AAAA).
    - **Status**: Fixed. Updated `UrlValidator::isValidWebhookUrl()` to resolve AAAA records and validate IPv6. Configured `HttpClient` to resolve DNS once and pin the IP via `CURLOPT_RESOLVE`.

12. **FIND-010**: `DomainMiddleware` hardcodes `localhost` passthrough.
    - **Status**: Fixed. Restructured `DomainMiddleware::handle()` to allow localhost passthrough only when client IP is a local loopback client (`127.0.0.1`, `::1`, `localhost`).

13. **FIND-011**: Invoice totals can go negative.
    - **Status**: Fixed. Clamped line item unit prices to `max(0, ...)` and final totals to be non-negative in `InvoiceService`.

14. **FIND-014**: `form_html` sanitizer keeps inline `<script>` tags.
    - **Status**: Fixed. Hardened `GatewayApiService::sanitizeFormHtml()` to strip all scripts except safe HTML form auto-submissions.

15. **FIND-015**: Mobile notifications fallback to shared, unlocked temp-file.
    - **Status**: Fixed. Rewrote `MobileNotificationService::queueNotification()` temp-file fallback to use exclusive file locking (`flock`) and owner-only permissions (`0600`).

16. **FIND-012**: `Authenticator` TOTP drift window is ±2 steps.
    - **Status**: Fixed. Tightened the default discrepancy limit parameter `$discrepancy` in `verifyCodeWithReplayGuard()` from 2 steps (60s) to 1 step (30s). Added a unit test `test_default_discrepancy_limit` to verify the 1-step constraint.
