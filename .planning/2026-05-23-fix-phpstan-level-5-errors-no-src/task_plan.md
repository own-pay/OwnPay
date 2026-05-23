# Task Plan: PHPStan Level 5 Static Analysis (Skipping `src/`)

## Goal
Achieve 100% PHPStan static analysis compliance at Level 5 for all paths (`cli/`, `config/`, and `modules/`) excluding `src/`, with zero runtime regressions in PHPUnit or linters.

## Current Phase
Phase 1

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent
- [x] Run PHPStan analysis and baseline all 113 errors
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define exact approach for categories of errors (curl options, return shapes, fields shape, null coalescing)
- [x] Create implementation plan and review required changes
- **Status:** complete

### Phase 3: Implementation
- [x] Fix configuration files (`config/routes/web.php` & `config/services.php`)
- [x] Fix addons (`modules/addons/`)
- [x] Fix remaining gateways (`modules/gateways/`)
  - [x] Fix `apple-pay` gateway strict comparison (Line 129)
  - [x] Fix `google-pay` gateway strict comparison (Line 129)
  - [x] Fix `now-payments` gateway verify amount return shape (Line 158)
  - [x] Fix `oxapay` gateway verify amount return shape (Line 138)
  - [x] Fix `paypal-checkout` gateway verify amount return shapes (Lines 158, 173, 246)
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run `vendor/bin/phpstan analyse --no-progress` and ensure zero errors
- [x] Run `vendor/bin/phpunit` and ensure all 394 integration tests pass
- [x] Run `npm run lint` and verify frontend syntax sanity
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs
- [x] Document final walkthrough
- [x] Deliver to user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Skip `src/` folder | Handled by path exclusions in `phpstan.neon` per user's explicit request. |
| Map fields `options` from list to associative map | Standardizes gateway fields compatibility with `PluginInterface::fields()` which requires `array<string, string>`. |
| Set `CURLOPT_SSL_VERIFYHOST => 0` | Standardizes cURL option verification hosts to valid integer bounds (expecting `0` or `2` instead of `false`). |
| Empty-string fallbacks in verify() return shapes | Ensures adapter method compatibility where return type expects string but receives null or missing offset on error. |

## Errors Encountered
| Error | Resolution |
|-------|------------|

