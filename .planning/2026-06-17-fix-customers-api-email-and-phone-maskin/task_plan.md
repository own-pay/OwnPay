# Task Plan: Customer API Unmasked List and Email Identifier Fix

## Goal
Fix `GET /api/v1/customers` to return unmasked email and phone, and fix `GET /api/v1/customers/{identifier}` to dynamically support email and phone path parameters by relaxing router validation for `{identifier}`.

## Current Phase
Phase 1: Requirements & Discovery

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent (unmasked list + dynamic email/phone query)
- [x] Identify constraints (route parameter regex constraints, rule standards)
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Design & Verification of Rules
- [x] Prepare updates to `code-standards-architecture.md` (relaxing route parameter constraint specifically for `{identifier}`)
- [x] Prepare updates to `ARCHITECTURE.md` and `AGENTS.md`
- **Status:** complete

### Phase 3: Implementation
- [x] Modify `CustomerController::index()` to return unmasked `email` and `phone`
- [x] Modify `Router.php` to allow `+`, `@`, and `%` in `{identifier}` route parameter pattern
- [x] Modify `CustomerController::show()` to use `rawurldecode` on `identifier`
- [x] Update rule files and documentation to reflect the updated route parameter constraint
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run PHPUnit tests (`vendor/bin/phpunit`) to ensure no regression
- [x] Run PHPStan analysis (`vendor/bin/phpstan analyse`) to verify strict level 9 type safety
- [x] Write integration test cases or script to test both endpoints (with emails containing `@`, phones containing `+` or `%2B`)
- **Status:** complete

### Phase 5: Delivery (Customer API)
- [x] Finalize plan files and document in walkthrough.md
- [x] Report completion to the user
- **Status:** complete

### Phase 6: Requirements & Discovery (API Keys Security)
- [x] Understand user requirements (X-Super-Admin-Email header, write + admin caller scopes, custom scopes in POST body)
- [x] Identify controller routes for API keys
- **Status:** complete

### Phase 7: Implementation (API Keys Security)
- [x] Implement `enforceSecureAccess()` helper in `ApiKeyController` checking caller key scopes and X-Super-Admin-Email header
- [x] Apply secure access validation to index, generate, and revoke methods in `ApiKeyController`
- [x] Parse and validate custom scopes array in `ApiKeyController::generate()`
- [x] Update standalone premium API tester `public/api-tester.php` to support superadmin email header and scopes parsing
- [x] Parse and validate custom scopes array in admin `ApiKeyController`
- **Status:** complete

### Phase 8: Verification & Delivery (API Keys Security)
- [x] Write unit/integration tests to verify caller scopes and superadmin email header checks
- [x] Write integration tests for admin dashboard key scope generation
- [x] Verify the full PHPUnit suite and PHPStan level 9 status
- [x] Update walkthrough and task artifacts
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Allow `+`, `@`, `%` specifically for `{identifier}` | Supports emails and phones in path parameters without relaxing constraints on other parameters. |
| Use `rawurldecode` in `CustomerController::show` | Safely handles URL-encoded path variables without converting `+` to space. |

## Errors Encountered
| Error | Resolution |
|-------|------------|

