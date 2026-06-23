# Task Plan: Database CLI Seeder

## Goal
Implement a robust CLI database seeder that cleans all 50+ database tables and seeds them with 520+ interconnected, encrypted, and GAAP-balanced records.

## Current Phase
Phase 6: Relocation (Completed)

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent
- [x] Identify constraints
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define approach
- [x] Create project structure
- **Status:** complete

### Phase 3: Implementation
- [x] Execute the plan
- [x] Write to files before executing
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify requirements met
- [x] Document test results
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs
- [x] Deliver to user
- **Status:** complete

### Phase 6: Relocate Seeder to dev/seed/
- [x] Create `dev/seed/` and copy base SQL files
- [x] Move seeder script to `dev/seed/seeder.php` using dynamic paths
- [x] Delete `docs/seed/`
- [x] Verify execution and test suite
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use existing `LedgerService` | Guarantees balanced journal entries under strict double-entry rules. |
| Sync transaction dates | Updates generated ledger entries' `created_at` dates to transaction dates to ensure report accuracy. |
| Programmatic migrations check | Solved schema sync issues on local database during seeder run by applying pending alters. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| Duplicate entry for `1-CASH` | Widened the ledger unique key constraint via migration 010. |
| Data truncation on invoice status | Aligned SQL placeholders and parameters in `op_invoices` insert. |
| Syntax error near `time()` | Passed PHP `time()` outputs as bound parameters instead of raw strings. |

