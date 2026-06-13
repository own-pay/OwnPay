# Task Plan: Audit Integrity Investigation

## Goal
Investigate why the audit integrity page shows a "Database Tampering Detected" alert with 13 corrupted audit log entries, find the root cause, and explain the meaning to the user.

## Current Phase
Phase 1: Research & Discovery

## Phases

### Phase 1: Research & Discovery
- [ ] Find the controller handling `/admin/audit-integrity` (or reports/finance audit integrity)
- [ ] Understand how the SHA-256 HMAC checksum is calculated and verified for audit logs
- [ ] Query the database to inspect the audit log table (`op_audit_logs` or similar) and find the 13 corrupted entries
- **Status:** in_progress

### Phase 2: Analysis & Explanation
- [ ] Analyze why the checksums failed (e.g. database edits, missing entries, key mismatch, timezone shifts, null values)
- [ ] Formulate explanation for the user
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|

## Errors Encountered
| Error | Resolution |
|-------|------------|
