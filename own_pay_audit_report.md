# OwnPay — Adversarial Audit Report (own_pay_audit_report.md)
**Full Security, Business Logic, Code Quality, Architecture, and Compliance Review**

---

## Part 1 — Executive Summary

### Total Findings by Severity
| Severity | Count | Domain | Description |
|---|---|---|---|
| **CRITICAL** | 0 | — | No critical vulnerabilities detected |
| **HIGH** | 1 | B-Business Logic | Suspended Merchant Transaction Leakage |
| **MEDIUM** | 1 | B-Business Logic / C-Code | Floating-Point Arithmetic on Invoices |
| **LOW** | 1 | D-Infra / Database | Missing Referential Integrity on Invoices |
| **INFO** | 0 | — | No informational findings |

### Overall Platform Risk Rating
**MEDIUM / CONSTRAINED**

The OwnPay platform features robust and secure engineering underpinnings. Critical security vectors—including Argon2id password hashing, TOTP authentication challenge replay protection, timing-safe API/HMAC comparison boundaries, SQL injection parameters, and custom white-label domain routing rules—have been verified as secure. However, a significant gap in merchant lifecycle control (allowing suspended merchants to continue processing payments) and minor precision issues in invoice float math restrict this to a **Conditional** deployment readiness assessment.

### Top Vulnerabilities Identified
1. **HIGH:** Suspended Merchant Transaction Leakage (`src/Middleware/BearerAuthMiddleware.php`)
2. **MEDIUM:** Floating-Point Precision Loss in Billing Calculations (`src/Service/Payment/InvoiceService.php`)
3. **LOW:** Missing Foreign Key Referential Integrity on Invoices (`database/schema.sql`)

### Deployment Readiness Assessment
**Conditional (Ready after immediate remediation of HIGH severity findings)**

### Dangerous Attack Scenario
An attacker representing a suspended, malicious merchant brand exploits the lack of status checks in `BearerAuthMiddleware` and `DomainMiddleware`. By using their previously active API keys, the suspended merchant continues to issue payment intents, generate checkout URLs, and collect live customer payments on their custom domain. Because the gateway credentials and domains remain functional despite administrative suspension, the merchant executes a transaction-evasion exit scam, collecting customer funds before the super-administrator can intervene.

---

## Part 2 — System Map Summary

### System Architecture
OwnPay is engineered as a single-owner, multi-brand payment gateway architecture on PHP 8.2+.
* **Core Framework:** PSR-4 standard autowired Dependency Injection Container (`src/Container.php`) and a central HTTP Front Controller (`public/index.php` -> `src/Kernel.php`).
* **Settings Engine:** Database-driven setting persistence using MySQL runtime configs (`op_system_settings` table), replacing legacy configurations.
* **White-Labeling & Routing:** Enforced custom domain resolution (`DomainMiddleware.php`) mapping requests to separate brands, and protecting administrative `/admin` prefixes from custom host exposure.

### Primary Entry Points
* **Public Checkout:** `/checkout/{token}` -> `CheckoutController@show`
* **API Ingestion:** `/api/v1/payments/initiate` -> `PaymentController@initiate`
* **Incoming Webhooks:** `/webhook/{gateway}` -> `UnifiedWebhookController@handle`
* **Admin Login & Dashboard:** `/{login_slug}` -> `AuthController@login`

### Primary Data Flows
1. **Initiate Payment:** API requests create records in `op_payment_intents` and generate checkout links mapped through `DomainUrlService`.
2. **Double-Entry Postings:** Completed captures trigger `LedgerService::postEntries` executing atomic balance modifications and verifying balanced journal debits/credits via BCMath.
3. **Customer Deletion (GDPR):** Customer deletion uses atomic queries. Referential integrity rules set customer IDs in `op_transactions` to `NULL` to scrub PII without tampering with financial ledger history.

---

## Part 3 — Detailed Findings

### FINDING-001
-----------------------------------------------------------------------
Severity     : HIGH
Domain       : B-Business Logic
Category     : Suspended Merchant Payment Leakage
Location     : `src/Middleware/BearerAuthMiddleware.php` line 94
               `src/Middleware/DomainMiddleware.php` line 88
               `src/Controller/Checkout/CheckoutController.php` line 128
-----------------------------------------------------------------------
Description  : The system does not check the status of a merchant/brand when validating their API credentials in `BearerAuthMiddleware` or routing their white-label domains in `DomainMiddleware`. Consequently, a merchant whose status is explicitly set to `suspended` in `op_merchants` can still authenticate, initiate payment sessions, and process checkout transactions.

Evidence     : 
In `src/Middleware/BearerAuthMiddleware.php` lines 92-97:
```php
        // Inject merchant context into request
        $request->setAttribute('api_key', $apiKey);
        $request->setAttribute('merchant_id', (int) $apiKey['merchant_id']);

        // Touch last_used (fire-and-forget)
        $repo->touchLastUsed((int) $apiKey['id']);
```

In `src/Middleware/DomainMiddleware.php` lines 105-110:
```php
        // Set request attributes to propagate resolved brand parameters down the application pipeline.
        $request->setAttribute('domain', $domainRecord);
        $request->setAttribute('merchant_id', (int) $domainRecord['merchant_id']);
        $request->setAttribute('domain_type', $domainRecord['type']);
        $request->setAttribute('custom_domain', $domain);
```

Attack Path  : 
1. An administrator sets a merchant status to `suspended` or `pending` in the DB or admin panel.
2. The suspended merchant issues an API call to `/api/v1/payments/initiate` using their valid API key.
3. `BearerAuthMiddleware` accepts the key and propagates the `merchant_id` context.
4. `PaymentController` initiates the transaction and returns a checkout link.
5. A customer opens the checkout link on the custom domain (which resolves fine via `DomainMiddleware`).
6. The customer successfully pays and captures funds.

Impact       : Financial containment failure. Suspended brands can bypass admin-imposed blocks to process payments, leading to potential fraud, chargeback exposure, or regulatory breaches.

Fix          : Query the merchant status dynamically from `op_merchants` and reject execution if the status is not `active`.

For `BearerAuthMiddleware.php`:
```php
        $merchant = $this->container->get(\OwnPay\Repository\MerchantRepository::class)->find((int) $apiKey['merchant_id']);
        if ($merchant === null || ($merchant['status'] ?? 'active') !== 'active') {
            return Response::json(['success' => false, 'message' => 'Merchant account is suspended or inactive'], 403);
        }
```

For `DomainMiddleware.php`:
```php
        $merchant = $this->container->get(\OwnPay\Repository\MerchantRepository::class)->find((int) $domainRecord['merchant_id']);
        if ($merchant === null || ($merchant['status'] ?? 'active') !== 'active') {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }
```

For `CheckoutController.php`:
```php
        $merchant = $this->merchants->find($mid);
        if ($merchant === null || ($merchant['status'] ?? 'active') !== 'active') {
            return $this->renderStatus($ref, 'expired');
        }
```

Reference    : CWE-285 (Improper Authorization), ASVS v4.0.3 - 4.1.1.
-----------------------------------------------------------------------

### FINDING-002
-----------------------------------------------------------------------
Severity     : MEDIUM
Domain       : B-Business Logic
Category     : Floating-Point Precision Loss
Location     : `src/Service/Payment/InvoiceService.php` lines 129-142
-----------------------------------------------------------------------
Description  : The invoice service calculates sub-totals, unit multiplications, taxes, discounts, and final totals using PHP's native floating-point types (`(float)` casts and standard mathematical operators `*`, `+`, `-`). Floating-point representations lack arbitrary precision and can lead to minor rounding errors (e.g., 19.99 * 3 = 59.969999... instead of 59.97), causing ledger mismatches and billing discrepancies.

Evidence     : 
In `src/Service/Payment/InvoiceService.php` lines 129-142:
```php
        $items = $data['items'] ?? [];
        $subtotal = 0;
        foreach ($items as &$item) {
            $qty   = max(1, (int) ($item['quantity'] ?? 1));
            $price = (float) ($item['unit_price'] ?? $item['amount'] ?? 0);
            $item['quantity']   = $qty;
            $item['unit_price'] = $price;
            $item['total']      = $qty * $price;
            $subtotal += $item['total'];
        }
        unset($item);

        $tax      = (float) ($data['tax'] ?? 0);
        $discount = (float) ($data['discount'] ?? 0);
        $total    = $subtotal + $tax - $discount;
```

Attack Path  : 
1. Create an invoice with items that have fractional values.
2. The floating-point rounding issue manifests as a minor fractional discrepancy (e.g., 0.01 cent error) on large scale or complex tax calculations.
3. When compiling accounting journals, these roundings fail strict matching in ledger entries where debits must balance credits to the exact penny.

Impact       : Discrepancies in billing, minor financial mismatches in the transaction ledger, and eventual validation crashes when ledger postings fail the strict balance verification.

Fix          : Use standard `bcadd`, `bcmul`, and `bcsub` functions (using BCMath extension) for all currency and financial calculations.
```php
        $subtotal = '0.00';
        foreach ($items as &$item) {
            $qty   = (string) max(1, (int) ($item['quantity'] ?? 1));
            $price = number_format((float) ($item['unit_price'] ?? $item['amount'] ?? 0), 2, '.', '');
            $item['quantity']   = (int) $qty;
            $item['unit_price'] = (float) $price;
            
            $itemTotal = bcmul($qty, $price, 2);
            $item['total'] = (float) $itemTotal;
            $subtotal = bcadd($subtotal, $itemTotal, 2);
        }
        unset($item);

        $tax      = number_format((float) ($data['tax'] ?? 0), 2, '.', '');
        $discount = number_format((float) ($data['discount'] ?? 0), 2, '.', '');
        
        $total = bcadd($subtotal, $tax, 2);
        $total = bcsub($total, $discount, 2);
```

Reference    : CWE-1339 (Internal Precision Rounding Error), ASVS v4.0.3 - 11.1.1.
-----------------------------------------------------------------------

### FINDING-003
-----------------------------------------------------------------------
Severity     : LOW
Domain       : D-Infra
Category     : Missing Referential Integrity
Location     : `database/schema.sql` lines 384
-----------------------------------------------------------------------
Description  : The `op_invoices` table defines a `customer_id` column to reference customer records in `op_customers`, but lacks a corresponding foreign key constraint with an `ON DELETE SET NULL` rule. This contrasts with the `op_transactions` table, which correctly defines `fk_txn_customer`.

Evidence     : 
In `database/schema.sql` lines 380-401:
```sql
CREATE TABLE `op_invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `uuid` CHAR(36) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `invoice_number` VARCHAR(30) NOT NULL,
  `customer_id` BIGINT UNSIGNED DEFAULT NULL,
  ...
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  UNIQUE KEY `uk_token` (`token`),
  UNIQUE KEY `uk_merchant_number` (`merchant_id`, `invoice_number`),
  CONSTRAINT `fk_inv_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Attack Path  : 
There is no direct attack vector. However, deleting a customer record (e.g. via the GDPR customer scrubbing panel in the admin panel) leaves orphan values in `op_invoices.customer_id` pointing to non-existent customer records.

Impact       : Loss of database referential integrity, causing inconsistencies or application-level runtime failures when trying to query customer profiles for old invoices.

Fix          : Alter the `op_invoices` schema to include the foreign key constraint:
```sql
ALTER TABLE `op_invoices` 
ADD CONSTRAINT `fk_inv_customer` FOREIGN KEY (`customer_id`) REFERENCES `op_customers` (`id`) ON DELETE SET NULL;
```

Reference    : CWE-611, ASVS v4.0.3 - 13.2.1.
-----------------------------------------------------------------------

---

## Part 4 — Pass Log

The following security checks were executed against the codebase and passed successfully:

* **CK-A1-01 (Session Configuration):** Verified that `SessionMiddleware.php` uses HttpOnly, secure cookies, and strict session flags.
* **CK-A1-02 (API Key Generation):** Verified `ApiKeyRepository.php` uses cryptographically secure entropy (`bin2hex(random_bytes(32))`) and `BearerAuthMiddleware` compares hashes using timing-safe `hash_equals`.
* **CK-A1-03 (Brute Force Protection):** Verified `AuthController.php` and `Authenticator.php` integrate brute force lockouts tracking recent attempts in `LoginAttemptRepository`.
* **CK-A1-04 (MFA Replay Protection):** Verified `TwoFactorMiddleware.php` blocks replay attempts by storing and asserting the `totp_last_used_window`.
* **CK-A1-05 (Argon2id Passwords):** Verified `Authenticator::hashPassword` uses Argon2id with recommended parameters (`memory_cost=65536, time_cost=4`).
* **CK-A1-06 (JWT Claim Validation):** Verified companion pairing flow in `DeviceController.php` enforces strict signature and audience/issuer assertions.
* **CK-A2-01 (RBAC Security):** Verified `PermissionMiddleware.php` enforces strict role authorization constraints prior to route resolution.
* **CK-A2-02 (Tenant Scoping):** Verified `TenantScope.php` encapsulates repository queries using `merchant_id` filters, preventing cross-tenant access.
* **CK-A3-01 (SQL Injection):** Verified `Database.php` forces prepared statements. Explicit casting is applied to prevent limit/offset injections.
* **CK-A3-02 (Plugin Sandbox):** Verified `PluginLoader.php` compiles tokens recursively and blocks restricted functions (RCE/filesystem) and class instances.
* **CK-A3-03 (Unsafe Deserialization):** Verified `FileCache.php` and `RedisCache.php` disable object instantiation during unserialize using `['allowed_classes' => false]`.
* **CK-A4-01 (Webhook Signatures):** Verified `RequestSignatureMiddleware.php` compares HMAC headers using constant-time `hash_equals`.
* **CK-A4-02 (Crypto Verification):** Confirmed no weak algorithms (MD5/SHA1) are used for security purposes or passwords.
* **CK-A5-01 (XSS Protection):** Verified Twig auto-escaping is globally active and checkout styles use per-request CSP nonce parameters.
* **CK-A6-01 (CSRF Pools):** Verified `CsrfMiddleware.php` validates actions using a rolling pool of the 10 most recent session tokens.
* **CK-A7-01 (File Uploads):** Verified `FilesystemService.php` implements extension allowlisting, binary signature validation, and malicious SVG XML sanitization.
* **CK-A8-01 (SSRF Prevention):** Verified `HttpClient.php` validates destination webhooks against local/reserved CIDR blocklists in `UrlValidator`.
* **CK-A9-01 (Security Headers):** Verified `SecurityHeadersMiddleware.php` appends appropriate HSTS, CSP, and frames block headers.
* **CK-A9-02 (Error Suppressions):** Verified `Kernel.php` catches exceptions and presents generic pages in production.
* **CK-A10-01 (Dependencies Audit):** Ran `composer audit` verifying no critical package vulnerabilities in `composer.lock`.
* **CK-B1-01 (Double Capture):** Verified state check in `CheckoutController::pay` blocks concurrent double captures.
* **CK-B1-02 (Intent State Machine):** Verified payment intent state machine transitions are validated in `PaymentIntentRepository.php`.
* **CK-B2-02 (Positive Amounts):** Verified input check rejects zero or negative amounts in `CheckoutController.php` and `PaymentController.php`.
* **CK-B3-01 (Webhook Idempotency):** Verified webhook event IDs are saved in `UnifiedWebhookController` to prevent duplicate processing.
* **CK-B4-01 (Refund Limits):** Verified refunds enforce capture boundaries and track cumulative payouts in `RefundService.php`.
* **CK-B5-01 (Payout Double-Spend):** Verified payout balances are updated via atomic DB transaction blocks.
* **CK-B6-01 (Multi-Tenant Scoping):** Verified brand context is resolved from request scopes in `BrandContext.php`.
* **CK-B8-01 (Platform Fees):** Verified platform fees are computed server-side via rules in `FeeRuleRepository.php`.
* **CK-B9-01 (Rate Limiters):** Verified rate limiting is enforced per endpoint in `RateLimiterMiddleware.php`.
* **CK-B10-01 (Log Integrity):** Verified audit logs are append-only; `AuditLogRepository` lacks edit or delete wrappers.
* **CK-C1-01 (PHP 8.2 Compatibility):** Confirmed strict typings, no dynamic property warnings, and passing static analysis.
* **CK-C2-01 (Error Suppression):** Verified exceptions are caught and logged without generic `@` suppressions.
* **CK-C3-01 (Sanitizer Allowlist):** Verified `InputSanitizer.php` filters variables against strict formatting filters.
* **CK-C4-01 (Transactions):** Verified database transaction blocks surround multi-step journal updates.
* **CK-C5-01 (Secrets Separation):** Verified secrets are parsed from environment variables, keeping example configs clean.
* **CK-C6-01 (PII in Logs):** Verified logger masks credit details and sensitive fields.
* **CK-C7-01 (Debug Code):** Checked for leftover debug dumps; no active instances found.
* **CK-C8-01 (Separation of Concerns):** Verified fat logic is successfully decoupled into service layers (e.g. `InvoiceService`, `RefundService`).
* **CK-D1-01 (Infrastructure Blocks):** Verified `.htaccess` and `nginx.conf.example` explicitly block config, .git, and upload file code execution.
* **CK-E1-01 (Card Data Storage):** Verified database schema is completely free of RAW PAN or CVV fields, maintaining PCI compatibility.
* **CK-E2-01 (GDPR Deletion):** Verified customer delete queries set associated tables to `NULL` to erase PII while keeping statistics balanced.
* **CK-E3-01 (Licenses):** Verified dependencies licenses (MIT, BSD-3-Clause) are compatible with AGPL-3.0.

---

## Part 5 — Remediation Roadmap

### Prioritized Remediation Timeline
| Priority | Finding ID | Severity | Action Required | Estimated Effort | Fix Dependency |
|---|---|---|---|---|---|
| **Immediate** | FINDING-001 | **HIGH** | Check merchant active status in API keys and domain middleware | 1 to 4 hours | None |
| **Short-term** | FINDING-002 | **MEDIUM** | Use BCMath for calculations in `InvoiceService.php` | 1 to 4 hours | None |
| **Backlog** | FINDING-003 | **LOW** | Add foreign key constraint to `op_invoices.customer_id` | under 1 hour | None |

---

## Part 6 — Post-Fix Hardening Checklist

- [ ] 1. Verify that invoking `/api/v1/payments/initiate` with a valid API key of a **suspended** merchant returns a `403 Forbidden` response.
- [ ] 2. Verify that accessing a white-labeled custom domain of a **suspended** merchant returns a `404 Not Found` response.
- [ ] 3. Verify that opening a checkout session of a **suspended** merchant returns an `expired` or error page, blocking payments.
- [ ] 4. Audit `InvoiceService::create()` with line items of `0.33` unit price and `3` quantity, ensuring the total is computed as exactly `0.99` and not containing trailing float representations in the database.
- [ ] 5. Run database migrations to alter `op_invoices` and assert that the `fk_inv_customer` foreign key is registered.
- [ ] 6. Delete a customer profile in the admin interface and verify that both `op_transactions` and `op_invoices` retain their records but set `customer_id` to `NULL`.
- [ ] 7. Execute `composer test` and verify that all unit and integration tests compile and pass without warnings.
- [ ] 8. Execute `vendor/bin/phpstan analyse` to verify zero type-safety errors.
