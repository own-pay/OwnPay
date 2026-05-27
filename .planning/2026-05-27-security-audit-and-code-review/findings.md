# Findings & Decisions

## Requirements
- **Goal:** Perform a comprehensive security audit and code review of the OwnPay codebase.
- **Standards Applied:** OWASP Top 10:2025 guidelines, ISO-27001 & PCI-DSS secure coding compliance, and project business/ledger rules.

## Research Findings
- **Supply Chain Security:** Running `composer audit` reports **zero** security vulnerability advisories. `composer.lock` is pinned and committed.
- **Code Correctness & Static Analysis:** Running `vendor/bin/phpstan analyse` returns **zero** errors on Level 1.
- **Functional Integrity & Test Suite:** Running `vendor/bin/phpunit` executes **405** unit tests (1153 assertions) with **100% success rate** (0 errors, 0 failures).
- **Authentication & 2FA Hardening:**
  - `TwoFactorMiddleware` securely enforces RFC 6238 TOTP check timing-safely (`hash_equals`).
  - **TOTP Replay Protection:** Securely checks that the slice is not repeated (`$checkSlice <= $lastUsedWindow`).
- **CSRF STP Protection:**
  - `CsrfMiddleware` implements Synchronizer Token Pattern (STP) using timing-safe comparisons (`hash_equals`).
  - **Multi-Tab Support:** Uses a pool of the last 10 session-bound tokens to support multi-tab operations without failing.
- **Request Signature Verification:**
  - `RequestSignatureMiddleware` validates webhook callbacks using HMAC-SHA256 or HMAC-SHA512.
  - Leverages timing-safe comparisons (`hash_equals`) and optional `X-Timestamp` validation to block replay attacks.
- **Content Security Policy & Nonce Enforcement:**
  - `SecurityHeadersMiddleware` appends HSTS, X-Content-Type-Options: nosniff, X-Frame-Options: DENY, and Referrer-Policy.
  - Dynamically builds CSP policies for Checkout using gateway `manifest.json` entries and runtime hooks.
  - Nonces are generated per-request and shared with the Twig views via the DI container.
- **Ledger Invariant & Balance Integrity:**
  - `LedgerService` enforces double-entry balancing (`bccomp($totalDebit, $totalCredit, 4) === 0`).
  - Uses `SELECT ... FOR UPDATE` row-locking during double-entry postings to avoid concurrency race conditions.
  - Employs strict PSR-4 domain isolation using `TenantScope` and cloned repositories.
- **Command & Object Injection:**
  - No `eval()` calls present in the codebase.
  - `unserialize()` is securely restricted in `FileCache.php` and `RedisCache.php` using `['allowed_classes' => false]`.
  - Command execution via `exec` in `BackupService` is safely parameter-escaped using `escapeshellarg`.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Read key middlewares first | Verifies core security layers (CSRF, XSS, 2FA, CSP, Signatures, Domains) |
| Execute test suites / analysis | Validates that audits match actual, passing code state without regressions |
| Verify ledger balance constraints | Confirms GAAP financial system integrity as required by database rules |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| None | All checks passed perfectly |

## Resources
- [DomainMiddleware](file:///c:/laragon/www/ownpay/src/Middleware/DomainMiddleware.php)
- [CsrfMiddleware](file:///c:/laragon/www/ownpay/src/Middleware/CsrfMiddleware.php)
- [TwoFactorMiddleware](file:///c:/laragon/www/ownpay/src/Middleware/TwoFactorMiddleware.php)
- [RequestSignatureMiddleware](file:///c:/laragon/www/ownpay/src/Middleware/RequestSignatureMiddleware.php)
- [SecurityHeadersMiddleware](file:///c:/laragon/www/ownpay/src/Middleware/SecurityHeadersMiddleware.php)
- [LedgerService](file:///c:/laragon/www/ownpay/src/Service/Payment/LedgerService.php)

