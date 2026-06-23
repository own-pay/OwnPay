# Task Plan: Neoxa UI-UX Data Sync & 500 Error Resolution

## Goal
Create a detailed, phase-wise implementation plan to resolve fatal Twig strict variable crashes (500 errors) and align new or mismatched Neoxa UI/UX variables with the PHP backend controllers and MySQL database.

## Current Phase
Phase 6: Implementation Planning (Phase-wise breakdown)

## Phases

### Phase 1: Requirements & Discovery (Completed)
- [x] Run git status / git diff to identify modified template files
- [x] Launch tests/linters to find 500 error / parse failures
- [x] Document initial findings in `findings.md`
- **Status:** complete

### Phase 2: Systematic Template Diff & Variable Extraction (Completed)
- [x] Diff each modified Twig and PHP page template file against git HEAD
- [x] List all new or modified variables, filters, functions, and loops
- [x] Group by template file and context
- **Status:** complete

### Phase 3: Backend Cross-Referencing & Mismatch Analysis (Completed)
- [x] Locate backend controllers/repositories feeding each template
- [x] Compare template variables against the fields passed from controllers and the database schema
- [x] Verify existence, name spelling, and types
- **Status:** complete

### Phase 4: Mapping Document Generation (Completed)
- [x] Write detailed mapping report to `docs/ui-ux-data-mapping.md`
- **Status:** complete

### Phase 5: Verification & Review (Completed)
- [x] Run PHPUnit and Twig linter to ensure no syntax errors remain in templates
- [x] Validate that the mapping document is completely accurate, detailed, and non-hallucinated
- **Status:** complete

### Phase 6: Implementation Planning (Current)
- [x] Analyze codebase structure for required changes (AdminPageTrait, DisputeController, TransactionController, DashboardController, FeeRuleController)
- [x] Design Phase 1: Backend Logic Fixing (bell notifications, dispute customer, transaction gateway/IP, dashboard KPIs)
- [x] Design Phase 2: Null/Resilience Fixing (sidebar switcher, fee rule create/edit checks, notification panel wrap)
- [x] Design Phase 3: Missing Values (Database migrations for op_payment_links, op_merchants, op_transactions, repository fillable, service integration)
- [x] Design Phase 4: Mismatch Resolution (dashboard table alignment, JS/sidebar class sync)
- [x] Design Phase 5: New Features & Leftovers (ContributorController, web route, PermissionMiddleware map)
- [x] Create comprehensive implementation plan artifact `implementation_plan.md` in the brain folder
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use session/cache for notification read status | There is no dedicated system notification table; dynamic generation from audit logs combined with session read trackers is the cleanest and most compliant solution. |
| Ternary checks over Twig default filter | Accessing null properties under Twig's strict variables triggers fatal errors before the default filter applies. Ternary checks `active_brand ? active_brand.name : 'System'` are required. |
| Maintain strict_variables = true | Disabling strict variables would mask structural errors; hardening the codebase is a security and stability best practice. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
