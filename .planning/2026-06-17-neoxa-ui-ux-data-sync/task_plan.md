# Task Plan: Neoxa UI-UX Data Sync & 500 Error Resolution

## Goal
Sync Neoxa UI/UX variables, resolve 500 errors (strict_variables), add missing columns/fields, update schema.sql, add contributors page, and ensure tests pass.

## Current Phase
Completed

## Phases

### Phase 1: Backend Logic Fixing
- [x] Implement notifications bell aggregator in `AdminPageTrait.php`
- [x] Retrieve, decrypt and bind customer info in `DisputeController.php`
- [x] Resolve gateway name and IP address in `TransactionController.php`
- [x] Calculate trends, revenue, target gauge, recent payment intents in `DashboardController.php`
- **Status:** complete

### Phase 2: Null/Resilience Fixing
- [x] Wrap brand switcher with ternary check in `sidebar.twig`
- [x] Wrap brand name with ternary in fee rules creation/edit templates
- [x] Wrap notifications bell loop with undefined safety check in `notification_panel.twig`
- **Status:** complete

### Phase 3: Database & Repository Updates
- [x] Execute `012_add_missing_ui_columns.sql` migration on the local MySQL database
- [x] Update `database/schema.sql` DDL to match the new columns
- [x] Add new fields to `$fillable` in `MerchantRepository`, `PaymentLinkRepository`, and `TransactionRepository`
- [x] Update raw SQL `create()` and `update()` in `PaymentLinkService.php` to handle `require_address`
- [x] Update SELECT in `BrandContext::getAllBrands()` to fetch new columns
- **Status:** complete

### Phase 4: Mismatch Resolution
- [x] Align transaction history variables in `dashboard.twig` (`tx.email` and `tx.description`)
- **Status:** complete

### Phase 5: New Features & Leftovers
- [x] Create `ContributorController.php` returning static list of contributors
- [x] Register `/admin/contributors` route in `web.php`
- [x] Register permissions in `PermissionMiddleware.php`
- **Status:** complete

### Phase 6: Verification
- [x] Run PHPUnit tests
- [x] Run Twig syntax linter
- [x] Run PHPStan static analysis (Level 9 check)
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| strict_variables => true | Kept active to ensure robustness and enforce null checks in templates. |
| schema.sql updates | Directly updated to prevent DB layout divergence. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| Mixed-type casting warnings | Utilized is_scalar() guards to narrow type before casting or concatenating mixed parameters. |
