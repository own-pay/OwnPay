# Task Plan: Security Remediations

## Goal
Implement security remediations for the 5 vulnerability findings in the approved `audit_report.md` (P1, P2, P2, P3, P4) and verify them using the test suite.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent & approved audit findings
- [x] Identify codebase implementation locations and constraints
- [x] Document findings in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define detailed implementation plan in brain/implementation_plan.md
- [x] Create detailed task checklist in brain/task.md
- **Status:** complete

### Phase 3: Implementation
- [x] Implement ApplePay / GooglePay adapter changes (reject live mocks)
- [x] Implement PluginLoader scanner enhancements (block dynamic code & callback wrappers)
- [x] Implement FilesystemService upload sanitization (block malicious SVG payloads)
- [x] Implement PermissionMiddleware & TwoFactorMiddleware login slug caching
- [x] Remove GET /logout route in web.php
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run PHPUnit to verify that all existing tests continue to pass
- [x] Write dedicated tests for each of the 5 security remediations
- [x] Document validation results in brain/walkthrough.md
- **Status:** complete

### Phase 5: Delivery
- [x] Deliver a summary of the accomplishments to the user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Ban dynamic calls and instantiations in scanner | No plugins in the codebase use dynamic variables or dynamic classes, so blocking them avoids sandbox escape vectors. |
| Use regex-based string rejection for SVGs | Avoids complex XML parser overhead or parser-specific XXE bugs. |
| Align login slug resolution cache in PermissionMiddleware and TwoFactorMiddleware | Unifies caching pattern and eliminates database query load on every request. |

## Errors Encountered
| Error | Resolution |
|-------|------------|

