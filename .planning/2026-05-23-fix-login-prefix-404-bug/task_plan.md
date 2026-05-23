# Task Plan: Fix Dynamic Login Prefix 404 Bug

## Goal
Resolve all hardcoded references and redirects to `/login` with dynamic login slug resolution so that setting a custom login prefix (e.g., `/root`) does not result in 404 errors during login form rendering, credentials validation failures, or session redirects.

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
- [x] Create project structure and document plan
- **Status:** complete

### Phase 3: Implementation
- [x] Add `resolveLoginSlug()` helper to `AuthController` and pass `login_url` in both rendering routes
- [x] Add `resolveLoginSlug()` helper to `DashboardController`
- [x] Replace all hardcoded redirects to `/login` with the resolved login slug in `AuthController` and `DashboardController`
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run test suite to verify no regressions
- [x] Manually verify logic against the resolved login slug
- **Status:** complete

### Phase 5: Delivery
- [x] Document final walkthrough
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use cache-first resolution for the login slug in controllers | Ensures high performance (avoiding DB hits on every request/redirect) while matching middleware behavior. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| PHPUnit ClassIsFinalException: AuthController cannot be doubled | Use PHP Reflection's `newInstanceWithoutConstructor()` to instantiate the final classes instead of mocking them. |
| PHPUnit ClassIsFinalException: SettingsRepository cannot be doubled | Use PHP Reflection's `newInstanceWithoutConstructor()` to instantiate `SettingsRepository` via Reflection as well. |
