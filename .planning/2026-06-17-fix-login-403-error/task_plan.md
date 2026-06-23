# Task Plan: Resolve Login POST 403 Forbidden Error

## Goal
Diagnose and resolve the 403 Forbidden error occurring during the admin login.

## Current Phase
Phase 9: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent: User receives a 403 error when submitting the login form.
- [x] Identify constraints: Do not guess or hallucinate. Use actual codebase logic. Enforce security.
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define the debug approach to pinpoint where the 403 is originating.
- [x] Formulate a verification strategy using logs or test routes.
- **Status:** complete

### Phase 3: CSRF Debug Instrumentation
- [x] Execute the diagnostic changes to log CSRF failures.
- [x] Add specific CSRF failure reasons to the 403 HTML page output.
- **Status:** complete

### Phase 4: Local Client Verification
- [x] Verify login functions without 403 errors locally (both CLI and HTTP client).
- [x] Verify standard PHPUnit tests pass.
- **Status:** complete

### Phase 5: Browser Automation & Root Cause Analysis
- [x] Execute login flow using browser automation.
- [x] Trace the resulting 403 Forbidden response to PermissionMiddleware.
- [x] Inspect the database permissions and identify the seeder comments bug.
- **Status:** complete

### Phase 6: Seeder Fix & Verification
- [x] Modify `storage/seed_dummy_data.php` and `dev/seed/seeder.php` to clean comments.
- [x] Run the database seeders and verify permissions are fully populated.
- [x] Test the login flow using the browser to verify redirection to admin dashboard.
- [x] Verify PHPUnit and other linters pass.
- **Status:** complete

### Phase 7: Delivery
- [x] Verify clean PHPUnit and PHPStan checks.
- [x] Report final root cause and solution.
- **Status:** complete

### Phase 8: Installer Boot & Routing Verification
- [x] Diagnose the 500 error when booting the application in an uninstalled state (empty DB).
- [x] Fix Router.php route loading to check for the install lock before resolving PluginRegistry.
- [x] Test the /install routing flow using the browser to verify successful redirect and rendering.
- [x] Run full PHPUnit and PHPStan checks.
- **Status:** complete

### Phase 9: Delivery
- [x] Deliver final walkthrough and fix explanation.
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Clean SQL comments in seeder | Single-line comment headers in seed SQL files caused the `str_starts_with(..., 'INSERT')` logic to skip insertion, leaving the permissions table empty and triggering 403 Insufficient Permissions. |

## Errors Encountered
| Error | Resolution |
|-------|------------|


