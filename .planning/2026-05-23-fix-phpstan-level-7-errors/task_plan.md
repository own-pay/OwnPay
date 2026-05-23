# Task Plan: Fix PHPStan Level 7 Errors in OwnPay

## Goal
Eliminate all 181 PHPStan errors at Level 7 by adding strict type annotations, standard fallback guards, array existence checks, and updating repository return types, achieving a completely clean PHPStan static analysis run.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Discovery & Research
- [x] Run initial PHPStan Level 7 static analysis to gather all errors
- [x] Document discovery and dump errors into phpstan_errors.txt
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Draft global implementation_plan.md artifact
- [x] Establish precise task checklist in task.md
- [x] Present the plan to the user for feedback and approval
- **Status:** complete

### Phase 3: Implementation
- [x] Implement PHPDoc and return type fixes in Repository layer
- [x] Implement type assertions, guards, and annotations in Service layer
- [x] Implement array key and resource guards in Controller layer
- [x] Implement dynamic instantiation and dynamic call guards in Core component
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run `vendor/bin/phpstan analyse` to verify 0 errors
- [x] Run PHPUnit tests to verify no runtime/type regressions
- [x] Run static checks
- **Status:** complete

### Phase 5: Delivery
- [x] Finalize documentation and sync files
- [x] Present walkthrough to the user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use `array<int, array<string, mixed>>` over `list<...>` | Avoids PHPStan complaining that query results are not guaranteed to be consecutive index lists, matching the database connector directly. |
| Use inline `@var numeric-string` annotations | Safely satisfies BC Math numeric requirements without altering double-entry ledger database schema or runtime values. |
| Remove redundant `is_int` on files error check | The outer `=== UPLOAD_ERR_OK` (0) condition already infers the precise integer type, making sub-checks redundant to PHPStan's type-narrowing. |
| Use `@phpstan-ignore-next-line` for dynamic calls | Permits clean dynamic method invocation (e.g. middleware `handle`, repository `pollSince`) where static proving is impossible. |
