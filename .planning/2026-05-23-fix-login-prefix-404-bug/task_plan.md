# Task Plan: Fix Dynamic Login Prefix 404 Bug

## Goal
Resolve all hardcoded references and redirects to `/login` with dynamic login slug resolution so that setting a custom login prefix (e.g., `/root`) does not result in 404 errors during login form rendering, credentials validation failures, or session redirects.

## Current Phase
Phase 2: Planning & Structure

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent
- [x] Identify constraints
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define approach
- [x] Create project structure and document plan
- **Status:** complete

### Phase 3: Implementation
- [ ] Add `resolveLoginSlug()` helper to `AuthController` and pass `login_url` in both rendering routes
- [ ] Add `resolveLoginSlug()` helper to `DashboardController`
- [ ] Replace all hardcoded redirects to `/login` with the resolved login slug in `AuthController` and `DashboardController`
- **Status:** pending

### Phase 4: Testing & Verification
- [ ] Run test suite to verify no regressions
- [ ] Manually verify logic against the resolved login slug
- **Status:** pending

### Phase 5: Delivery
- [ ] Document final walkthrough
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use cache-first resolution for the login slug in controllers | Ensures high performance (avoiding DB hits on every request/redirect) while matching middleware behavior. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
