# Task Plan: system-optimization-suite

## Goal
Implement a premium, safe, manually-triggered Maintenance & Optimization suite under Settings → Optimization tab, supporting cache cleaning, hybrid DB optimization, configurable log purging, and temp files cleanup.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent and requirements (safe, manual, recommended monthly).
- [x] Identify constraints (hybrid ANALYZE/OPTIMIZE on DB, configurable retention).
- [x] Document in findings.md.
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define backend routes and execution actions.
- [x] Create implementation plan artifact.
- **Status:** complete

### Phase 3: Implementation
- [x] Implement backend optimization methods in `SettingsController.php`
- [x] Register new POST routes in `routes/web.php`
- [x] Add the new Optimization tab and stats cards UI in `settings/index.twig`
- [x] Harden CSS styles in `settings.css`
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Create `PlatformMaintenanceTest.php` to verify all optimization actions
- [x] Run PHPUnit tests and verify all pass successfully
- [x] Run PHPStan static analysis at strict Level 9
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs and ensure zero regressions
- [x] Document final walkthrough.md
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Hybrid DB Optimization | Runs fast, non-blocking `ANALYZE TABLE` globally, and `OPTIMIZE TABLE` on hot tables to avoid excessive locks in production. |
| Settings Tab Placement | Placed as a unified settings tab under Settings → Optimization to match existing tabbed settings structure. |
| Configurable Log Purging | Retention period dropdown directly in UI to give admins control over log retention while keeping tables clean. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| PHPStan: instanceof will always evaluate to true | Declared cache type as `mixed` under DocBlock. |
| PHPStan: Cannot access offset on mixed | Safely verified container get array type first. |
| PHPStan: Call to function is_array() evaluate to true | Removed redundant is_array checks where array is inferred. |
