# Task Plan: Audit Verification and Fixing Plan

## Goal
Verify findings in `docs/v2/audit_findings/ownpay_master_audit_report.md` and create a comprehensive fixing plan for the violated invariant (INV-1) to ensure single super-administrator behavior.

## Current Phase
Phase 3: Verification & Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Analyze `docs/v2/audit_findings/ownpay_master_audit_report.md`
- [x] Investigate database schema for `op_merchant_users` and cascade behaviors
- [x] Verify status of INV-1, INV-2, INV-5
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define the approach to decouple superadmin from specific merchants
- [x] Draft a comprehensive SQL migration plan to make `merchant_id` and `role_id` nullable
- [x] Detail the application code updates required (login, installer, brand switching)
- [x] Create `docs/v2/audit_findings/ownpay_fixing_plan.md` containing the details
- **Status:** complete

### Phase 3: Verification & Delivery
- [x] Run test suite to ensure no regressions
- [x] Verify Twig, JS, CSS quality
- [x] Finalize walkthrough.md and clean up active session
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Create `ownpay_fixing_plan.md` in `docs/v2/audit_findings/` | Follow user request to draft a fixing plan for real findings in the same directory. |

## Errors Encountered
| Error | Resolution |
|-------|------------|


