# Findings & Decisions — OwnPay Bug & Business Logic Audit

## Requirements
- In-depth audit for bugs, missing business logic.
- Cover all critical subsystems: auth, payments, ledger, tenancy, plugins, APIs, checkout.
- Identify security gaps and architectural inconsistencies.
- Produce actionable findings with severity ratings.

## Research Findings

### Phase 1: Codebase Structure
- **Architecture Validation:** The system is single-owner, multi-brand (merchants table with `merchant_id`). We verified the Boot sequence, PSR-11 lightweight container resolver in `src/Container.php`, `BaseRepository` extending, and `TenantScope` traits.
- **Hook Extensibility:** Verified `src/Event/EventManager.php` hook actions (`doAction`, `applyFilter`) which execute dynamic third-party and internal hooks.

### Phase 2: Auth & Authorization
1. **Critical: `/2fa` Rate Limiting Bypass:** The GET and POST `/2fa` routes in `config/routes/web.php` are mapped to the `'web'` middleware pipeline. Since the `'web'` pipeline does not include the `RateLimiterMiddleware`, an attacker can easily run unlimited brute-force attempts on the 6-digit TOTP token without lockout.
2. **Medium: Admin Session Key Injection Privilege Escalation:** `AdminSession::set()` does not restrict keys, permitting arbitrary modifications of critical session keys like `is_superadmin` or `auth_role_id` if a controller exposes dynamic key-value updates.
3. **Low: Argon2id Login timing Leak Enumeration:** In `Authenticator::attempt()`, if the user does not exist, it runs `password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$dummy$dummy')`. Because `$argon2id$v=19$m=65536,t=4,p=1$dummy$dummy` is not a valid Argon2id hash format, PHP's `password_verify` rejects it instantly (<1ms), while valid accounts trigger the heavy CPU hash check (100-200ms). This allows email enumeration timing attacks.

### Phase 3: Payment & Financial
1. **Critical: Inbound Webhook Processing Failure:** `GatewayApiService::handleCallback` only processes transactions that are in a `'pending'` state. However, during checkout initiation, `PaymentIntentCheckoutController::pay()` transitions the transaction status to `'processing'`. When the gateway returns a callback, it gets rejected as `Transaction not found or already processed`, silently failing webhooks for all checkout-initiated transactions.
2. **Critical: Double Ledger Debit Posting:** `WebhookInboundProcessor::handlePaymentCompleted()` and `GatewayApiService::handleCallback()` can record payment received, resulting in duplicated debit entries in the double-entry ledger.
3. **High: Missing Enum Case `'callback_processing'`:** `TransactionStatus` enum is missing `'callback_processing'` which is set dynamically in `PaymentIntentCheckoutController::status` (atomic row claim lock). This causes severe deserialization ValueError exceptions when the system loads/maps transactions.
4. **High: Plugin Webhook Signature Verification Bypass:** In `UnifiedWebhookController::handle()`, if a plugin registers `webhook.incoming.{gateway}`, the controller runs the plugin action *before* signature checking, allowing unauthenticated attackers to spoof payloads.
5. **High: Missing Webhook Signature Middleware:** `config/middleware.php` omits `RequestSignatureMiddleware` from the `webhook` group, leaving inbound webhooks vulnerable if they do not perform manual checks.

### Phase 4: Tenant Isolation
1. **Critical: Staff Brand Switching Access Bypass:** `BrandController::switchBrand` contains no checks to verify that a non-superadmin belongs to the `brand_id` they switch to. This allows any brand staff member to switch their active session brand to any other merchant in the system, gaining full access to their private repos via `TenantScope`.

### Phase 5: API & Input Validation
- Parameterized queries are generally followed, but the lack of rate limiting on login/2fa and lack of signature checks in the webhook pipeline represent significant input validation and API vulnerabilities.

### Phase 6: Business Logic & Cron Jobs
1. **Critical: Broken Webhook Retry Job:** `WebhookRetryJob.php` references `op_webhook_endpoints` (does not exist, correct table is `op_webhooks`) and non-existent columns in `op_comm_log` (`entity_id`, `retry_count`, `next_retry_at`, `content`, `event_type`), causing a fatal crash every time the cron executes.
2. **Medium: Domain Verification Stale Timestamp Bug:** `DnsVerificationJob.php` updates `op_domains` using `'verified_at'`, but the database column is `'dns_verified_at'`. This update is silently ignored because it's not fillable, and the verification timestamp is lost.
3. **Medium: SMS Matching Transaction Stuck in Pending:** `SmsVerificationJob.php` correctly matches SMS records to transactions, but fails to transition the transaction `status` to `'completed'` or call `complete()`. This leaves matched transactions permanently `'pending'` with no ledger entries.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Lock down `/2fa` route with `RateLimiterMiddleware` | Essential security safeguard against automated brute force timing attacks. |
| Add validation to `switchBrand` | Enforces tenant isolation for brand staff members. |
| Correct table references in `WebhookRetryJob` | Prevents fatal errors during cron execution and restores webhook retry capability. |
| Dynamic Rate Limiting in `RateLimiterMiddleware` | Directs route-specific rate limits (login, API, global) based on request path. |
| Context-based exception logging | Safe exceptions logging inside structured context JSON to block log injection. |
| Database Transaction boundaries in `SmsVerificationJob` | Encapsulates state complete + ledger posting in a database transaction block. |

## Bug Registry
| ID | Severity | Category | File | Description | Status |
|----|----------|----------|------|-------------|--------|
| AUD-001 | Critical | Auth | `config/routes/web.php` | GET/POST `/2fa` bypasses rate-limiting. | Closed |
| AUD-002 | Critical | Tenant | `src/Controller/Admin/BrandController.php` | Staff brand switching logic bypass. | Closed |
| AUD-003 | Critical | Payments | `src/Service/Payment/GatewayApiService.php` | Inbound webhook callback rejects `'processing'` transactions. | Closed |
| AUD-004 | Critical | Cron | `src/Cron/WebhookRetryJob.php` | Webhook retry job references non-existent tables/columns. | Closed |
| AUD-005 | High | Security | `src/Controller/Webhook/UnifiedWebhookController.php` | Plugin webhook bypasses signature verification. | Closed |
| AUD-006 | High | Enums | `src/Enum/TransactionStatus.php` | Missing `'callback_processing'` enum state. | Closed |
| AUD-007 | Medium | Cron | `src/Cron/DnsVerificationJob.php` | Field mismatch (`verified_at` vs `dns_verified_at`) silently discards timestamp. | Closed |
| AUD-008 | Medium | Cron | `src/Cron/SmsVerificationJob.php` | Matching SMS does not complete transaction state. | Closed |
| AUD-009 | Low | Security | `src/Security/Authenticator.php` | Invalid format of dummy hash leaks user existence via timing. | Closed |
| AUD-010 | Critical | Rate Limit | `src/Middleware/RateLimiterMiddleware.php` | Reads non-existent `api_per_minute` configuration, bypassing stricter login rate limits. | Open |
| AUD-011 | High | Security | `src/Controller/Webhook/UnifiedWebhookController.php` | Exception message concatenation in warning log enables log injection / forging. | Open |
| AUD-012 | High | Financial | `src/Cron/SmsVerificationJob.php` | Marks transaction completed before ledger write, risking un-journaled financial state. | Open |

---
*Update this file after every 2 view/browser/search operations*
*This prevents visual information from being lost*

