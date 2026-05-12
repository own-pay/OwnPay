---
description: This document defines the enterprise-grade security audit workflow for agents running locally on the OwnPay codebase.
---

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
Audit the following files for logic flaws and security bypasses:
1.  **`src/Kernel.php`**: Check the DI container initialization, middleware pipeline execution, and exception handling.
2.  **`public/index.php`**: Verify front-controller entry point and basic environment setup.
3.  **`src/Controller/Install/`**: Ensure installation controllers correctly enforce the `.installed` lock from `storage/`.
4.  **`src/Plugin/PluginSandbox.php`**: Audit the token-based scanner and capability enforcer for the universal plugin system.
5.  **`src/Security/FieldEncryptor.php`**: Verify AES-256-GCM implementation and secure handling of the `PII_ENCRYPTION_KEY`.
6.  **`src/Service/System/FilesystemService.php`**: Verify path traversal guards (realpath checks) used across the app.
7.  **`src/Service/System/ImageService.php`**: Check metadata stripping and mime-type validation logic.

## ☣️ Phase 4: Zero-Day / Dangerous Sink Search
Search for PHP functions that are often vectors for exploitation.
- **Search for Code Execution**: `grep -r "eval(" .`
- **Search for Insecure Deserialization**: `grep -r "unserialize(" .`
- **Search for Dynamic Variable Injection**: `grep -r "extract(" .`
- **Search for Shell Execution**: `grep -rE "(shell_exec|exec|system|passthru)\(" .`

## 🏗️ Phase 5: Infrastructure & Hardening Verification
- **Middleware Check**: Ensure `src/Middleware/SecurityHeadersMiddleware.php` is active in the global stack.
- **CSRF Check**: Verify `src/Middleware/CsrfMiddleware.php` rotates tokens and validates all POST requests.
- **Database Check**: Ensure `PDO::ATTR_EMULATE_PREPARES` is set to `false` in `src/Core/Database.php`.

## 📄 Reporting
After completing the audit, generate a report summarizing:
1.  **Vulnerabilities Found** (Categorized by severity: Critical, High, Medium, Low).
2.  **Remediation Steps Taken**.
3.  **Residual Risks** and long-term hardening recommendations.

## 🛠️ Usage
**Trigger Phrases**:
- "Run security audit"
- "Audit for vulnerabilities"
- "Check for zero-days"
- "Security review of the codebase"
