# Task Plan: Admin UI for Fee Rules

## Goal
Implement a fully functional administrative CRUD UI to manage transactional fee rules (flat, percentage, and tiered) in OwnPay, complete with multi-brand scoping controls.

## Current Phase
Phase 1: Requirements & Discovery

## Phases

### Phase 1: Requirements & Discovery
- [x] Analyze codebase structure and existing fee calculation engine
- [x] Check TenantScope and BaseRepository features
- [x] Identify routing and permission mapping constraints
- [x] Document discoveries in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define administrative routes and permission mappings
- [x] Outline controller methods and validation requirements
- [x] Design layout integration and sidebar updates
- [x] Create implementation_plan.md and request feedback
- **Status:** complete

### Phase 3: Implementation
- [x] Implement FeeRuleController and register routes
- [x] Add menu links and translation keys
- [x] Create Twig templates for index, create, and edit actions
- [x] Implement JS tier rows editor script
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run PHPUnit tests to verify no regressions
- [x] Run phpstan static analysis
- [x] Perform manual verification in the admin dashboard
- **Status:** complete

### Phase 5: Delivery
- [x] Clean up files and verify all criteria are met
- [x] Finalize walkthrough report and deliver
- **Status:** complete

## Key Questions
1. Should non-superadmin users be allowed to view/manage global rules? (No, they should only see brand-specific rules scoped to their active merchant context)
2. How should tiered rules be edited? (Via a dynamic JS rows editor that serializes to JSON)

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use `settings.view` / `settings.manage` permissions | Fits cleanly with the existing settings-related permissions of roles in the system |
| Implement dynamic JS rows editor | Provides a premium visual experience for configuring complex tiered structures |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
