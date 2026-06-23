# Task Plan: Fix Installer cPanel Network Error

## Goal
Make the installer robust on cPanel by pre-emptively checking for Argon2id support at Step 1 and reporting actual server errors rather than masking them as network errors at Steps 2, 3, and 4.

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
- [ ] Create implementation plan artifact and get approval
- **Status:** in_progress

### Phase 3: Implementation
- [ ] Add Argon2id check to `InstallerController::checkRequirements`
- [ ] Improve JavaScript fetch error handling in `templates/install/step2.php`
- [ ] Improve JavaScript fetch error handling in `templates/install/step3.php`
- [ ] Improve JavaScript fetch error handling in `templates/install/step4.php`
- **Status:** pending

### Phase 4: Testing & Verification
- [x] Verify installer requirements check runs correctly
- [x] Run PHPUnit tests to verify no regressions in InstallerController
- [x] Run static analysis via PHPStan
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs
- [x] Deliver to user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Add Argon2id check to Step 1 | Argon2id is a strict security requirement of the gateway, so we must check for it in the requirements phase. |
| Fallback to parsing text on JSON parse failure | Exposes the actual server/PHP errors, allowing the user to debug issues like missing DB privileges or PHP extensions. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
