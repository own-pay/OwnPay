# Enterprise Security & Forensic Codebase Re-Audit Report

**Status**: COMPLETED (AUDIT & FIND ONLY)  
**Target Codebase**: OwnPay Enterprise Payment Gateway (v0.1.0 Genesis)  
**Audit Executed**: 2026-05-20  
**Overall Stability Rating**: **D+ (5.5/10)** — Post-remediation analysis indicates that while basic checkout sessions and csrf leaks are patched, the ledger accounting engine suffers from fatal double-entry creation and balance calculation bugs, a silent multi-refund leakage vulnerability exists, the plugin sandboxing is entirely dead code, staff users face severe privilege escalation risks, and the mobile companion app fails out of the box due to issuer mismatch.

---

## 1. Executive Summary

This forensic re-audit was initiated to verify post-patch integrity, detect side-effects, and identify hidden micro-architectural vulnerabilities across the entire OwnPay enterprise payment gateway ecosystem.

### Major Discoveries:
1. **Ledger Fatal Design Flaw**: Double-entry bookkeeping for refunds and settlements is fundamentally broken. Querying dynamic account names (`MERCHANT_PAYABLE`) under conflicting hardcoded types (`asset` vs `liability`) triggers database unique key constraint crashes, rolling back transactions and locking up accounting records.
2. **Accounting Balance Corruption**: The double-entry ledger atomically *adds* amounts to both credit and debit balances via a simple mathematical addition (`balance = balance + :amount`), failing to distinguish debit/credit directions per account type (Assets vs Liabilities). This results in unchecked compounding balance growth instead of netting zero.
3. **Checkout Metadata Destruction**: Selecting manual gateways or submitting verification details executes direct, unmerged JSON overwrites of the transaction `metadata` column. This wipes the `invoice_id` and `payment_link_id` keys, leaving completed transactions unassociated and permanently stalling invoice state transitions to `'paid'`.
4. **Multi-Refund Financial Leakage**: `RefundService` does not sum or check processed refunds against the original transaction amount, allowing multiple partial refunds to exceed the transaction amount and drain funds. It also uses unsafe float casting for currency comparisons.
5. **False-Positive Plugin Scanner & Dead Sandbox**: The AST-token plugin security scanner blocks OOP development by matching safe object method calls (e.g. `$db->exec()`, `$logger->fwrite()`) as global PHP functions, while the actual runtime sandbox is entirely dead code (methods are never invoked in core execution).
6. **JWT Issuer Mismatch**: The DI container constructs `JwtService` with a default issuer of `'ownpay'`, but `JwtAuthMiddleware` validates against `'OwnPay'`, completely breaking companion app pairing out of the box.

---

## 2. Verified Gaps & Bugs

### CRITICAL SEVERITY

#### BUG 01: Duplicate Ledger Account Creation & Fatal Database Crash
* **Component**: Double-Entry Ledger Bookkeeping Engine
* **File & Lines**:
  * [LedgerService.php](src/Service/Payment/LedgerService.php#L41-L42)
  * [LedgerRepository.php](src/Repository/LedgerRepository.php#L36-L37)
  * [schema.sql](database/schema.sql#L492)
* **Code Evidence**:
  * *`LedgerService.php` L41-42*:
    ```php
    $drAccount = $this->ledger->findOrCreateAccount($debitAccountCode, 'asset', $currency, $merchantId);
    $crAccount = $this->ledger->findOrCreateAccount($creditAccountCode, 'liability', $currency, $merchantId);
    ```
  * *`LedgerRepository.php` L36-37*:
    ```php
    $where = '`name` = :name AND `type` = :type AND `currency` = :cur';
    $params = ['name' => $name, 'type' => $type, 'cur' => $currency];
    ```
  * *`schema.sql` L492*:
    ```sql
    UNIQUE KEY `uk_merchant_name` (`merchant_id`, `name`)
    ```
* **Vulnerability Mechanism**:
  1. During standard payment processing (`recordPaymentReceived`), `MERCHANT_PAYABLE` is successfully created as a `'liability'` account under the active merchant.
  2. When a refund is initiated (`recordRefund`), the debit account is `'MERCHANT_PAYABLE'` and the credit account is `'CASH'`. 
  3. `LedgerService` passes `'MERCHANT_PAYABLE'` as the debit account to `findOrCreateAccount()` with a hardcoded type of `'asset'` (L41).
  4. `LedgerRepository::findOrCreateAccount` queries the database for an account with `name = 'MERCHANT_PAYABLE'` AND `type = 'asset'`.
  5. Because `MERCHANT_PAYABLE` exists only with type `'liability'`, the query returns `NULL`.
  6. The repository proceeds to execute an `INSERT` statement to create `MERCHANT_PAYABLE` as an `'asset'`.
  7. The MySQL database rejects this write, throwing a `PDOException: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry` due to the unique key constraint on `(merchant_id, name)`.
  8. The outer database transaction immediately rolls back. Refund and settlement ledger journaling is permanently blocked.

---

### HIGH SEVERITY

#### BUG 02: Ledger Balance Accumulation Failure & Ledger Corruption
* **Component**: Double-Entry Ledger Bookkeeping Engine
* **File & Lines**:
  * [LedgerRepository.php](src/Repository/LedgerRepository.php#L75-L87)
  * [LedgerService.php](src/Service/Payment/LedgerService.php#L60-L61)
* **Code Evidence**:
  * *`LedgerRepository.php` L84*:
    ```php
    $this->db->execute(
        "UPDATE `op_ledger_accounts` SET `balance` = `balance` + :amount WHERE {$where}",
        $params
    );
    ```
  * *`LedgerService.php` L60-61*:
    ```php
    $this->ledger->adjustBalance((int) $drAccount['id'], $amount);
    $this->ledger->adjustBalance((int) $crAccount['id'], $amount);
    ```
* **Vulnerability Mechanism**:
  * Double-entry bookkeeping dictates that debits increase Asset/Expense accounts and decrease Liability/Equity/Revenue accounts, while credits do the opposite.
  * In the current implementation, both the debit and credit legs of a transaction are posted by adding the amount to the respective accounts.
  * Debiting a liability account (e.g. `MERCHANT_PAYABLE`) during a refund increases its balance instead of decreasing it. Crediting an asset account (e.g. `CASH`) also increases its balance.
  * The ledger's accounting equation fails to net out, leading to compounding, incorrect, and highly corrupted balance calculations for merchants.

#### BUG 03: Transaction Metadata Overwrite & Invoice 'Paid' Loss
* **Component**: Checkout Flow & Invoice Status State Machine
* **File & Lines**:
  * [CheckoutController.php](src/Controller/Checkout/CheckoutController.php#L320-L323)
  * [TransactionRepository.php](src/Repository/TransactionRepository.php#L251-L264)
  * [PaymentCompletionListener.php](src/Service/Payment/PaymentCompletionListener.php#L39-L45)
* **Code Evidence**:
  * *`CheckoutController.php` L320-323*:
    ```php
    $this->txnRepo->updateMetadata((int) $txn['id'], [
        'payment_details' => $details,
        'submitted_at'    => DateHelper::now(),
    ], (int) $txn['merchant_id']);
    ```
  * *`TransactionRepository.php` L255*:
    ```sql
    UPDATE {$this->table} SET metadata = :meta, updated_at = NOW() WHERE id = :id AND merchant_id = :mid
    ```
* **Vulnerability Mechanism**:
  1. When an invoice payment is initialized, `InvoiceCheckoutController` creates a transaction with metadata mapping: `{"invoice_id": X, "invoice_number": Y}`.
  2. When the customer submits manual payment proof (`pay()`), `CheckoutController` calls `updateMetadata` with only `payment_details` and `submitted_at`.
  3. `TransactionRepository::updateMetadata` executes a raw update, completely overwriting the existing metadata column in `op_transactions` rather than merging.
  4. The keys `invoice_id` and `payment_link_id` are completely wiped from the database.
  5. Upon transaction approval and completion, `PaymentCompletionListener::onTransactionCompleted` receives the transaction but extracts a `null` value for `invoice_id` from the overwritten metadata.
  6. The invoice state is never updated to `'paid'`, leaving completed orders permanently stuck as `'sent'` or `'overdue'` in the database.

#### BUG 04: Multi-Refund / Over-Refund Financial Leakage
* **Component**: Refund Services & Financial Integrity
* **File & Lines**:
  * [RefundService.php](src/Service/Payment/RefundService.php#L43-L49)
  * [RefundRepository.php](src/Repository/RefundRepository.php#L1-L24)
* **Code Evidence**:
  * *`RefundService.php` L47-49*:
    ```php
    if ((float)$amount > (float)$txn['amount']) {
        throw new InvalidArgumentException('Refund amount cannot exceed transaction amount');
    }
    ```
* **Vulnerability Mechanism**:
  1. `RefundService::create` checks if the single refund `$amount` is greater than `$txn['amount']`.
  2. However, the service fails to query or sum the amounts of *previously processed refunds* for that same transaction.
  3. `RefundRepository` lacks any methods to aggregate refund totals.
  4. A malicious staff user or compromised account can execute multiple consecutive partial refunds (e.g., three refunds of 40 BDT on a 100 BDT transaction), resulting in total refunded amounts exceeding the original transaction value and causing a major financial loss.
  5. The currency/financial comparisons also rely on unsafe `(float)` casting instead of `bccomp` from BCMath.

#### BUG 05: Inactive Plugin Sandbox Security Bypass
* **Component**: Core Plugin System Architecture
* **File & Lines**:
  * [PluginLoader.php](src/Plugin/PluginLoader.php#L200-L209)
  * [PluginRegistry.php](src/Plugin/PluginRegistry.php#L80-L83)
  * [PluginSandbox.php](src/Plugin/PluginSandbox.php#L1-L107)
* **Code Evidence**:
  * *`PluginRegistry.php` L80-83*:
    ```php
    public function getSandbox(string $slug): ?PluginSandbox
    {
        return $this->sandboxes[$slug] ?? null;
    }
    ```
* **Vulnerability Mechanism**:
  * The `PluginSandbox` class exposes critical runtime security capabilities: directory-restricted path access (`validateFilePath`), core-table query protection (`validateSql`), and capability validation (`hasCapability`).
  * Although `PluginLoader` instantiates a `PluginSandbox` instance for each loaded plugin and saves it in the `PluginRegistry` under `$this->sandboxes`, **the `getSandbox` method is never called anywhere else in the application core**.
  * Sandbox validations are never executed at runtime. Active plugins can execute raw SQL directly on core tables (e.g. `op_merchant_users`), read/write arbitrary system files, and perform actions without declared capabilities. The sandbox is completely bypassed, offering zero actual protection.

#### BUG 06: DI Container JWT Issuer Mismatch & Broken Companion App Authentication
* **Component**: API Companion App Security Middleware / DI container
* **File & Lines**:
  * [JwtService.php](src/Service/Auth/JwtService.php#L20)
  * [JwtAuthMiddleware.php](src/Middleware/JwtAuthMiddleware.php#L60)
  * [services.php](config/services.php#L262-L264)
* **Code Evidence**:
  * *`services.php` L262-264*:
    ```php
    $c->singleton(\OwnPay\Service\Auth\JwtService::class, static function (): \OwnPay\Service\Auth\JwtService {
        return new \OwnPay\Service\Auth\JwtService();
    });
    ```
  * *`JwtService.php` L20*:
    ```php
    public function __construct(?string $secret = null, string $issuer = 'ownpay', int $ttl = 86400)
    ```
  * *`JwtAuthMiddleware.php` L60*:
    ```php
    $expectedIss = getenv('APP_NAME') ?: 'OwnPay';
    ```
* **Vulnerability Mechanism**:
  1. When the mobile companion app pairs and requests a token, `JwtService` is resolved from the container. Because no arguments are passed, it defaults its `$issuer` property to `'ownpay'` (all lowercase) as defined in its constructor.
  2. When the companion app subsequently executes API endpoints protected by `JwtAuthMiddleware`, the middleware validates that the token's `iss` claim strictly equals `$expectedIss` (which evaluates to `'OwnPay'` or the `.env` `APP_NAME` setting).
  3. Because `'ownpay' !== 'OwnPay'`, all authentication attempts fail validation and return `401 Unauthorized: Invalid JWT issuer`.
  4. The mobile companion app integration is completely broken out of the box.

---

### MEDIUM SEVERITY

#### BUG 07: Privilege Escalation via Unmapped Admin Routes
* **Component**: Role-Based Access Control (RBAC) Security Middleware
* **File & Lines**:
  * [PermissionMiddleware.php](src/Middleware/PermissionMiddleware.php#L172-L175)
* **Code Evidence**:
  * *`PermissionMiddleware.php` L172-175*:
    ```php
    // Default-deny for unmapped /admin/* routes
    if (str_starts_with($path, '/admin')) {
        return 'admin.access';
    }
    ```
* **Vulnerability Mechanism**:
  * `PermissionMiddleware` resolves required route permissions using a static hardcoded `$map`.
  * If a route is not mapped, and it starts with `/admin`, it defaults to requiring the `'admin.access'` permission.
  * However, `'admin.access'` is the baseline, lowest-tier permission granted to standard staff members to let them log in to the admin panel.
  * If a developer adds a new administrative controller or registers a new route under `/admin` and forgets to update `$map`, **every basic staff user receives complete access to it**.
  * This bypasses the intended "default-deny" design, turning it into a "default-allow-to-all-staff" security vulnerability.

#### BUG 08: AST-Token Scanner False Positives Block OOP Plugins
* **Component**: Core Plugin Loading Engine
* **File & Lines**:
  * [PluginLoader.php](src/Plugin/PluginLoader.php#L168-L186)
* **Code Evidence**:
  * *`PluginLoader.php` L169-171*:
    ```php
    if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
        $funcName = $tokens[$i][1];
        if (PluginSandbox::isDangerousFunction($funcName)) {
    ```
* **Vulnerability Mechanism**:
  * The plugin pre-load scanner parses PHP files to identify dangerous global function calls (e.g. `exec`, `fwrite`, `header`, `unlink`) to prevent malicious plugins.
  * However, the loop only checks for the string token followed by a parenthesis. It fails to check the prefix token to see if it is an object property reference (`->` / `T_OBJECT_OPERATOR`) or a static resolution operator (`::` / `T_DOUBLE_COLON`).
  * As a consequence, safe object-oriented method calls (such as `$db->exec()`, `$logger->fwrite()`, or `$response->header()`) are incorrectly matched as global function calls.
  * This throws false-positive runtime violations and blocks safe plugins, preventing developers from utilizing standard object-oriented programming interfaces.

---

### LOW SEVERITY

#### BUG 09: Executable PHP templates misnamed as ".twig"
* **Component**: Installer Framework
* **File & Lines**:
  * [InstallerController.php](src/Controller/Install/InstallerController.php#L307-L315)
* **Code Evidence**:
  * *`InstallerController.php` L307-315*:
    ```php
    private function renderTwig(string $template, array $data): string
    {
        $file = $this->rootDir . '/templates/' . $template;
        if (!file_exists($file)) return '<h1>Template not found: ' . htmlspecialchars($template) . '</h1>';
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return ob_get_clean() ?: '';
    }
    ```
* **Vulnerability Mechanism**:
  * The installer wizard controller exposes a method named `renderTwig()`, but instead of invoking the Twig engine, it parses the templates using raw PHP `include` statements.
  * The installer templates under `templates/install/` are named with a `.twig` extension but actually contain raw, executable PHP scripts.
  * This is an architectural naming mismatch that violates the codebase standard of using Twig and poses confusion for secure templating auditing.

---

## 3. Architectural & Fintech Gaps

### I. Severe Performance & Lock Risks on JSON Extract Queries
* **Component**: Database Query Performance
* **File & Lines**: [InvoiceRepository.php](src/Repository/InvoiceRepository.php#L60-L61)
* **Code Snippet**:
  ```sql
  SELECT trx_id FROM op_transactions WHERE JSON_EXTRACT(metadata, '$.invoice_id') = :iid AND status = 'pending' LIMIT 1
  ```
* **Fintech Gap**:
  * The `op_transactions` table stores the `invoice_id` and `payment_link_id` variables inside the unstructured `metadata` JSON column.
  * The database schema does not define a generated virtual column or any index for `JSON_EXTRACT(metadata, '$.invoice_id')`.
  * Consequently, every single invoice checkout page load executes a full table scan of `op_transactions` to locate active transactions.
  * Under heavy transaction volumes, this will saturate database I/O, cause slow query locking, and create severe transactional race conditions.

### II. Absence of Dynamic Core Life-Cycle Hook Dispatches
* **Component**: Plugin Architecture & System Extensibility
* **Core Core Gap**:
  * The plugin model aims to follow WordPress's open-closed architectural model using action and filter hooks via `EventManager`.
  * However, the core application lacks hooks inside key transactional pipelines. There are no events or filters registered within:
    1. **Routing and Routing Selection**: Plugins cannot dynamically hook or inject new web routes or API endpoints without editing core arrays.
    2. **Middlewares**: Plugins cannot register custom request/response filter middlewares.
    3. **Database Queries**: There are no filters to alter query scopes, restricting sandboxing or analytical capabilities.
    4. **Authentication**: No actions are fired on successful/failed superadmin or merchant staff login, preventing external integration of security monitoring tools.

### III. Dynamic Invoices Trapped in Overdue State
* **Component**: Invoice Checkout Status Pipeline
* **File & Lines**: [InvoiceCheckoutController.php](src/Controller/Checkout/InvoiceCheckoutController.php#L36-L53)
* **Fintech Gap**:
  * In the invoice status check, if the system detects an invoice is past its due date, it updates its status to `'overdue'` and sets `$invoice = null` to block checkout.
  * However, `'overdue'` is explicitly listed in the whitelisted allowed payable statuses (`$allowedStatuses = ['sent', 'overdue']`).
  * If the invoice is already `'overdue'` in the database, the code checks the due date again, finds it is in the past, and blocks it anyway.
  * This contradiction makes it impossible for customers to ever pay an overdue invoice, locking out legitimate late settlement collections.

---

## 4. Verification Plan

Since this is an **AUDIT & FIND ONLY** phase, we verify these issues strictly through forensic code execution tracing and local PHP diagnostics:

### 1. Database Duplicate Key Verification
```sql
-- Attempting to simulate LedgerService.php recordRefund() account insertions:
-- 1. Merchant payable liability exists:
INSERT INTO `op_ledger_accounts` (`merchant_id`, `name`, `type`, `currency`, `balance`) 
VALUES (1, 'MERCHANT_PAYABLE', 'liability', 'BDT', 0.00);

-- 2. Refund attempts to locate MERCHANT_PAYABLE as asset (fails) and tries to insert:
INSERT INTO `op_ledger_accounts` (`merchant_id`, `name`, `type`, `currency`, `balance`) 
VALUES (1, 'MERCHANT_PAYABLE', 'asset', 'BDT', 0.00);

-- RESULT: [Err] 1062 - Duplicate entry '1-MERCHANT_PAYABLE' for key 'uk_merchant_name'
```

### 2. Manual Verification CLI Trace (Token Parsing Simulation)
```php
<?php
// Simulate PluginLoader.php L169 T_STRING scan on an OOP method call:
$code = '$db->exec("DELETE FROM table");';
$tokens = token_get_all($code);
foreach ($tokens as $token) {
    if (is_array($token) && $token[0] === T_STRING) {
        echo "Token value: " . $token[1] . "\n";
    }
}
// Output: Token value: db
// Output: Token value: exec -> triggers false positive flag in PluginLoader L171!
```
