# Task Plan: Final Admin UI Audit & Tab Alignment

## Goal
Conduct a comprehensive review of all Admin UI panels, templates, and settings to ensure no GUI components are missing or misaligned, and resolve minor sub-tab conditional rendering bugs.

## Current Phase
Phase 3: Implementation

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent to check all missing GUIs
- [x] Analyze settings index tabs, branding, and domain configurations
- [x] Audit the login attempts sub-tab active classes in activities logs
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Map the required tab logic alignments
- [x] Group linter runs and PHPUnit testing
- **Status:** complete

### Phase 3: Implementation
- [x] Align sub-tab active button classes in `activities.twig`
- [x] Align sub-tab active button classes in `login-attempts.twig`
- [x] Pass `active_subpage` in `LoginAttemptController`
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run full PHPUnit tests to verify zero regressions
- [x] Run PHPStan Level 9 analysis
- [x] Run twig-cs-fixer and ESLint/Stylelint checks
- **Status:** complete

### Phase 5: Delivery
- [x] Verify outputs and file status
- [x] Compile walkthrough.md
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Sub-tab alignment | Correcting the conditional classes in activities and login attempts logs ensures visual consistency and active tab highlighting in the UI. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
