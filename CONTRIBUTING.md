# Contributing to OwnPay

Thank you for your interest in contributing to **OwnPay**! As an enterprise-grade, self-hosted payment gateway orchestrator, we welcome high-quality contributions from the community. To keep the project stable, secure, and maintainable, we enforce a strict set of contributing guidelines.

---

## ⚖️ License & Agreements
By contributing to this repository, you agree that your contributions will be licensed under the **GNU Affero General Public License v3.0 (AGPL-3.0)**. 

---

## 🚦 Pull Request Process & Branching Model

> [!IMPORTANT]
> **Mandatory Branching Rule**:
> All contribution Pull Requests **MUST target the `dev` branch**. Pull requests opened directly against the `main` branch **will be automatically closed and rejected**. The `main` branch is reserved strictly for stable, production-ready releases.

### Standard Workflow:
1. **Fork** the official repository: [own-pay/OwnPay](https://github.com/own-pay/OwnPay).
2. **Clone** your fork locally:
   ```bash
   git clone https://github.com/your-username/OwnPay.git
   cd OwnPay
   ```
3. **Set Up Upstream Remote**:
   ```bash
   git remote add upstream https://github.com/own-pay/OwnPay.git
   ```
4. **Create a Feature Branch** from the `dev` branch:
   ```bash
   git checkout -b feature/your-awesome-feature
   ```
5. **Implement Changes** following the coding standards below.
6. **Verify and Test** your changes locally.
7. **Commit & Push** to your fork:
   ```bash
   git commit -am "feat: add secure transaction logging to bKash gateway"
   git push origin feature/your-awesome-feature
   ```
8. **Open a Pull Request** targeting the `dev` branch. Complete the pull request template entirely.

---

## 🎨 Coding Standards & Guidelines

To maintain code quality across the codebase, we enforce modern PHP best practices:

### 1. PHP Syntax & Strict Types
* All PHP files must start with the strict types declaration:
  ```php
  <?php
  declare(strict_types=1);
  ```
  Ensure there are no leading spaces or UTF-8 Byte Order Marks (BOM) before `<?php`.
* We follow the **PSR-12** extended coding style guide.

### 2. Dependency Injection & Container
* Never use `global` variables or raw session access for core services.
* Resolve dependencies via the PSR-11 compliant container (`src/Container.php`). Use constructor injection.

### 3. Database & Repository Patterns
* All queries must utilize parameterized values to prevent SQL injection.
* Data related to a brand/merchant must use the `TenantScope` trait and be queried via `$repo->forTenant($merchantId)`.
* Do not perform direct SQL queries in controllers. Always wrap data operations inside Repositories extending `BaseRepository`.

### 4. CSRF Protection
* All state-changing HTML forms must include a CSRF token:
  ```html
  <input type="hidden" name="_csrf_token" value="{{ csrf_token }}">
  ```
* In PHP, retrieve the canonical CSRF token via `\OwnPay\Security\SecurityHelpers::csrfToken()`.

### 5. Plugin Architecture
* Gateways, Addons, and Themes must be placed inside the `modules/` directory:
  - Gateway integrations: `modules/gateways/`
  - Themes: `modules/themes/`
  - Addon modules: `modules/addons/`
* Every module must contain a valid `manifest.json` describing its configuration and security parameters (including dynamic CSP declarations).

---

## 🧪 Verification & Testing

Before submitting a Pull Request, you must run the local verification suite:

### 1. PHP Syntax Check (Linting)
Ensure there are no syntax errors in your modified files:
```bash
find src/ -name "*.php" -exec php -l {} \;
```

### 2. Static Analysis (PHPStan)
Run PHPStan to verify type safety and architectural compliance:
```bash
vendor/bin/phpstan analyse
```

### 3. Automated Unit Tests (PHPUnit)
All tests must pass successfully before a review is conducted:
```bash
vendor/bin/phpunit
```

---

## 🛡️ Security Vulnerabilities
If you discover a security vulnerability within OwnPay, **do not** open a public GitHub issue. Please follow our [Security Policy](SECURITY.md) and report it privately via email to [ping@ownpay.org](mailto:ping@ownpay.org).

---
*Built by the Community, for the Community.*
