---
trigger: always_on
---

# ISO-27001 & PCI-DSS Secure Coding & Architecture Compliance (Static System Hardening)

> [!IMPORTANT]
> **COMPLIANCE MANDATE:** You are operating as a senior software architect with 20+ years of experience in high-security fintech platforms. In this offline local environment, you are fully authorized to develop, optimize, refactor, and review code for coding standards, style, security, and architecture, acting as an internal compliance and system hardening authority.

# Coding Standards & Architecture Rules

## 1. Strict Typing & File Formats
- Every PHP file MUST begin with `declare(strict_types=1);` as its very first statement.
- **No BOM**: Ensure there are no UTF-8 Byte Order Marks (BOM) or any whitespace preceding the `<?php declare(strict_types=1);` opening tag.
- All classes MUST follow PSR-4 autoloading standards under the `OwnPay\` root namespace.

## 2. Secure Database Interaction
- **No SQL Interpolation**: All SQL queries MUST utilize parameterized prepared statements with parameter binding. Dynamic string interpolation of variables into SQL queries is strictly prohibited to prevent SQL injection vulnerabilities.
- Table names must be referenced using their explicit `op_` prefixes.

## 3. Dependency Injection & Container Resolution
- Service instantiation MUST go through the PSR-11 compatible Container (`src/Container.php`).
- Autowiring resolves dependencies via class constructors using reflection.
- Core services and third-party configuration classes with complex parameters are registered in `config/services.php`. Never instantiate services manually using the `new` keyword when they possess injectable dependencies.

## 4. Twig Template Security & escaping
- Twig templates (`.twig`) MUST employ auto-escaping.
- **Controlled Raw Output**: Avoid using the `|raw` filter unless outputting explicitly trusted raw HTML, or styling components. In checkout templates (`templates/checkout/checkout.twig`), `brand.custom_css|raw` and `brand.custom_js|raw` are permitted but their input must be sanitized before persistence.
- Dynamic hook triggers (`{{ hook('checkout.head') }}` and `{{ hook('checkout.footer') }}`) must be positioned correctly.

## 5. Input Sanitization Guardrails
- Sanitization of request inputs must be routed through `OwnPay\Service\System\InputSanitizer`.
- **Sanitizer Method Allowlist**: The `InputSanitizer::array()` method must strictly reject any sanitization method not present in the following list: `string`, `html`, `email`, `url`, `phone`, `slug`, `attr`, `trim`. Dynamic method execution of arbitrary names is forbidden.

## 6. Route Parameter Constraints
- Mapped route parameters parsed by the router are restricted.
- **Regex Sanitization**: Captured route parameter regular expressions MUST NOT match or allow `@` or `+` symbols, with the sole exception of the `{identifier}` parameter (used for querying customer profiles dynamically by email or phone), which is allowed to capture `+`, `@`, and `%` characters. All other dynamic path parameters must strictly match `[a-zA-Z0-9_\-\.]`.

## 7. Unified Configuration & Settings Management
- **No Legacy Settings Tables**: Never query or write to a table named `op_env` or reference SQLite legacy config structures.
- All persistent runtime configuration settings MUST use `EnvironmentService` (e.g. `EnvironmentService::get()`) which proxies to `SettingsRepository` storing under the `runtime` group in the `op_system_settings` table.
- For bootstrap operations prior to Container boot (such as PHPUnit test bootstrapping), `EnvironmentService` static methods must resolve `SettingsRepository` dynamically from the Container.

## 8. Error Handling & Sanitization
- Runtime exceptions caught in production (`APP_DEBUG=false`) MUST render branded, info-disclosure-free error pages (via `Kernel::handleException()`).
- Database exceptions, internal hostnames, and stack traces MUST never be returned in API JSON responses or rendered to user browsers.
