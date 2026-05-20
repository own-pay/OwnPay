# Deep Re-Audit Bug Remediation Plan

**Goal:** Fix all verified bugs and gaps in `comprehensive_deep_audit.md` for production readiness.
**Status:** completed

## Phase 1: Ledger Booking Engine Hardening (BUG 01 & BUG 02)
- Status: completed
- [x] Refactor `LedgerRepository::findOrCreateAccount` to query by name, currency, merchant (avoiding type filters)
- [x] Add `getAccountType` helper mapping in `LedgerService` and pass correct types
- [x] Add entry type to `LedgerRepository::adjustBalance` and calculate sign dynamically based on account type

## Phase 2: Transaction Metadata & Refund Security (BUG 03 & BUG 04)
- Status: completed
- [x] Refactor `TransactionRepository::updateMetadata` to merge current metadata instead of overwriting
- [x] Add `getTotalRefundedAmount` to `RefundRepository`
- [x] Inject `LedgerService` into `RefundService` and add over-refund checks using BCMath
- [x] Log refund transactions to double-entry ledger via `recordRefund`

## Phase 3: Auth Security, JWT App Pairing & RBAC (BUG 06 & BUG 07)
- Status: completed
- [x] Update `JwtService` singleton DI constructor parameters in `services.php`
- [x] Inject `aud` claim in `JwtService::issue`
- [x] Harden `PermissionMiddleware` default fallback for unmapped `/admin` routes to `'system.unmapped'`
- [x] Fire event hooks in `Authenticator` on successful/failed login and logout

## Phase 4: Plugin Loader Scanner, Sandbox & Overdue Invoices (BUG 08, BUG 05 & Gap III)
- Status: completed
- [x] Fix OOP false positives in `PluginLoader` tokenizer scanner by ignoring member/method prefixes
- [x] Track active plugin context in `EventManager::doAction` and `applyFilter`
- [x] Wire `PluginRegistry` to `Database` and validate plugin queries inside `Database::execute`
- [x] Remove overdue invoice block in `InvoiceCheckoutController::show`

## Phase 5: Database Indexing & Cleanups (Gap I & BUG 09)
- Status: completed
- [x] Add generated virtual columns and indexes to `database/schema.sql` for metadata JSON extraction
- [x] Rename step twig files to `.php` in `templates/install/`
- [x] Rename `renderTwig` to `renderPhpTemplate` in `InstallerController.php` and update references

## Phase 6: Lint, Test, and Verification
- Status: completed
- [x] Run PHP lint `php -l` on all modified files
- [x] Create double-entry & refund test scripts to verify correct accounting balance computations
- [x] Create walkthrough.md artifact summarizing changes
