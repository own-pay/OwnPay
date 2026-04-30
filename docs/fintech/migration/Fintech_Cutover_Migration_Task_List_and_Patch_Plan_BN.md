# Fintech Greenfield Release Task List + Patch Plan (No Legacy Path)

তারিখ: 17 February 2026
প্রজেক্ট: OwnPay
লক্ষ্য: নতুন রিলিজে legacy compatibility path সম্পূর্ণ বাদ দিয়ে full fintech schema ও strict service architecture enforce করা; একদম fresh database import থেকে go-live করা।

## Data Policy (Greenfield)

- Existing production DB upgrade/migration হবে না।
- Historical data backfill হবে না।
- Release হবে clean schema import + fresh operational data দিয়ে।

## Cutover Policy (Hard Rules)

- Legacy API key auth (`pp_api.api_key` raw compare) ব্যবহার করা যাবে না।
- Legacy payment fallback insert path রাখা যাবে না।
- সব payment initiation অবশ্যই `PaymentService` দিয়ে হবে।
- সব critical transaction state change এর পরে `pp_sync_fintech_transaction_state()` বাধ্যতামূলক।
- Hash-based key registry (`pp_api_keys`) ছাড়া কোনো key operational ধরা হবে না।

## Release Scope

- In:
  - DB schema hard enforcement (fintech tables/constraints)
  - API auth strict hashed-key cutover
  - Payment init strict fintech service cutover
  - Transaction state sync (intent/attempt/ledger/audit)
  - Webhook dedupe/signature enforcement path
  - Admin API key lifecycle sync
- Out:
  - পুরনো release compatibility mode
  - public/unauthorized raw key exposure path
  - partial/optional fintech write paths

## Workstream Breakdown

### WS-1: Database Hard Cutover

- [x] SQL 1101 block pattern cleanup (`TEXT...DEFAULT`).
- [x] Fintech v1 core tables add (`pp_idempotency_keys`, `pp_payment_intents`, `pp_payment_attempts`, `pp_ledger_*`, `pp_webhook_events`, `pp_audit_logs`, `pp_reconciliation_*`, `pp_api_keys`).
- [x] Unique/FK/check constraints rollout.
- [x] Fresh import validation preflight (table/constraint/index presence + FK integrity + strict mode smoke checks) installer flow-এ embedded (`pp-content/pp-install/index.php`)।

### WS-2: API Authentication Strict Mode

- [x] Header unchanged রাখা (`MHS-OwnPay-API-KEY`).
- [x] Hash-first auth implementation.
- [x] `ApiKeyRepository` থেকে legacy raw-key fallback remove.
- [x] Admin create/edit/list এ hashed-key sync path enforce.
- [x] Admin delete/bulk-delete এর পর orphan যাচাই ও audit event add (post-delete hook review)।

### WS-3: Payment Initiation Strict Mode

- [x] API checkout redirect/popup -> `pp_initiate_payment()`.
- [x] Invoice/payment-link/payment-link-default -> `pp_initiate_payment()`.
- [x] `pp_initiate_payment()` থেকে legacy fallback remove.
- [x] Fintech schema readiness guard যোগ (`pp_assert_fintech_schema_ready`).
- [x] Gateway callback/other module direct transaction-create path audit ও replace (module-by-module sweep; `insertData($db_prefix.'transaction'...)` legacy call removed, `pp_initiate_payment()`/`PaymentService` only)।

### WS-4: Transaction State Machine + Sync

- [x] `pp_sync_fintech_transaction_state()` introduce.
- [x] Completed/refunded/canceled/pending critical paths এ sync hook bind.
- [x] Ledger posting + audit logging integrate on sync path.
- [x] Explicit finite-state transition validator (initiated->pending/completed/canceled, completed->refunded only) hard-enforce in all manual/admin actions.
- [x] All direct `updateData(...transaction...)` কে centralized state service-এ migrate করে codepath freeze।

### WS-5: Webhook Security Hardening

- [x] Webhook ingest dedupe (`pp_webhook_events`).
- [x] Optional signature verify path.
- [x] Signature mandatory mode env flag + per-brand secret rollout.
- [x] Replay/clock-skew guard + event TTL validation.

### WS-6: Test & Release Gate

- [ ] Syntax lint for changed PHP files. (To be finalized in dedicated WS-6 branch)
- [ ] SQL invalid DDL regex guard pass. (To be finalized in dedicated WS-6 branch)
- [ ] Integration tests (automated smoke suite: `qa/release_smoke/run.php`, latest report: `qa/release_smoke/reports/latest.json`):
  - idempotency replay
  - duplicate webhook
  - ledger debit=credit invariant
  - rollback on partial failure
- [ ] Release checklist + rollback SOP finalization (`Fintech_Release_Checklist_and_Rollback_SOP_BN.md`).

## Patch Plan (Execution Order)

1. Fresh DB import + preflight validation SQL run.
2. Strict auth/payment fallback removals merge.
3. Transaction state centralization pass (remaining direct updates).
4. Webhook mandatory signature mode rollout.
5. End-to-end staging test + production release cutover.

## Current Implementation Status (This Session)

- Completed now:
  - strict hashed-key auth path enabled
  - legacy raw-key fallback removed
  - payment fallback removed
  - fintech schema readiness guard added
  - major transaction sync hooks integrated
  - fresh-import preflight SQL package added
- In progress:
  - WS-6 moved to dedicated branch (`QA/WS6-Release-Safety-Automation`)
- Pending:
  - WS-6 integration run evidence capture in dedicated branch

## Risk Controls

- Deployment blocker: required fintech tables missing থাকলে payment initiation fail হবে (intentional strict behavior)।
- Go-live pre-check mandatory:
  - schema existence
  - unique constraint readiness
  - hashed key creation flow check
  - webhook secret configuration

## Rollout Decision Gate

Release allow only if:

- all WS-1/WS-2/WS-3 items complete,
- WS-4 finite transition validator complete,
- WS-6 integration tests green.
