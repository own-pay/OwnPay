# Task Plan: Financial Leakage Risk Concurrency Audit (Part 2)

## Goal
Secure general ledger accounts, payment callbacks, and refund pipelines against concurrency race conditions, double-posting, and duplicate/excess refund capacity bypasses, ensuring all tests pass with 100% success.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Identify concurrency race condition vulnerabilities in Gateway Callback and Refund pipelines.
- [x] Document vulnerability findings in findings.md.
- **Status:** complete

### Phase 2: Design & Planning
- [x] Decide on using exclusive SELECT FOR UPDATE row locking.
- [x] Plan the integration tests in FinancialLeakageAuditTest.
- **Status:** complete

### Phase 3: Implementation & Fixes
- [x] Implement FOR UPDATE row locking inside GatewayApiService.
- [x] Implement FOR UPDATE row locking and transaction boundaries inside RefundService.
- [x] Refactor FinancialLeakageAuditTest to correct FeeService dependencies and avoid destructive database drops.
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run PHPUnit specifically for FinancialLeakageAuditTest.
- [x] Run all system-wide PHPUnit tests (454 tests) to ensure zero regressions.
- [x] Run static analysis (PHPStan Level 9 compliance).
- [x] Run style / syntax lints (npm run lint && composer lint:twig).
- **Status:** complete

### Phase 5: Delivery
- [x] Document final test results and walkthrough.
- [x] Re-lock planning hash via attestation script.
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| **Correct FeeService initialization** | Injected SettingsRepository and FeeRuleRepository alongside EventManager to fix ArgumentCountError. |
| **Avoid global DELETE inside setUp/tearDown** | Scoped cleanups strictly to test-local records (IDs 1001, 1002, 1003) to prevent cascading test failures. |
| **Use Real GatewayBridge with Mocked Adapter** | Bypassed final class double limitation by using the real `GatewayBridge` service and mocking its interface adapters. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| ArgumentCountError on FeeService constructor | Injected correct mock/real dependencies in setup. |
| Shared DB isolation breakage | Scoped table deletions to prevent dropping master records needed by other tests. |
| Final class mocking error | Mocked the `GatewayAdapterInterface` interface and registered it instead of mocking `GatewayBridge`. |
| Missing `slug` and `email` columns on test merchants | Seeding statements updated to include missing columns required by the new sovereign model constraints. |
