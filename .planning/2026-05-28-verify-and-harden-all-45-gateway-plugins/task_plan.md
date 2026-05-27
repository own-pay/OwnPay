# Task Plan: Verify and Harden All 45 Gateway Plugins

## Goal
Refactor and harden all 45 payment gateway plugins to achieve 100% PHPStan Level 9 type safety compliance and guarantee bug-free, warning-free integration with the core OwnPay system, following a manual file-by-file refactoring strategy.

## Current Phase
Phase 4: Delivery & Documentation

## Phases

### Phase 1: Discovery & Analysis
- [x] Initialize planning session and register the active plan.
- [x] Run PHPStan in JSON output mode to identify all type-safety violations across all gateway modules.
- [x] Group and categorize the typical PHPStan violations (mixed offsets, cast errors, parameter mismatches).
- **Status:** complete

### Phase 2: Systematic Refactoring
- [x] Manually refactor files with 1-2 errors (17 files).
- [x] Manually refactor files with 3-6 errors (8 files).
- [x] Manually refactor files with 8-12 errors (9 files).
- [x] Manually refactor files with 13-18 errors (3 files: Eps, Aamarpay, Shurjopay).
- [x] Manually refactor the final 13 high-profile modules (Dana, GCash, Ovo, Pix, Flutterwave, Bancontact, iDEAL, Mollie, PhonePe, Paystack, Upay, Adyen, Worldline).
- **Status:** complete

### Phase 3: Verification & Hardening
- [x] Run PHPStan Level 9 analysis again to verify that all errors are fully resolved.
- [x] Run the PHPUnit test suite to ensure that no regressions were introduced.
- [x] Perform syntax checks using `php -l` on all files.
- **Status:** complete

### Phase 4: Delivery & Documentation
- [x] Update `walkthrough.md` with verification results.
- [x] Re-attest the plan hashes to finish the session.
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Full Level 9 Hardening | To match the high-security and quality standards of the OwnPay core codebase, all gateway modules must compile warning-free under PHPStan Level 9. |
| Manual Refactoring | User requested manual refactoring file-by-file to ensure bespoke type handling for each payment gateway. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| non-nullable array offset check in initiate | Removed `?? null` checking for `$params` fields since they are guaranteed to exist as strings by the interface signature. |
