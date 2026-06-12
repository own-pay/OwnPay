# Findings & Decisions

## Requirements
- Check `docs/v2/audit_findings/ownpay_master_audit_report.md`
- Cross-check if the findings are real.
- If real, create a fixing plan in the same directory.
- Adhere to the strict plans and agent rules.

## Research Findings
- **INV-1: Single Super-Administrator (Business Model) - VIOLATED (CRITICAL)**
  - **Status:** **REAL**
  - **Verification:** Verified in `database/schema.sql` (lines 64-89) that `op_merchant_users` defines `merchant_id` as `BIGINT UNSIGNED NOT NULL`. It also defines a foreign key `fk_mu_merchant` pointing to `op_merchants(id)` with `ON DELETE CASCADE`.
  - **Implications:** Since the superadmin (`is_superadmin = 1`) is created with a `merchant_id` during installation, deleting the associated merchant brand will trigger `ON DELETE CASCADE` and delete the superadmin user, locking out the owner. A superadmin should conceptually not be hard-bound to any specific brand/merchant context.
- **INV-2: Brand = Merchant (Data Scoping) - PASS**
  - **Status:** **REAL**
  - **Verification:** `CustomerRepository.php` correctly implements `TenantScope`. Bypasses only occur intentionally (e.g. `countForDashboard` when merchant ID is null).
- **INV-5: Ledger is Double-Entry (Financial Logic) - PASS**
  - **Status:** **REAL**
  - **Verification:** `LedgerService::postEntries()` (lines 101-104) uses `bccomp($totalDebit, $totalCredit, 4) !== 0` to throw an exception if debits do not match credits. The entire posting is wrapped in a database transaction block (`$db->transaction`).

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Make `merchant_id` and `role_id` nullable in `op_merchant_users` | Allows creating global superadministrators (`merchant_id = NULL`, `role_id = NULL`) that do not belong to any specific merchant, protecting them from cascade deletes if a merchant is deleted. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [schema.sql](file:///c:/laragon/www/ownpay/database/schema.sql#L64)
- [CustomerRepository.php](file:///c:/laragon/www/ownpay/src/Repository/CustomerRepository.php#L16)
- [LedgerService.php](file:///c:/laragon/www/ownpay/src/Service/Payment/LedgerService.php#L101)
