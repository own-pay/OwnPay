# Local Security Audit Workflow

This document defines the enterprise-grade security audit workflow for agents running locally on the OwnPay codebase.

## 🏁 Phase 1: Dependency Audit
Check for known vulnerabilities in third-party packages.
- **Action**: Run `npm audit` and `composer audit`.
- **Remediation**: Use `npm audit fix` or update `composer.json` versions.

## 🔍 Phase 2: Static Analysis & Secret Scanning
- **Action**: Run PHPStan to find type inconsistencies that could lead to logic bugs.
    - `vendor/bin/phpstan analyze`
- **Action**: Scan for hardcoded secrets or API keys.
    - Use `grep -rE "(key|secret|password|token)[[:space:]]*=[[:space:]]*['\"][a-zA-Z0-9]{10,}" .`

## 🧠 Phase 3: Manual Code Review (High-Risk Targets)
Audit the following files for logic flaws:
1.  **`app/core/adapter.php`**: Check session flags and middleware initialization.
2.  **`app/install/index.php`**: Ensure the `.installed` lock is respected.
3.  **`src/Service/FilesystemService.php`**: Verify path traversal guards.
4.  **`src/Service/ImageService.php`**: Verify metadata stripping and extension whitelisting.

## ☣️ Phase 4: Zero-Day / Dangerous Sink Search
Search for PHP functions that are often vectors for exploitation.
- **Search for Code Execution**: `grep -r "eval(" .`
- **Search for Insecure Deserialization**: `grep -r "unserialize(" .`
- **Search for Dynamic Variable Injection**: `grep -r "extract(" .`
- **Search for Shell Execution**: `grep -rE "(shell_exec|exec|system|passthru)\(" .`

## 🏗️ Phase 5: Infrastructure & Hardening Verification
- **Check `.htaccess`**: Ensure `X-Frame-Options`, `X-Content-Type-Options`, and `Content-Security-Policy` are present.
- **Check CSRF**: Ensure all forms use `csrf_token()` and the `CsrfMiddleware` is active in `adapter.php`.
- **Check Database**: Ensure `PDO::ATTR_EMULATE_PREPARES` is set to `false` in `src/Core/Database.php`.

## 📄 Reporting
After completing the audit, generate a report summarizing:
1.  **Vulnerabilities Found** (Categorized by severity).
2.  **Remediation Steps Taken**.
3.  **Residual Risks**.
