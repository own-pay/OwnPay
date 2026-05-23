# Task Plan: Fresh Security Audit & Remediations

## Goal
Conduct a fresh security audit of the OwnPay codebase, identify remaining vulnerabilities, draft a plan, obtain approval, implement fixes, and verify.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Analyze codebase for remaining vulnerability patterns
- [x] Run composer audit dependency scans
- [x] Document new findings in findings.md
- [x] Create fresh audit_report.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define detailed implementation plan in brain/implementation_plan.md
- [x] Create detailed task checklist in brain/task.md
- **Status:** complete

### Phase 3: Implementation
- [x] Implement SQL sandbox bypass check in EventManager.php and Database.php
- [x] Implement manual redirect loop SSRF check in HttpClient.php
- [x] Implement referer validation in BrandController.php
- [x] Implement Webhook Tester URL validation in DeveloperController.php
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Add new test cases to tests/Security/SecurityRemediationTest.php
- [x] Run PHPUnit test suite to confirm everything passes
- [x] Document validation in brain/walkthrough.md
- **Status:** complete

### Phase 5: Delivery
- [/] Present completion summary to the user
- **Status:** in_progress

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Validate hook SQL in EventManager | Mitigates supply-chain SQL injection bypasses before queries reach the database handler. |
| Implement manual redirect loops in HttpClient | Resolves redirect-based SSRF exploits without relying on insecure native cURL redirection. |
| Extract path from referer header | Prevents open redirect attacks by ensuring redirects are relative and restricted to the admin panel. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| PHPUnit ClassIsFinalException mocking final classes | Replaced final class mocks in unit tests with direct instantiations of the classes using mocked non-final dependencies (Database/PDO). |
| Brand switch test permission block | Set $_SESSION['is_superadmin'] = true in switchBrand test to allow passing authorization checks. |
