---
trigger: always_on
---

# OwnPay AI Agent Rules Index

This ruleset contains strict guidelines and structural constraints that MUST be followed by all AI agents when writing, editing, or refactoring code in the OwnPay codebase.

## Dedicated Rules Files

The rules are divided by functional boundaries:

1. **[business-model-scoping.md](file:///.agents/rules/business-model-scoping.md)**: Rules for the single-owner multi-brand layout, role-based access control, and `TenantScope` isolation.
2. **[database-schema.md](file:///.agents/rules/database-schema.md)**: Rules for MySQL table prefixes (`op_`), strict column naming conventions, stored generated columns, and decommissioned systems.
3. **[white-label-domains.md](file:///.agents/rules/white-label-domains.md)**: Rules for the domain routing middleware, white-label URL generation using `DomainUrlService`, and blocking `/admin/*` on custom domains.
4. **[double-entry-ledger.md](file:///.agents/rules/double-entry-ledger.md)**: Rules for double-entry bookkeeping balancing, GAAP compliance, ledger account scoping, and transaction race condition prevention.
5. **[security-cryptography.md](file:///.agents/rules/security-cryptography.md)**: Rules for Argon2id passwords, TOTP 2FA replay protection, JWT claim enforcement, timing-safe webhook HMAC checks, and the `PluginSandbox`.
6. **[code-standards-architecture.md](file:///.agents/rules/code-standards-architecture.md)**: Rules for strict typing, parameterized SQL queries, PSR-11 container resolution, Twig escaping, input sanitization, and error/exception masking.

## General AI Agent Mandate
Before initiating any task, you must:
1. Read the comprehensive architectural specifications in [ARCHITECTURE.md](file:///c:/laragon/www/ownpay/ARCHITECTURE.md).
2. Read the developer-centric guidelines in [AGENTS.md](file:///c:/laragon/www/ownpay/AGENTS.md).
3. Ensure all changes strictly preserve the ledger bookkeeping balance, tenant isolation boundaries, and dynamic security controls.