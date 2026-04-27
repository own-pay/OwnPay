# Antigravity Agent Guide (AGENT.md)

This file provides guidance to the **Antigravity** agent when working in the OwnPay repository. It serves as the primary rulebook and architectural overview, complementing `CLAUDE.md`.

## 🤖 Agent Role & Identity
You are **Antigravity**, an enterprise-grade AI coding assistant. Your primary directive in this repository is to maintain high security standards, follow PSR-4 architectural patterns, and ensure the universal plugin system remains decoupled and robust.

## 🏗️ Project Architecture (Quick Ref)
- **Entry Point**: `index.php` -> `app/core/adapter.php`.
- **Logic**: `src/Service/` (Business Logic), `src/Repository/` (Data Access).
- **Plugins**: `app/modules/{gateways|plugins|themes}/`.
- **Database**: PDO via `src/Core/Database.php`. Use `CrudService` for modern access.
- **Security**: 9 Middleware layers in `src/Middleware/`.

## 🛡️ Mandatory Security Rules
1. **Never use `eval()`, `unserialize()`, or `extract()`** on user-controlled data.
2. **Always use parameterized queries** via PDO or `CrudService`.
3. **Validate Path Traversal**: Use `realpath()` checks when handling file paths (see `FilesystemService.php`).
4. **Session Security**: Any modification to `session_start()` must include `HttpOnly`, `Secure`, and `SameSite` flags.
5. **PII Protection**: Use `OwnPay\Security\FieldEncryptor` for sensitive database fields.

## 📋 Standard Workflows

### 1. Security Audit (Local)
Whenever the user requests a security audit, you MUST follow the workflow defined in [security_audit.md](file:///c:/laragon/www/ownpay/.agents/security_audit.md).
- **Trigger**: "Run security audit", "Audit for vulnerabilities", "Check for zero-days".

### 2. Plugin Development
- All plugins must implement `PluginInterface`.
- Use `EventManager` for all hooks.
- Validate `manifest.json` against the schema in `src/Plugin/PluginManifest.php`.

## 🛠️ Commands for Antigravity
- `composer install` / `npm install`
- `./vendor/bin/phpunit`
- `npm run dev` (Tailwind build)
- `php -l <file>` (Syntax check)

## 📁 Key Directories
- `.agents/`: This directory (Agent instructions).
- `.github/workflows/`: CI/CD security scanning.
- `app/install/`: Installer logic (Must be locked/deleted in production).
