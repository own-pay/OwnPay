# Task Plan: Update Developer Endpoints UI

## Goal
Update the hardcoded developer endpoint documentation in `DeveloperController` to accurately reflect all existing public and mobile API routes defined in the codebase.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Identify where endpoints are defined in the UI (`DeveloperController::getEndpointReference`)
- [x] List all API routes defined in `config/routes/`
- [x] Compare and document differences in findings.md
- **Status:** complete

### Phase 2: Design & Planning
- [x] Map out the correct categories and endpoints structure
- [x] Draft the endpoint array structure
- **Status:** complete

### Phase 3: Implementation
- [x] Update `DeveloperController::getEndpointReference` with current endpoints
- [x] Check if `templates/admin/developer/index.twig` needs any formatting changes
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Perform static analysis check using PHPStan
- [x] Run PHPUnit test suite to ensure no regressions
- [x] Verify UI works (and endpoints tab matches expectations)
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs
- [x] Deliver to user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|

## Errors Encountered
| Error | Resolution |
|-------|------------|
