# Findings & Decisions - Agent Rules Generation

## Requirements
- Create dedicated markdown files in `.agents/rules/` containing strict rules that AI agents must follow.
- Rules must cover architecture, database schema, white-labeling, security, ledger bookkeeping, and coding standards.
- Each rule file must begin with YAML frontmatter:
  ```yaml
  ---
  trigger: always_on
  ---
  ```
- Make multiple rules, with a dedicated file for each functional area.

## Key Discoveries
- **Single-Owner Multi-Brand Model**: Explicitly not multi-tenant SaaS. Isolation uses the `merchant_id` column.
- **Boot Sequence & DI Container**: Container explicitly bound in `config/services.php`. Light-weight autowiring.
- **Tenant Scope Trait**: Repositories must call `$repo->forTenant($mid)` before executing scoped queries.
- **Double-Entry Ledger Engine**: Balances debit/credit, locks transactions for double-post protection, validates accounting rules (Asset/Expense DR increases, Liability/Equity/Revenue CR increases).
- **White-Label Domain Pipeline**: Resolves host via `DomainMiddleware`, generates custom domain URLs with `DomainUrlService`. Admin paths blocked on custom domains.
- **Security Protocols**: Enforces CSRF tokens using `SecurityHelpers::csrfToken()`, verifies JWT issuer, validates pairing logs, uses timing-safe HMAC checks, and restricts plugins using `PluginSandbox`.
- **Database Column Conventions**: Columns must follow precise names (e.g. `two_factor_enabled`, `totp_secret_enc`, `decimal_places`, `base_currency`, etc.).

## Planned Rule Files & Mappings

1. **`business-model-scoping.md`**: Rules for tenant isolation, RBAC/staff access, and `TenantScope` repository scoping.
2. **`database-schema.md`**: Rules for prefixing `op_`, column naming conventions, and stored generated indexing columns.
3. **`white-label-domains.md`**: Rules for custom domain resolution, URL building via `DomainUrlService`, and blocking `/admin/*` on custom domains.
4. **`double-entry-ledger.md`**: Rules for debit/credit balancing, double-posting protection, asset/liability directionality, and merchant-specific account resolution.
5. **`security-cryptography.md`**: Rules for Argon2id, dynamic OTP 2FA, JWT companion verification, timing-safe HMAC check on webhooks, and `PluginSandbox` operations.
6. **`code-standards-architecture.md`**: Rules for `declare(strict_types=1)`, no UTF-8 BOM, PSR-11 Container, and Twig templates with safe filters.
