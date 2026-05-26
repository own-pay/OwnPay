# Audit Findings: OwnPay Deep Codebase Audit

## 1. Project Reconnaissance & Mapping

### 1.1 Project Structure & Type
- **Type:** White-label, single-owner, multi-brand payment gateway platform (Monolith).
- **Core Languages:** 
  - Backend: PHP 8.3 (active runtime in tests), PHP 8.2+ target
  - Frontend: Vanilla JS, Twig template engine (v3.14), CSS
- **Database Layer:** Custom PDO-based repositories extending a common `BaseRepository`. No heavy ORM like Doctrine or Eloquent; uses raw prepared queries under parameter binding. Enforces `TenantScope` for brand/merchant isolation.
- **Entry Points:**
  - Public Web Root: `public/index.php` (Single Front Controller pattern using a PSR-11 `Container` and a custom `Router`).
  - Installer: `/install/` routes handled by `InstallerController`.

### 1.2 Configuration & Manifest Files
- **Env Templates:** `.env`, `.env.example`
- **Application Configs:**
  - `config/app.php`
  - `config/database.php`
  - `config/middleware.php`
  - `config/routes/web.php`
  - `config/services.php`
- **Dependency Manifests:**
  - `composer.json` / `composer.lock`
  - `package.json` / `package-lock.json`
- **Linting & Code Quality Configs:**
  - `phpstan.neon` (Strict Level 9 analysis targets)
  - `eslint.config.js`
  - `stylelint.config.js`
  - `.twig-cs-fixer.php`

### 1.3 HTTP Entry Points (Routes & Controllers)
- Main routes are declared in `config/routes/web.php`.
- The PSR-11 custom Router parses routes and dispatches them to appropriate controllers located in:
  - `src/Controller/Admin/` (Backoffice brand management, transactions, gateways, people, SMS/mobile)
  - `src/Controller/Checkout/` (Invoice/Payment checkout interface for clients)
  - `src/Controller/Install/` (Installation flow)
  - `src/Controller/Api/` (Gateway integration endpoints, device pairing, webhook notifications)

---

## 2. Dependency Audit & Package Vulnerabilities
- Checked backend dependencies using `composer audit --format=json`. Zero vulnerabilities or abandoned packages reported.
- Frontend includes only development linting tools (`eslint`, `stylelint`), meaning zero runtime NPM dependencies exist, minimizing vulnerability surfaces on the client side.

---

## 3. Environment & Secrets Check
- Audited `.env.example`, `.env` (ignored from version control).
- Zero hardcoded environment secrets or keys discovered in version control.
- `config/database.php` uses raw prepared statement execution (`\PDO::ATTR_EMULATE_PREPARES => false`), completely protecting SQL query strings at the server level.

---

## 4. Deep Code Audit Findings

### 4.1 Timing Side-Channel in Cron Token Comparison
- **File:** `src/Controller/Page/CronController.php` : Line 59
- **Finding:** standard string comparisons (`!==` and `==`) are used to check the incoming `secret` parameter against the expected `CRON_SECRET`. Standard PHP string operators terminate comparison early upon encountering the first mismatch, leaking timing information byte-by-byte.

### 4.2 TwigExtensions `setting()` Function Stub
- **File:** `src/View/TwigExtensions.php` : Line 248
- **Finding:** Twig function `setting()` is stubbed out and returns the default parameter value without querying `SettingsRepository` or `SettingsService`. Any template calling `{{ setting(...) }}` will retrieve fallback values.

### 4.3 InvoiceService `generatePdf()` Function Stub
- **File:** `src/Service/Payment/InvoiceService.php` : Line 283
- **Finding:** The `generatePdf` method in `InvoiceService` is a stub returning a JSON string representation of the invoice data rather than generating or rendering a proper PDF document stream.

### 4.4 Unscoped Query in PairedDeviceRepository findByUuid
- **File:** `src/Repository/PairedDeviceRepository.php` : Line 42
- **Finding:** `findByUuid` executes a raw query on `op_paired_devices` without specifying `merchant_id` context or utilizing the `TenantScope` filters. Bypasses brand context isolation guidelines.

### 4.5 Financial Ledger Silent Balance Desynchronization on Multiple Partial Refunds
- **File:** `src/Service/Payment/LedgerService.php` : Line 213 & `src/Service/Payment/RefundService.php` : Line 150
- **Finding:** When partial refunds are posted, `RefundService` passes the transaction's primary key (`$txnId`) as the `reference_id` to `LedgerService::recordRefund` instead of the unique refund entry's primary key. Because `postEntries` executes a `FOR UPDATE` duplicate check on `reference_type = 'transaction'` and the same `$transactionId`, the second and subsequent partial refunds are silently ignored by the ledger system, leaving the merchant payable accounts desynchronized and resulting in massive silent financial leakage.

### 4.6 Swallowed Exception in Manual Gateway Currency Conversion
- **File:** `src/Controller/Checkout/PaymentIntentCheckoutController.php` : Line 328
- **Finding:** If an invoice is billed in a non-BDT currency and a manual gateway is used, the conversion to BDT swallows exceptions in an empty `catch (\Throwable $e) {}` block, preventing diagnostic logging of exchange rate/currency API failures.

