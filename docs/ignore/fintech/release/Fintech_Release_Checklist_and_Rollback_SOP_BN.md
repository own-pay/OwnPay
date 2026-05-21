# Fintech Release Checklist + Rollback SOP (Greenfield)

তারিখ: 17 February 2026  
প্রজেক্ট: OwnPay (Greenfield fintech release)

## 1) Release Gate Checklist (Go/No-Go)

`GO` দেওয়ার আগে নিচের সব পয়েন্ট `PASS` হতে হবে।

1. Installer import `success` এবং preflight-এ কোনো `blocking` failure নেই।
2. Required fintech tables present:
   - `pp_idempotency_keys`
   - `pp_payment_intents`
   - `pp_payment_attempts`
   - `pp_ledger_journal`
   - `pp_ledger_entries`
   - `pp_webhook_events`
   - `pp_audit_logs`
   - `pp_reconciliation_runs`
   - `pp_reconciliation_items`
   - `pp_api_keys`
3. API auth header check pass: `MHS-OwnPay-API-KEY`.
4. Admin থেকে কমপক্ষে 1টি active API key তৈরি করা হয়েছে।
5. `api/checkout/redirect` এবং `api/checkout/popup` end-to-end payment-init smoke pass।
6. Idempotency check:
   - same payload + same `Idempotency-Key` => replay response
   - different payload + same key => `409 IDEMPOTENCY_CONFLICT`
7. Invoice webhook check:
   - `X-OwnPay-SIGNATURE` verify pass/fail expected behavior
   - duplicate event ingest হলে second call idempotent `200 OK`
8. State transition validator check:
   - `initiated -> pending/completed/canceled` pass
   - invalid transition (example `refunded -> completed`) blocked
9. Ledger invariant quick check:
   - প্রতিটি `ledger_journal` এ sum(debit) == sum(credit)
10. Audit trail check:
    - API key create/delete event logged
    - transaction state sync event logged

## 2) Production Deployment Sequence

1. Backup current code snapshot (git tag + artifact).
2. Fresh DB create.
3. Installer দিয়ে `db.sql` import.
4. Installer preflight warnings capture.
5. Admin bootstrap complete.
6. API key তৈরি + secure vault-এ store.
7. `invoice-webhook-secret` সেট + merchant webhook receiver config sync.
8. Smoke tests run (checklist section-1)।
9. Traffic allow / go-live.

## 3) Rollback SOP

Rollback trigger example:

- critical payment initiation failure
- auth failure spike
- webhook signature/processing failure causing revenue-impact

### 3.1 Immediate Containment

1. New inbound traffic temporarily pause.
2. Merchant-side retry jobs সাময়িক disable (duplicate pressure কমাতে)।
3. Incident timestamp + failing request نمونه সংগ্রহ।

### 3.2 Technical Rollback Steps

1. Previous stable release artifact deploy।
2. Application cache/session refresh।
3. Health + auth + payment smoke re-run।
4. Traffic ধাপে ধাপে restore।

### 3.3 Data Policy for Greenfield Rollback

1. যদি go-live window-এ real transaction শুরু না হয়ে থাকে:
   - DB drop + clean re-import allowed।
2. যদি real transaction শুরু হয়ে যায়:
   - DB reset করা যাবে না।
   - hotfix forward path follow করতে হবে।

### 3.4 Exit Criteria (Rollback Complete)

1. API auth success rate normal baseline-এ।
2. checkout initiation success rate normal baseline-এ।
3. webhook processing backlog stable/zero।
4. audit log-এ rollback window-র ঘটনাগুলো captured।

## 4) Ownership Matrix

1. Release Commander: final Go/No-Go approval।
2. Backend Owner: API/payment/webhook runtime validation।
3. DBA Owner: schema/preflight/integrity verification।
4. Support Owner: merchant communication + incident updates।
