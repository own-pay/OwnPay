# Task Plan: Secure Cross-Tenant Transaction Matching

## Goal
Harden cross-tenant fallback matching inside the SMS verification engine to enforce strict temporal, uniqueness, and reference constraints, preventing any potential cross-merchant payment leakage.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent
- [x] Identify constraints
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define precise time-window boundaries (e.g. max 30-minute deviation)
- [x] Incorporate safety check to abort global matching if multiple pending records overlap (Ambiguity Protection)
- [x] Scan codebase for other global/unscoped matching operations that could bypass `TenantScope`
- **Status:** complete

### Phase 3: Implementation
- [x] Update `TransactionRepository::findPendingMatchGlobal` signature and query constraints
- [x] Refactor `SmsVerificationJob::run` fallback execution logic to pass SMS received/created timestamp
- [x] Attest the new plan
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Execute `phpunit` tests to verify zero regressions
- [x] Execute `phpstan` to guarantee Level 9 strict type-safety
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs
- [x] Deliver clear walkthrough of the fix to the user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Apply tight created_at window | Prevents matching obsolete pending transactions from hours/days ago |
| Enforce ambiguity check | Aborts global match if more than one pending transaction shares the same amount + gateway in the time window |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| Mixed to int type casting | Used is_scalar type guard before casting fetchColumn result to integer |
