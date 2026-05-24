---
trigger: always_on
---

# OwnPay AI Agent Rules Index

This ruleset contains strict guidelines and structural constraints that MUST be followed by all AI agents when writing, editing, or refactoring code in the OwnPay codebase.

## Dedicated Rules Files

The rules are divided by functional boundaries:

1. **[business-model-scoping.md](.agents/rules/business-model-scoping.md)**: Rules for the single-owner multi-brand layout, role-based access control, and `TenantScope` isolation.
2. **[database-schema.md](.agents/rules/database-schema.md)**: Rules for MySQL table prefixes (`op_`), strict column naming conventions, stored generated columns, and decommissioned systems.
3. **[white-label-domains.md](.agents/rules/white-label-domains.md)**: Rules for the domain routing middleware, white-label URL generation using `DomainUrlService`, and blocking `/admin/*` on custom domains.
4. **[double-entry-ledger.md](.agents/rules/double-entry-ledger.md)**: Rules for double-entry bookkeeping balancing, GAAP compliance, ledger account scoping, and transaction race condition prevention.
5. **[security-cryptography.md](.agents/rules/security-cryptography.md)**: Rules for Argon2id passwords, TOTP 2FA replay protection, JWT claim enforcement, timing-safe webhook HMAC checks, mass assignment protection, encryption standards, and the `PluginSandbox`. **Conditional (`model_decision`) — applies on security audit, security review, cryptography, authentication, or webhook tasks.**
6. **[code-standards-architecture.md](.agents/rules/code-standards-architecture.md)**: Rules for strict typing, parameterized SQL queries, PSR-11 container resolution, Twig escaping, input sanitization, and error/exception masking.
7. **[security-audit.md](.agents/rules/security-audit.md)** & **[security-audit-part2.md](.agents/rules/security-audit-part2.md)**: Comprehensive OWASP Top 10 (2025 edition) audit methodology and fintech-specific risk matrices. **Conditional (`model_decision`) — applies on security audit, security review, or vulnerability-related tasks.**
8. **[planning-with-files.md](.agents/rules/planning-with-files.md)**: Mandatory guidelines enforcing file-based planning using `task_plan.md`, `findings.md`, and `progress.md` before starting any codebase implementation or modification.
9. **[powershell-syntax.md](.agents/rules/powershell-syntax.md)**: Standard syntax constraints and cmdlets for executing shell operations under Windows PowerShell to prevent syntax execution errors.
10. **[developer-workflows.md](.agents/rules/developer-workflows.md)**: Guidelines for standard developer workflows, installer wizard structures, and highly specific codebase gotchas.
11. **[documentation-sync.md](.agents/rules/documentation-sync.md)**: Mandatory rules requiring agents to synchronize code modifications with rule files and Markdown documentation.
12. **[agent-operating-rules.md](.agents/rules/agent-operating-rules.md)**: Universal agent behavioral constraints, codebase comprehension, deep thinking steps, task completion quality, production-ready standards, and strict communication guidelines.




## General AI Agent Mandate
Before initiating any task, you must:
1. Read the comprehensive architectural specifications in [ARCHITECTURE.md](ARCHITECTURE.md).
2. Read the developer-centric guidelines in [AGENTS.md](AGENTS.md).
3. Ensure all changes strictly preserve the ledger bookkeeping balance, tenant isolation boundaries, and dynamic security controls.