# Findings & Decisions

## Requirements
- Audit the entire codebase thoroughly without skips or guesses.
- Spot and list all route configuration vs. controller docblock mismatches.
- Verify double-entry ledger bookkeeping, prepared statement usage, CSP security nonces, input sanitization rules.
- Run static type-checking and automated integration test suites.

## Research Findings

### 1. API Route vs. Controller Docblock Mismatches (Verified Fact-by-Fact)
* **`DeviceController.php` (Mobile device pairing & lifecycle)**:
  * *`pair()` method*: Docblock (Line 57) says `POST /api/mobile/v1/devices/pair`. Reality in `api.php` routing: `POST /api/mobile/v1/devices`.
  * *`refresh()` method*: Docblock (Line 195) says `POST /api/mobile/v1/devices/refresh`. Reality in `api.php` routing: `POST /api/mobile/v1/devices/token-refreshes`.
  * *`status()` method*: Docblock (Line 244) says `GET /api/mobile/v1/devices/status`. Reality in `api.php` routing: `GET /api/mobile/v1/devices/statuses`.
* **`SmsController.php` (Mobile companion app SMS receiver & queuing)**:
  * *`receive()` method*: Docblock (Line 64) says `POST /api/mobile/v1/sms/receive`. Reality in `api.php` routing: `POST /api/mobile/v1/sms`.
  * *`queue()` method*: Docblock (Line 158) says `GET /api/mobile/v1/sms/queue`. Reality in `api.php` routing: `GET /api/mobile/v1/sms/queues`.

### 2. Twig Omission &ettings Verification
* **`TwigExtensions.php` settings `@todo` marker**:
  * Docblock at line 242 states a `@todo` to integrate setting loading with `SettingsRepository` in future phases.
  * In reality, the function `setting(key, default)` is fully implemented, retrieving brand-scoped settings dynamically via the resolved brand context and settings repositories. The `@todo` block is a benign docblock typo.

### 3. Double-Entry Ledger Bookkeeping Hardening
* **`LedgerService.php` concurrency locking**:
  * Implements double-entry ledger postings inside database transaction blocks (`db->transaction()`).
  * Concurrency issues (race conditions, double posting) are neutralized by executing row-locking `SELECT id FROM op_ledger_transactions ... FOR UPDATE` before executing updates.
  * Decimal accuracy uses strict BCMath operations (`bcmul`, `bcadd`, `bcsub`, `bccomp`) scaled to 4 decimals, fully adhering to standard GAAP.

### 4. Input Sanitization & Dynamic Dispatch Security
* **`InputSanitizer.php` safe method dynamic dispatch allowlist**:
  * Crucial OWASP mitigation: the static recursive method `InputSanitizer::array(array $input, string $method)` validates the requested filter `$method` against an explicit allowlist `['string', 'html', 'email', 'url', 'phone', 'slug', 'attr', 'trim']`.
  * This prevents administrative/public API inputs from triggering arbitrary static callables or code execution via dynamic dispatch exploits.

### 5. Two-Factor Authentication (2FA) Setup Decryption Safety
* **`TwoFactorSetupController.php` setup QR-Code generation**:
  * Resolved critical design mistake where the AES-256-GCM encrypted column `totp_secret_enc` was passed raw to verification and QR generation methods.
  * Setup controller correctly invokes `$this->userRepo->getTotpSecret($userId)` which decrypts the key on-the-fly and returns raw base32 characters to compute a valid `otpauth://` QR URI.

### 6. Audit & Web Server Directory Hardening
* **Mass Assignment prevention**:
  * `MerchantRepository::updateBrand()` avoids binding raw array variables dynamically into database fields. It binds explicit parameter values strictly in a parameterized SQL update, defending against dynamic model field hijacking.
* **IP Spoofing & Proxy safety**:
  * `Request::ip()` resolves client IP addresses safely by only trusting `X-Forwarded-For` and `X-Real-IP` proxies if the proxy server IP is defined in the `TRUSTED_PROXIES` environment whitelist.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Fresh Audit Plan | Isolates comprehensive deep-dive observations for precise documentation. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [api.php](file:///c:/laragon/www/ownpay/config/routes/api.php)
- [DeviceController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/DeviceController.php)
- [SmsController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/SmsController.php)
- [TwigExtensions.php](file:///c:/laragon/www/ownpay/src/View/TwigExtensions.php)
- [LedgerService.php](file:///c:/laragon/www/ownpay/src/Service/Payment/LedgerService.php)
- [InputSanitizer.php](file:///c:/laragon/www/ownpay/src/Service/System/InputSanitizer.php)
- [TwoFactorSetupController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/TwoFactorSetupController.php)
- [Request.php](file:///c:/laragon/www/ownpay/src/Http/Request.php)
