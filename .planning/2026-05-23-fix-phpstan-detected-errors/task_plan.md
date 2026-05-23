# Task Plan: Fix PHPStan level 6 errors in OwnPay

## Goal
Eliminate all 31 PHPStan errors related to "no value type specified in iterable type array" by annotating parameters and return types using correct PHPDoc annotations across 13 files, achieving a completely clean PHPStan static analysis run.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Run initial PHPStan static analysis to gather all errors
- [x] Locate and analyze all 13 affected PHP files
- [x] Document discovery in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Draft global implementation_plan.md artifact
- [x] Establish precise PHPDoc annotations for each affected method signature
- [x] Present the plan to the user for feedback and approval
- **Status:** complete

### Phase 3: Implementation
- [x] Implement PHPDoc annotations in Core component (`Database.php`, `FormattingHelper.php`)
- [x] Implement PHPDoc annotations in Controller component (Base, Brand, Staff, Transaction, Checkout, Installer, Webhook)
- [x] Implement PHPDoc annotations in Service, Repository, and Plugin components (`PluginLoader.php`, `FeeRuleRepository.php`, `LedgerService.php`, `UpdateService.php`)
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run `vendor/bin/phpstan analyse` to verify 0 errors
- [x] Run PHPUnit tests to verify no runtime/type regressions
- [x] Document validation in progress.md and walkthrough.md
- **Status:** complete

### Phase 5: Delivery
- [x] Finalize documentation and sync files
- [x] Present walkthrough to the user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use `array<string, mixed>` / `array<string|int, mixed>` | Standard, widely understood PHPDoc annotations that PHPStan level 6 accepts for generic or dynamic associative arrays. |
| Use highly specific shapes where possible | For shapes like `array{logo_path: string|null, settings: string}`, providing extra safety and self-documenting code. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
