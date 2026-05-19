# Phase 3 Audit: SMS, Mobile, DB Schema & Test Integrity

This document presents findings from the forensic audit of OwnPay v0.1.0, focusing on the SMS verification cron job, mobile API controller safety, testing infrastructure integrity, and the database schema (`database/schema.sql`).

---

## 1. SMS Verification Cron Failures & Logical Crashes

### 1.1 Method Signature Mismatch (Fatal Argument Count)
* **Location**: `src/Cron/SmsVerificationJob.php` line 61 Calling `TransactionRepository::findPendingMatch()`
* **Declaration**: `src/Repository/TransactionRepository.php` line 125
* **Issue**:
  - `SmsVerificationJob.php` attempts to match parsed SMS transactions by calling:
    ```php
    $txn = $this->transactionRepo->findPendingMatch(
        $sms['amount'],
        $sms['gateway_slug'],
        $sms['received_at'],
        300 // 5-minute window tolerance
    );
    ```
  - However, `TransactionRepository::findPendingMatch()` is declared as:
    ```php
    public function findPendingMatch(int $merchantId, string $amount, string $gatewaySlug): ?array
    ```
  - This mismatch (4 parameters passed instead of 3; mismatch in semantic ordering where `merchant_id` is missing and amount/gateway are shifted) results in a fatal error or incorrect parameters mapping at runtime, completely breaking the SMS match flow.

### 1.2 Unscoped Tenant Scope Query in Cron Job (Fatal LogicException)
* **Location**: `src/Cron/SmsVerificationJob.php` line 36
* **Issue**:
  - The job starts its execution loop with:
    ```php
    $unmatched = $this->smsParsed->getUnmatched(100);
    ```
  - `SmsParsedRepository` uses the `TenantScope` trait. Its `findUnmatched` method invokes `$this->requireTenant()`.
  - Because `SmsVerificationJob` is a background CLI cron script, no HTTP middleware is active to automatically populate the tenant/brand context. Since the script never calls `forTenant($merchantId)` on the repository prior to fetching unmatched records, `$this->requireTenant()` immediately throws:
    ```
    LogicException: Tenant scope not set on OwnPay\Repository\SmsParsedRepository. Call forTenant() first.
    ```
  - The background daemon crashes on the very first execution iteration.

---

## 2. Test Suite Compilation Errors & Omissions

### 2.1 UTF-8 BOM Header Failures (Compilation Blockers)
* **Location**: 18 PHP test files under `tests/` directory:
  - `tests/Service/SmsParserServiceTest.php`
  - `tests/Service/DomainServiceTest.php`
  - `tests/Service/CurrencyServiceTest.php`
  - `tests/Service/CustomerServiceTest.php`
  - `tests/Service/AuditServiceTest.php`
  - `tests/Service/AdminSessionTest.php`
  - `tests/Security/AuthenticatorTest.php`
  - `tests/Plugin/PluginLoaderTest.php`
  - `tests/Middleware/TwoFactorMiddlewareTest.php`
  - `tests/Middleware/RateLimiterMiddlewareTest.php`
  - `tests/Middleware/PermissionMiddlewareTest.php`
  - `tests/Middleware/IpAllowlistMiddlewareTest.php`
  - `tests/Middleware/DomainMiddlewareTest.php`
  - `tests/Middleware/CsrfMiddlewareTest.php`
  - `tests/Middleware/BearerAuthMiddlewareTest.php`
  - `tests/Event/EventManagerTest.php`
  - `tests/Controller/RolesControllerTest.php`
  - `tests/Controller/FaqControllerTest.php`
* **Issue**:
  - These files were saved containing a UTF-8 Byte Order Mark (BOM) (`\xEF\xBB\xBF`).
  - In PHP, the BOM is output as whitespace/output before compilation starts. As a result, the subsequent `declare(strict_types=1);` statement is no longer the absolute first expression in the file.
  - Running PHPUnit or executing these files results in:
    ```
    Fatal error: strict_types declaration must be the very first statement in the script
    ```
  - The presence of the BOM halts test suite execution globally.

### 2.2 XML Suite Omissions (Silenced Failures)
* **Location**: `phpunit.xml`
* **Issue**:
  - The main `phpunit.xml` configuration has omitted the `<testsuite>` entries corresponding to these directories (`tests/Service/`, `tests/Middleware/`, `tests/Controller/`, `tests/Event/`, `tests/Plugin/`, `tests/Security/`).
  - This hides the fatal compilation errors from being reported during general test suites runs, letting broken/uncompilable test coverage slip into the release bundle.

### 2.3 Obsolete Columns & markedSkipped Integrations
* **Location**: `tests/Integration/AdminFeaturesIntegrationTest.php`, `tests/Integration/NotificationDashboardIntegrationTest.php`, `tests/Integration/SmsParsingIntegrationTest.php`
* **Issue**:
  - These test suites attempt to query or insert database entries using obsolete schema column names (e.g., `device_uuid`, `parsed_amount`, `parsed_trx_id`, `status` instead of `device_id`, `amount`, `trx_id`, `match_status` respectively).
  - Rather than updating these test cases to align with the database, they have been marked as skipped via `$this->markTestSkipped()` in the setup phases.
  - This leads to a false sense of test coverage, leaving crucial integrations completely untested.

### 2.4 DB Configuration Mismatch in phpunit.xml
* **Location**: `phpunit.xml` & `tests/Integration/IntegrationTestCase.php`
* **Issue**:
  - The default test environment variables in `phpunit.xml` target a local database instance `ownpay_test` with password `""` (blank).
  - Laragon / MySQL default database installations on local dev machines use `root` as the password. Because of the database connection failure, integration tests gracefully catch connection errors and skip rather than raising validation failures.

---

## 3. Database Schema (`database/schema.sql`) Audit & Drift

A detailed cross-reference was performed between table definitions in `database/schema.sql` and model/repository properties:

### 3.1 Missing Indexes (Performance Penalties)
* **Table**: `op_transactions`
* **Issue**:
  - External checkout integrations, gateway return parameters, and webhook callback processors search for matching transaction records using the `gateway_trx_id` field (e.g., Stripe PaymentIntent ID, SSLCommerz session keys).
  - Currently, `op_transactions` does not define an index on `gateway_trx_id`. Every single webhook payload or checkout return handler forces a full table scan on `op_transactions` to find the corresponding local row, creating a denial of service vulnerability under peak traffic.

### 3.2 Global Exchange Rates vs. Tenant Isolation Drift
* **Table**: `op_exchange_rates`
* **Issue**:
  - The database defines `op_exchange_rates` globally (no `merchant_id` or scoping field).
  - However, in a multi-brand context, different brands (merchants) may require distinct exchange markups or completely custom exchange conversion rules. Because the schema enforces global-only base-to-target records, the platform cannot support custom, brand-scoped currency exchange rates.

### 3.3 Strict Foreign Key Enforcement on Superadmins
* **Table**: `op_merchant_users`
* **Issue**:
  - The table enforces `role_id BIGINT UNSIGNED NOT NULL` with a foreign key constraint to `op_roles`.
  - In code, superadmin users (`is_superadmin = 1`) bypass RBAC permissions checks. However, the database layer does not allow `role_id` to be null. Any installer script or migration must provision a placeholder role for superadmins simply to satisfy the database schema's strict foreign key constraints.

---

## Summary of Resolution Actions Required (Next Phase)

1. **Fix signature in `SmsVerificationJob.php`** to supply `merchant_id` first and map correct arguments to `findPendingMatch`.
2. **Implement tenant scoping loop in `SmsVerificationJob.php`** (querying active merchants first, then invoking `forTenant($mid)`).
3. **Strip UTF-8 BOM characters** from the 18 uncompilable test scripts.
4. **Restore missing suites** to `phpunit.xml` configuration.
5. **Add missing index** on `op_transactions(gateway_trx_id)` in `schema.sql`.
6. **Align integration test models** with V2 column names and remove skip flags.
