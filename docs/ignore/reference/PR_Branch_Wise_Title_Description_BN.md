# Branch-wise PR Titles & Descriptions

এই ফাইলটি branch অনুযায়ী PR title এবং PR description সংরক্ষণ করার জন্য।
প্রজেক্ট শেষ না হওয়া পর্যন্ত প্রতি branch-এর জন্য নতুন section যোগ করা হবে।

## Branch: `DB-Schema/Fintech-Enterprise-Level-Scheme-v1`

### PR Title (Recommended)

`feat(fintech): ship enterprise-grade schema v1 with strict payment/auth/webhook hardening`

### PR Description

## Summary

This PR delivers the Fintech Enterprise Schema v1 cutover for a greenfield release, including database hardening, strict API authentication, payment initiation centralization, transaction state synchronization, webhook security enforcement, and installer preflight validation.

## Why This Change Was Needed

The legacy flow had structural limitations for production fintech requirements:

- Missing enterprise-grade financial tables and integrity constraints.
- Insufficient idempotency and webhook deduplication controls.
- Inconsistent transaction state updates across code paths.
- API key lifecycle inconsistencies and delete failure edge-cases.

This PR addresses those gaps with a strict, production-oriented architecture.

## Scope of Changes

### 1) Database Schema Hardening (Fintech v1)

- Added full fintech core tables in installer `db.sql`:
  - `idempotency_keys`
  - `payment_intents`
  - `payment_attempts`
  - `ledger_journal`
  - `ledger_entries`
  - `webhook_events`
  - `audit_logs`
  - `reconciliation_runs`
  - `reconciliation_items`
  - `api_keys`
- Added enterprise constraints:
  - Unique keys for idempotency/webhook/api-hash/journal references.
  - Foreign keys across payment, ledger, reconciliation, and API key graphs.
  - SQL-1101-safe defaults (no invalid text/blob/json defaults).
- Result: schema is now integrity-first and cutover-ready.

### 2) Installer Preflight Validation + Human-Friendly Warning UI

- Embedded preflight checks directly into installer import flow.
- Added blocking checks for required tables/indexes/FKs.
- Added warning-level readiness checks (non-blocking go-live hints).
- Fixed prefix-aware object checks for non-`pp_` table prefixes.
- Improved webhook secret readiness detection to support scope-aware env rows.
- Added installer UI rendering for preflight warnings with human-friendly labels and details.

### 3) Strict API Authentication & API Key Lifecycle

- Header contract kept unchanged: `MHS-OwnPay-API-KEY`.
- Authentication flow switched to hash-first lookup via `api_keys`.
- Added/reinforced repository and helper paths for hashed key lifecycle.
- API key create/edit/delete/bulk-delete flows synchronized with hashed registry.
- Improved delete guard/orphan verification and failure diagnostics.
- Admin list UX supports show/hide/copy of API key values.

### 4) Payment Initiation Centralization + Idempotency

- Centralized payment creation via `pp_initiate_payment()` / `PaymentService`.
- Removed direct legacy transaction-creation paths from updated API/payment flows.
- Added idempotency acquisition/replay/conflict handling via `idempotency_keys`.
- Improved API error mapping:
  - `409 IDEMPOTENCY_CONFLICT` for true idempotency conflicts.
  - `503 FINTECH_SCHEMA_NOT_READY` when schema guard fails.
  - `500 PAYMENT_INIT_FAILED` for generic runtime failures.

### 5) Transaction State Machine + Fintech Sync

- Added explicit transition validator and centralized status transition function.
- Bound critical state transitions to fintech sync:
  - Payment intent status sync.
  - Payment attempt timeline inserts.
  - Ledger posting (double-entry).
  - Audit trail events.
- Enforced finite transition model across updated admin/system paths.

### 6) Webhook Security Hardening

- Added webhook signature verification service support.
- Added event timestamp extraction/validation (age + clock-skew control).
- Added webhook ingest dedupe using `webhook_events`.
- Added optional/mandatory signature config with env-driven settings.
- Added webhook security management fields in admin API settings flow.

### 7) Documentation & Release Governance

- Added release checklist and rollback SOP:
  - `Fintech_Release_Checklist_and_Rollback_SOP_BN.md`
- Updated migration task list status and progress markers.
- Aligned rollout wording with greenfield/no-legacy-path policy.

## New Components Added

- Repositories:
  - `BaseRepository`
  - `ApiKeyRepository`
  - `IdempotencyRepository`
  - `PaymentRepository`
  - `LedgerRepository`
  - `WebhookEventRepository`
- Services:
  - `PaymentService`
  - `IdempotencyService`
  - `LedgerService`
  - `WebhookService`
- Audit:
  - `AuditLogger`

## Data & Migration Policy

- This branch follows a greenfield policy:
  - Fresh DB import.
  - No legacy DB migration/backfill requirement.
  - No compatibility fallback path kept for old schema behavior.

## Risk Control & Mitigation

- Strict schema readiness guard prevents partial fintech runtime.
- Preflight catches structural issues before installer completion.
- Idempotency and webhook dedupe reduce duplicate financial side effects.
- Central state transition and ledger posting reduce reconciliation drift.

## Validation Performed

- Syntax linting on changed PHP files.
- Installer import + preflight warning rendering flow validation.

## Rollout Notes

- Recommended rollout:
  - Fresh install -> preflight pass -> admin bootstrap -> API key setup -> webhook secret config -> go-live.
- If issue occurs:
  - Use rollback SOP in `Fintech_Release_Checklist_and_Rollback_SOP_BN.md`.

---

## Branch: `QA/WS6-Release-Safety-Automation`

### PR Title (Recommended) - WS-6 Release Safety

`test(release): add WS-6 production smoke automation and release evidence reporting`

### PR Description - WS-6 Release Safety

## Summary

This PR implements WS-6 release safety automation as a dedicated QA branch, introducing scripted smoke tests and machine-readable reporting to validate production-critical fintech behaviors before go-live.

## Why This Change Was Needed

- Manual pre-release checks are error-prone and non-repeatable.
- Critical runtime guarantees (idempotency, webhook dedupe, ledger balancing, rollback safety) need automated validation.
- Release decisions require clear PASS/FAIL evidence.

## Scope of Changes

### 1) Automated Smoke Runner

- Added CLI smoke runner:
  - `qa/release_smoke/run.php`
- Supports:
  - default cleanup mode
  - `--no-cleanup`
  - `--verbose`
  - `--report=<path>`

### 2) WS-6 Coverage Implemented

- `IDEMPOTENCY_REPLAY`
  - Same payload/key replay behavior verification.
  - Conflict behavior verification for payload mismatch.
- `DUPLICATE_WEBHOOK`
  - Signature validation and duplicate ingest dedupe check.
- `LEDGER_INVARIANT`
  - Journal-level debit=credit invariant check.
- `ROLLBACK_PARTIAL_FAILURE`
  - Partial failure path confirms no unintended persisted transaction row.

### 3) Evidence & Traceability

- Added JSON report output:
  - default: `qa/release_smoke/reports/latest.json`
- Enables repeatable release gate evidence for PR/release approvals.

### 4) Documentation

- Added usage and safety docs:
  - `qa/release_smoke/README.md`

## Validation Performed

- Runner syntax validation passed.
- Smoke suite executed successfully with PASS report.

## Operational Notes

- Recommended target: staging/pre-production DB.
- Keep `qa/` tooling in source repo for team-wide repeatability.
- Optionally exclude `qa/` from production deployment artifact.

---

## Branch: `<NEXT_BRANCH_NAME>`

### PR Title (Recommended)

`<TO_BE_FILLED>`

### PR Description

`<TO_BE_FILLED>`
