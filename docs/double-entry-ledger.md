---
trigger: always_on
---

# Double-Entry Ledger Bookkeeping Rules

## 1. Core Financial Integrity & Balancing
- Every financial movement within OwnPay MUST use double-entry bookkeeping recorded across the `op_ledger_accounts`, `op_ledger_transactions`, and `op_ledger_entries` tables.
- **Journal Balance Constraint**: Every ledger transaction MUST consist of one or more debits (DR) and one or more credits (CR) that balance exactly. The sum of debits MUST equal the sum of credits. A transaction with unbalanced entries MUST be rejected and the database transaction rolled back.
- **Asset/Liability account separation**: Liability accounts (e.g. `MERCHANT_PAYABLE`) and Asset/Revenue/Expense accounts must be separated properly, using the correct accounting directions.

## 2. Account Directionality (GAAP Compliance)
All account balances and entries MUST adjust strictly according to standard GAAP accounting rules. AI agents must apply balance changes according to the account type:
- **Asset** and **Expense** Accounts:
  - Debit (DR) increases the balance (+).
  - Credit (CR) decreases the balance (-).
- **Liability**, **Equity**, and **Revenue** Accounts:
  - Credit (CR) increases the balance (+).
  - Debit (DR) decreases the balance (-).

## 3. Brand/Merchant Scoping
- Ledger accounts MUST be strictly isolated by the brand (merchant) ID to prevent cross-brand financial leakage.
- Always retrieve or initialize accounts via `LedgerRepository::findOrCreateAccount($name, $type, $currency, $merchantId)`. Never query accounts globally without scoping by both `merchant_id` and `currency`.

## 4. Concurrency & Race Condition Prevention
- Posting ledger transactions MUST execute within a database transaction block (`db()->beginTransaction()`).
- Explicit locks (e.g. `SELECT ... FOR UPDATE` or application-level mutex/idempotency keys) MUST be used on involved ledger accounts during updates to prevent double-posting, race conditions, or out-of-order balance calculations.

## 5. Scoped Repository & Service Cloning
- Calling `TenantScope::forTenant($merchantId)` on a repository or service (like `LedgerService` or `LedgerRepository`) returns a **cloned instance** with the specified tenant scope.
- The original repository/service instance retains its previous/default scope.
- **Mandatory Return Capture**: Inside ledger transactions or callbacks, you MUST capture and use the returned clone (e.g. `$scopedLedger = $this->ledger->forTenant($mid);`). Do not rely on the original instance.
