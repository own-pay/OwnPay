# Task Plan: Upgrade PHPStan to Level 9 & Full Verification

## Goal
Establish strict PHPStan Level 9 static analysis as the codebase testing standard, update the rulesets, and verify all tests pass (PHPStan, PHPUnit, JS, CSS, JSON, and Twig).

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Establish Strict Level 9 Config
- [x] Configure `phpstan.neon` to use Level 9
- [x] Add paths `cli`, `config`, `modules`, and `src` to analysis paths
- [x] Run PHPStan level 9 verification
- **Status:** complete

### Phase 2: Update Developer Rulesets
- [x] Edit `.agents/rules/developer-workflows.md` to enforce strict level 9 static analysis
- **Status:** complete

### Phase 3: PHPUnit Verification
- [x] Run full PHPUnit ledger and business logic test suite
- **Status:** complete

### Phase 4: Frontend & Assets Verification
- [x] Run ESLint on JavaScript files
- [x] Run Stylelint on CSS files
- [x] Run ESLint on JSON files
- [x] Run Twig CS Fixer on Twig templates
- **Status:** complete

### Phase 5: Delivery
- [x] Create unit tests for SecurityHeadersMiddleware and SessionMiddleware
- [x] Run entire test suite (401 tests) and verify success
- [x] Update findings and progress records
- [x] Report final green status to the user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Promote to strict PHPStan Level 9 | Standardizes rigorous static checking on a high-value payment ledger and White-Label platform to eliminate null pointer bugs and invalid type assumptions. |
| Add dedicated middleware tests | Ensures new security reporting and SameSite configs are explicitly validated to prevent regressions. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| None | Entire system was fully ready and type-safe for strict Level 9. |

