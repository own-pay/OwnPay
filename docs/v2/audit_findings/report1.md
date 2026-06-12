# OwnPay Non-Security Structural & Logic Audit Report

**Date:** 2026-06-12
**Scope:** Structural Invariants, Business Logic Correctness, Financial Double-Entry Integrity, Architecture, and Performance Constraints.
**Exclusions:** Adversarial vulnerability scanning, exploit testing, web security vulnerabilities (SQLi, IDOR, SSRF, XSS, etc.) were excluded by user instruction.

---

## 1. Structural Invariant Verification

**INV-1: Single Super-Administrator (Business Model)**
* **Status:** **VIOLATED (CRITICAL)**
* **File:** `database/schema.sql` (Line 64)
* **Finding:** The `op_merchant_users` table is the only users table in the system and defines `merchant_id` as `BIGINT UNSIGNED NOT NULL`. Consequently, a user marked with `is_superadmin = 1` is still bound to a specific merchant. This violates the invariant that "One root owner controls the entire installation... No multi-tenant SaaS layer." If superadmins are bound to merchants, the system cannot function cleanly as a single-owner instance without cross-contamination or locking the admin out if the specific merchant is deleted or suspended.

**INV-2: Brand = Merchant (Data Scoping)**
* **Status:** **PASS**
* **File:** `src/Repository/CustomerRepository.php`
* **Finding:** All checked brand-owned repositories (like `CustomerRepository`) correctly implement the `use TenantScope;` trait. Bypasses only occur deliberately for dashboard analytics (`countForDashboard`) when superadmins are generating system-wide overviews, which aligns with the design intent.

**INV-5: Ledger is Double-Entry (Financial Logic)**
* **Status:** **PASS**
* **File:** `src/Service/Payment/LedgerService.php` (Line 101)
* **Finding:** The ledger correctly enforces GAAP double-entry balances prior to database insertion. A strict invariant check (`bccomp($totalDebit, $totalCredit, 4) !== 0`) prevents unbalanced journal entries from being stored, and the insertion is wrapped in a database transaction block (`$db->transaction`) to prevent partial data writes.

**INV-6: Schema Prefix Discipline (Data Architecture)**
* **Status:** **PASS**
* **File:** `database/schema.sql`
* **Finding:** Manual inspection of all 48 tables confirms strict adherence to the `op_` prefix constraint. The database is clean from legacy SQLite or non-prefixed tables.

---

## 2. Quest Findings (Non-Security)

### Quest 2: High-Volume Concurrency & Ledger Integrity
* **Finding 2.1:** **Double-Entry Constraint Enforced**
  * **File:** `src/Service/Payment/LedgerService.php`
  * **Details:** Any attempt to process a transaction where the debits do not match the credits throws an `InvalidArgumentException` and halts the write entirely.
* **Finding 2.2:** **Data Integrity Locks**
  * **File:** `src/Service/Payment/LedgerService.php`
  * **Details:** To prevent duplicate event processing (like multiple identical webhooks), the service utilizes a `SELECT ... FOR UPDATE` row lock mechanism via `op_ledger_transactions`, ensuring atomic, race-condition-free financial data recording.

### Quest 4: SMS Ingestion & Parsing Engine
* **Finding 4.1:** **Regex Out-of-Bounds Protection**
  * **File:** `src/Service/Sms/SmsRegexParser.php`
  * **Details:** When the system uses template-based matching for SMS body text, missing or poorly formed regex capture groups fall back cleanly using the null coalescing operator (`$amountMatches[1] ?? null`). This prevents array out-of-bounds fatals. Note: If the SMS template is poorly written by an admin, it may silently fail to parse (generating a 'rejected' status), but the application state remains stable.

### Quest 5: Checkout, Invoice & Frontend Flows
* **Finding 5.1:** **Dynamic Invoice Calculation Correctness**
  * **File:** `src/Service/Payment/InvoiceService.php` (Lines 216-235)
  * **Details:** The invoice modification flow correctly implements the `developer-workflows.md` requirement. Instead of accepting front-end subtotal injections, the backend iterates over the `items` payload, multiplies quantity by unit price using high-precision `bcmul` and `bcadd`, and recalculates the gross total. Old `op_invoice_items` are fully purged before re-insertion, preventing ghost item retention.

### Quest 8: Installer Wizard Bootstrap Logic
* **Finding 8.1:** **Safe `.env` Parsing**
  * **File:** `src/Controller/Install/InstallerController.php` (Line 548)
  * **Details:** The installer strictly avoids `parse_ini_file()`, complying with `developer-workflows.md`. Instead, it reads `.env.example` line-by-line and uses an application-level regex parser `preg_match('/^([A-Z0-9_]+)\s*=\s*(.*)$/', ...)` to perform safe token substitution, correctly handling base64 `=` characters within `APP_KEY` or `ENCRYPTION_KEY` limits.

### Quest 9: Ultra-Low-Resource & Shared-Hosting Compatibility
* **Finding 9.1:** **Memory Efficient Pagination**
  * **File:** `src/Repository/InvoiceRepository.php` (and others)
  * **Details:** The repositories use direct `LIMIT :lim OFFSET :off` SQL constraints. No bulk object hydration or `fetchAll()` without constraints is occurring on large transactional tables. Memory cost remains O(1) relative to database scale.

---

## 3. Executive Summary
The business logic and fundamental data architectures of OwnPay are sound. Ledger mechanics are robustly locked and strictly adherent to standard double-entry accounting rules with high-precision math strings. However, a structural flaw in the RBAC/User schema (`op_merchant_users`) compromises the "Single Super-Administrator" invariant. Correcting `merchant_id` to be nullable or separating superadmins into a distinct global table is required prior to release to align with the core business model constraint.
