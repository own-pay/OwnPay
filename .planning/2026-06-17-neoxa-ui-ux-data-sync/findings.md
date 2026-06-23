# Findings & Decisions - Neoxa UI-UX Data Sync

## Requirements
- Sync Neoxa UI/UX variables.
- Prevent 500 errors caused by Twig's `strict_variables => true`.
- Update the database with missing columns:
  - `op_payment_links`: `require_address` (TINYINT(1) DEFAULT 0)
  - `op_merchants`: `color` (VARCHAR(7)), `initials` (VARCHAR(5)), `description` (VARCHAR(255))
  - `op_transactions`: `ip_address` (VARCHAR(45))
- Update `database/schema.sql` to keep DDL synchronized.
- Add Contributors page (`/admin/contributors`).

## Research Findings
- Twig strict variables mode will raise an exception if a property is accessed on a null value before evaluation of the default filter.
- Tables in `database/schema.sql` need to be modified:
  - `op_merchants` table definition (line 13)
  - `op_transactions` table definition (line 271)
  - `op_payment_links` table definition (line 342)

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Update schema.sql directly | Keeping single source of truth for DDL. |
| Execute SQL file manually on database | Apply migration directly to local MySQL. |
