# Task Plan: remove-admin-addons

## Goal
Remove the obsolete /admin/addons route, controller, view, and associated references to clean up the codebase and align with the unified plugins design.

## Current Phase
Phase 5: Delivery

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
- [x] Delete AddonController.php
- [x] Delete index.twig (addons)
- [x] Remove route from web.php
- [x] Remove route map from PermissionMiddleware.php
- [x] Remove referer check from PluginController.php
- [x] Fix back link in settings.twig
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify requirements met
- [x] Run PHPUnit, PHPStan, and linters
- [x] Document test results
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs
- [x] Deliver to user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Remove addons page completely | Redundant with unified /admin/plugins |

## Errors Encountered
| Error | Resolution |
|-------|------------|
