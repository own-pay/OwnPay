# Findings & Decisions

## Requirements
- Create a comprehensive database seeder CLI script at `docs/seed/seeder.php`.
- Populate all database tables (52 tables in total) with logical data.
- Dynamically generate 500+ records for hot tables: customers, payment intents, transactions, ledger entries.
- Use Argon2id passwords for users (`admin` / `admin123`).
- Secure customer PII (Name, Email, Phone) using AES-256-GCM encryption with container-derived `FieldEncryptor`.
- Ensure double-entry journal balance constraints are met (Debits = Credits) for ledger transactions.

## Research Findings
- The application database schema contains 52 tables.
- Table `op_ledger_accounts` unique index key in database was `uk_merchant_name` (`merchant_id`, `name`), but migration `010_ledger_account_currency_unique.sql` alters it to drop and re-add `uk_merchant_name_currency` (`merchant_id`, `name`, `currency`). This migration had to be run to enable multi-currency CASH and MERCHANT_PAYABLE accounts (BDT & USD).

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Use `LedgerService` directly | Ensures GAAP double-entry journal balance is strictly met for cash, payables, and platform fee revenues. |
| Sync ledger entry creation dates | Keeps ledger transaction reporting timelines matched with completed transaction times. |
| Auto-migration syncer | Re-runs pending migration files (including 010 and 011) to bring the target database structure to the latest state. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Duplicate key '1-CASH' | Ran database migrations to update the unique key constraint to widen it. |
| Invoice status truncation | Fixed parameter mismatch in array query mapping. |
| Syntax error near `time()` | Passed `time()` outputs as bound parameters instead of inlining PHP function calls in SQL strings. |

## Resources
- [seeder.php](file:///c:/laragon/www/ownpay/docs/seed/seeder.php)
- [schema.sql](file:///c:/laragon/www/ownpay/database/schema.sql)
- [walkthrough.md](file:///C:/Users/iamna/.gemini/antigravity-ide/brain/0ee4f8a9-c0bd-46c1-84b5-c68c5634a6f0/walkthrough.md)

