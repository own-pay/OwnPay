# Findings: Audit and Refactor Agent Rules Configuration

## Requirements Analysis
We need to:
1. Cross-reference `AGENTS.md` with all rules inside `.agents/rules/` and de-duplicate.
2. Extract remaining granular, complex guidelines into a dedicated rule file inside `.agents/rules/`.
3. Restructure `AGENTS.md` to be a clean, high-level manifest/routing table pointing to the `.agents/rules/` files.

---

## Redundancy Mapping

| Section in `AGENTS.md` | Target Rule in `.agents/rules/` | Status |
| :--- | :--- | :--- |
| Business Model | `business-model-scoping.md` | **Fully Redundant** |
| Architecture Entry Point & PSR-11 DI | `code-standards-architecture.md` | **Fully Redundant** |
| Repository & TenantScope Pattern | `business-model-scoping.md` | **Fully Redundant** |
| Directory Structure | `AGENTS.md` / `ARCHITECTURE.md` | **High-level reference** |
| Database Schema & Prefix | `database-schema.md` | **Fully Redundant** |
| Column Naming Conventions | `database-schema.md` | **Fully Redundant** |
| Stored Generated Columns & Decommissioned Tables | `database-schema.md` | **Fully Redundant** |
| Brand Context Resolution | `business-model-scoping.md` | **Fully Redundant** |
| Authentication / RBAC / 2FA | `security-cryptography.md` | **Fully Redundant** |
| White-Label Domain Pipeline | `white-label-domains.md` | **Fully Redundant** |
| Double-Entry Ledger Bookkeeping | `double-entry-ledger.md` | **Fully Redundant** |
| Plugin Sandbox Scanning & Exec | `security-cryptography.md` | **Fully Redundant** |
| CSRF, JWT, TOTP, and Keys | `security-cryptography.md` | **Fully Redundant** |
| Coding Standards (strict typing, parameterized SQL, InputSanitizer) | `code-standards-architecture.md` | **Fully Redundant** |
| Common Tasks | Unmapped | **Extract to `developer-workflows.md`** |
| Unmapped Known Gotchas | Unmapped | **Extract to `developer-workflows.md`** |

---

## Unmapped Granular Rules (To Extract)

The following developer workflows and specific gotchas are not covered by any active rule yet:
1. **Common Tasks:**
   * Adding a new admin page & route.
   * Admin sidebar structure sequencing.
   * Installer wizard architecture (Wizard phases, rate limits, env.temp base64 keys).
   * Injecting repositories and services.
2. **Specific Gotchas:**
   * Plugin name enrichment (two-pass manifest and filesystem).
   * Theme slug mismatch (`own-pay` vs legacy `own-pay-theme`).
   * Brand status combobox enum validation (`active`, `suspended`, `pending`).
   * Manual gateway logos relative `/storage/` prefixing.
   * Invoice line-items dynamic subtotal update and line-items purge/re-insert.
   * Device pairing token fallback to superadmin ID `1`.
   * Notification acknowledgment device-uuid scoping and `(string)` casting.
   * Gateway currency conversions metadata integrity.

---

## Action Plan

1. **Create `developer-workflows.md`:** Write a new `always_on` rule file housing the unmapped tasks and specific gotchas.
2. **Update `architecture-rule.md`:** Index the new rule file at index 10.
3. **Rewrite `AGENTS.md`:** Restructure it as a premium high-level manifest that details project context, global agent constraints, and maps to the 11 rule files.
