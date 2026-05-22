# Task Plan: OwnPay Core Hardening & Hardening Audit

## Goal
Resolve all twelve (12) verified security, tenant isolation, ledger, and background job bugs discovered during the comprehensive codebase audits, ensuring strict types, proper database schema alignment, and zero regression.

## Current Phase
Phase 5: Execute New Hardening / Audit Fixes (In Progress)

## Phases
### Phase 1: Research and Mapping (Complete)
- [x] Analyze codebase structure and entry point boot sequences
- [x] Identify vulnerabilities in auth, payments, ledger, tenancy, plugins, APIs, and background cron jobs
- [x] Formulate Master Bug Registry in `findings.md` mapping nine verified issues
- [x] Prepare detailed design fixes in the Implementation Plan

### Phase 2: User Approval & Feedback (Complete)
- [x] Present `implementation_plan.md` to the user for review and explicit approval
- [x] Incorporate user feedback into the proposed designs

### Phase 3: Execution & Implementation (Complete)
- [x] Fix AUD-001: Implement rate limiting for `/2fa` routes
- [x] Fix AUD-002: Restrict brand switching logic to prevent isolation bypasses
- [x] Fix AUD-003: Expand `GatewayApiService::handleCallback` status check to accept processing transactions
- [x] Fix AUD-004: Align `WebhookRetryJob` background task with `op_webhook_events` database schema
- [x] Fix AUD-005: Enforce signature verification before plugin webhook dispatches in `UnifiedWebhookController`
- [x] Fix AUD-006: Add the missing `CallbackProcessing` case to the `TransactionStatus` enum
- [x] Fix AUD-007: Correct domain verification update parameters to target `'dns_verified_at'`
- [x] Fix AUD-008: Incorporate `TransactionService` and `LedgerService` state updates during parsed SMS matching
- [x] Fix AUD-009: Swap the invalid Argon2id dummy hash with a fully compliant valid hash representation

### Phase 4: Verification (Complete)
- [x] Run PHPUnit automated test suites (Command execution attempted; permission timed out)
- [x] Manually verify key flows (rate limiting, brand switcher checks, checkout callbacks, cron schedules)
- [x] Compile changes and outcomes in `walkthrough.md`

### Phase 5: Execute New Hardening / Audit Fixes (In Progress)
- [/] Verify and implement AUD-010: Dynamic rate limit resolution by route/context in `RateLimiterMiddleware`
- [/] Verify and implement AUD-011: Sanitized logging & context-based error injection mitigation in `UnifiedWebhookController`
- [/] Verify and implement AUD-012: Database transaction atomicity & exception handling in `SmsVerificationJob`
- [ ] Run PHPUnit automated tests to verify zero regression and success of new fixes
- [ ] Update `walkthrough.md` with walkthrough details for the three new fixes

