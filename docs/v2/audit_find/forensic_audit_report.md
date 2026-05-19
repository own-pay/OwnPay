# OwnPay V0.1.0 Forensic Audit Report

## 1. Executive Summary
Conducted ruthless, line-by-line forensic architectural and codebase audit. Identified 6 critical enterprise-grade vulnerabilities across multi-tenancy isolation, ledger integrity, security sandboxing, and core business logic.

## 2. Critical Findings

### 2.1 Ledger System Complete Failure (DB Write Exception)
**Location:** `src/Service/Payment/LedgerService.php` (Line 48)
**Description:** `LedgerService` handles double-entry bookkeeping but fails to invoke `$this->ledger->forTenant($merchantId)` before creating transactions. Consequently, `$this->tenantId` inside `LedgerRepository` remains `null`.
**Impact:** `op_ledger_transactions.merchant_id` is defined as `BIGINT UNSIGNED NOT NULL`. The `INSERT` query attempts to insert `NULL`, triggering a PDO Exception (`Column 'merchant_id' cannot be null`). This hard-crashes the entire payment completion process and prevents any journal entries from being written.

### 2.2 Multi-Brand Data Leak & Collision via Invoice Lookups
**Location:** `src/Controller/Checkout/InvoiceCheckoutController.php` (Line 31) & `src/Repository/InvoiceRepository.php` (Line 45)
**Description:** The checkout route `/invoice/{token}` reads the token parameter but queries `findUnpaidByNumber($invoiceNumber)` using it as the `invoice_number`. The database schema explicitly enforces `UNIQUE KEY uk_merchant_number (merchant_id, invoice_number)`.
**Impact:** `invoice_number` (e.g. `INV-000001`) is only unique *per merchant*, not globally. Without tenant scoping, this query returns the first matching invoice in the database regardless of the brand. Customers paying Brand B's invoice may be silently redirected to Brand A's invoice, causing cross-tenant data leaks and misdirected payments.

### 2.3 Total SMS Verification Matching Bypass
**Location:** `src/Cron/SmsVerificationJob.php` (Line 41)
**Description:** The cron job attempts to read `$sms['parsed_trx_id']` and `$sms['parsed_amount']` from the row array. However, `SmsParsedRepository` queries the `op_sms_parsed` table via `SELECT *`, which returns the actual database column names: `trx_id` and `amount`.
**Impact:** Because the keys `parsed_trx_id` and `parsed_amount` do not exist in the returned array, `$trxId` and `$amount` evaluate to `null`. The job immediately executes `continue;` on every single SMS record, permanently breaking automated mobile SMS transaction matching.

### 2.4 Trivial Plugin Sandbox Arbitrary Code Execution (ACE)
**Location:** `src/Plugin/PluginLoader.php` (Line 160)
**Description:** The `PluginLoader` enforces sandboxing by scanning for dangerous functions (e.g., `exec`, `system`, `eval`) via regex. However, it *only* scans the single `entrypointFile` defined in `manifest.json`.
**Impact:** A malicious plugin can completely bypass the sandbox by placing dangerous PHP shell execution code in a secondary file (e.g., `Helper.php` or inside a bundled `vendor/` directory) and simply `require`ing that file from the entrypoint. This grants full remote code execution on the host server.

### 2.5 Webhook Payload Spoofing via GET Parameter Overwrite
**Location:** `src/Controller/Webhook/UnifiedWebhookController.php` (Line 90)
**Description:** The webhook handler merges GET query parameters directly over the parsed POST JSON/form-data body: `array_merge($callbackData, $queryParams)`.
**Impact:** If a gateway adapter relies on values within `$callbackData` for verification, an attacker can trivially spoof fields by appending them to the URL (e.g., `?status=completed&amount=100.00`). `array_merge` overwrites identical keys from the first array with the second, nullifying the gateway's authentic POST payload.

### 2.6 Staff Trapped in Brand Context (RBAC Flaw)
**Location:** `src/Middleware/PermissionMiddleware.php` (Line 114) & `src/Controller/Admin/BrandController.php` (Line 141)
**Description:** The route mapper resolves any `POST /admin/brands/*` request to the `brands.manage` permission. The brand switcher endpoint is `POST /admin/brands/switch`.
**Impact:** Staff members assigned to multiple brands who lack the `brands.manage` permission (which is strictly for creating/editing brands) are explicitly denied access to the brand switcher. They are permanently locked into their initial brand session and cannot navigate the system as intended.

### 2.7 Complete Mobile API Failure via UUID Integer Casting
**Location:** `src/Controller/Api/Mobile/DeviceController.php` (Line 80) & `SmsController.php` (Line 33)
**Description:** Mobile API controllers extract the `device_id` (a UUID string such as `f47ac10b-...`) from the JWT via `$req->getAttribute('device_id')` and cast it to an `(int)`. In PHP, casting a UUID string to an integer results in `0`.
**Impact:** `SmsController::receive` passes `'0'` to `SmsParserService`, causing it to immediately reject every SMS batch with `DEVICE_NOT_FOUND`. Similarly, `DeviceController::revoke` attempts to revoke device `'0'`, silently failing to revoke actual malicious or lost devices. The entire mobile API and device management system is fundamentally broken by this type casting error.

## 3. Recommendation
Code freeze. Address the above 7 critical vulnerabilities immediately prior to `v0.1.0` release. No further features should be developed until these architectural and integrity flaws are patched.
