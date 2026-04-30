# Fintech Target Schema v1 + SQL 1101 Error Fix Report (বাংলা)

**প্রজেক্ট:** OwnPay  
**তারিখ:** 17 February 2026  
**রিপোর্ট ধরন:** Database Architecture Blueprint + Import Error RCA/Fix  

---

## 1) Executive Summary

এই রিপোর্টে ২টি বিষয় একসাথে কভার করা হয়েছে:

1. **Fintech-grade target schema v1** (FK, unique, idempotency, ledger, audit, encryption-ready design)  
2. **SQLSTATE[42000] / Error 1101**: `BLOB, TEXT, GEOMETRY or JSON column 'name' can't have a default value` এর কারণ, প্রভাব এবং ফিক্স

বর্তমান স্কিমা operational হলেও enterprise fintech-compliance (data integrity, auditability, reconciliation, anti-duplication) স্তরে এখনও গ্যাপ আছে। পাশাপাশি `TEXT ... DEFAULT ...` declaration থাকার কারণে MariaDB/MySQL import ভেঙে যাচ্ছিল।

---

## 2) Error Detail: `name can't have a default value`

### 2.1 Error

`SQLSTATE[42000]: Syntax error or access violation: 1101 BLOB, TEXT, GEOMETRY or JSON column 'name' can't have a default value`

### 2.2 Root Cause

MariaDB/MySQL-এ `TEXT/BLOB/JSON/GEOMETRY` টাইপ কলামে literal `DEFAULT` value allow করা হয় না (engine/version নির্ভর কিছু ব্যতিক্রম ছাড়া production-এ unsafe/inconsistent)।

### 2.3 কীভাবে trigger হয়েছে

`db_bk.sql`-এ একাধিক জায়গায় এই pattern ছিল:

```sql
`name` text NOT NULL DEFAULT '--'
```

Import parser `CREATE TABLE` execution phase-এ fail করে, ফলে পরের migration/seed block execute হয় না।

### 2.4 Fix Applied

`TEXT NOT NULL DEFAULT ...` declarations remove করা হয়েছে এবং column-গুলো `TEXT NOT NULL` রাখা হয়েছে।

উদাহরণ:

```sql
-- Before
`name` text NOT NULL DEFAULT '--',

-- After
`name` text NOT NULL,
```

### 2.5 অতিরিক্ত গুরুত্বপূর্ণ নোট

`DEFAULT '--'` sentinel value schema-level anti-pattern। এর বদলে:

1. nullable column + app fallback  
2. proper domain টাইপ (`varchar`) + sane default  
3. validation layer-এ required check

---

## 3) Error Impact Analysis

1. Fresh install/restore ভেঙে যায়  
2. CI/CD database bootstrap fail হতে পারে  
3. Partial schema creation হলে runtime logic mismatch তৈরি হয়  
4. False application bug impression তৈরি হয় (আসলে root cause DB DDL failure)

---

## 4) Fintech-Grade Target Schema v1 (Blueprint)

## 4.1 Design Goals

1. **Immutability for money movement**  
2. **Strong referential integrity**  
3. **Idempotent payment operations**  
4. **Full audit trail + forensic traceability**  
5. **Encryption-ready sensitive data model**  
6. **Reconciliation-first reporting**

## 4.2 Core Target Tables (recommended)

1. `accounts` (customer/merchant/system wallets)  
2. `ledger_journal` (one business event = one journal header)  
3. `ledger_entries` (double-entry debit/credit rows, immutable)  
4. `payment_intents` (initiation স্তর)  
5. `payment_attempts` (gateway interaction timeline)  
6. `transactions` (business view, derived/current status)  
7. `idempotency_keys` (request replay prevention)  
8. `webhook_events` (ingested signed events, dedupe hash)  
9. `audit_logs` (who/what/when পরিবর্তন)  
10. `reconciliation_runs` + `reconciliation_items`

## 4.3 Mandatory Constraints

1. Foreign keys with explicit `ON DELETE/ON UPDATE` policy  
2. Unique keys:
   - `transactions.ref`
   - `transactions.trx_id` (nullable unique where supported)
   - `payment_intents.idempotency_key`
   - `api_keys.key_hash`
3. Check constraints:
   - `amount >= 0`
   - `currency` ISO whitelist
   - `status` finite state transitions
4. Created/updated timestamps as `DATETIME(6)` or `TIMESTAMP` (UTC)

## 4.4 Idempotency Model (minimum)

```sql
CREATE TABLE idempotency_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(64) NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  response_code INT NULL,
  response_body JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_scope_key (scope, idempotency_key)
) ENGINE=InnoDB;
```

## 4.5 Double-Entry Ledger Skeleton

```sql
CREATE TABLE ledger_journal (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(50) NOT NULL,
  external_ref VARCHAR(64) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_event_ref (event_type, external_ref)
) ENGINE=InnoDB;

CREATE TABLE ledger_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  entry_type ENUM('debit','credit') NOT NULL,
  amount DECIMAL(20,8) NOT NULL,
  currency CHAR(3) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_entries_journal FOREIGN KEY (journal_id) REFERENCES ledger_journal(id),
  CHECK (amount > 0)
) ENGINE=InnoDB;
```

## 4.6 Audit + Tamper Evidence

```sql
CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_type VARCHAR(20) NOT NULL,
  actor_id VARCHAR(64) NOT NULL,
  action VARCHAR(100) NOT NULL,
  entity_name VARCHAR(80) NOT NULL,
  entity_id VARCHAR(80) NOT NULL,
  before_state JSON NULL,
  after_state JSON NULL,
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_entity_time (entity_name, entity_id, created_at)
) ENGINE=InnoDB;
```

---

## 5) Current-to-Target Gap Map (Short)

1. **FK নেই** → orphan data risk  
2. **Unique constraints দুর্বল** → duplicate/ref replay risk  
3. **Date fields varchar/text** → ordering/reporting inconsistency  
4. **Sentinel `'--'` pattern** → semantic ambiguity  
5. **Single-row transaction model** → full accounting trace missing  
6. **Encrypted-at-rest column strategy নেই** → compliance risk

---

## 6) Migration Strategy (Phased, Safe)

## Phase 1: Stability

1. `TEXT ... DEFAULT` issue পুরোপুরি cleanup  
2. DATETIME columns introduce (`*_at`) parallel ভাবে  
3. `ref`, `api_key`, critical identifiers-এ unique key rollout (dedupe cleanup পরে)

## Phase 2: Integrity

1. FK add with data backfill  
2. idempotency table + request hash enforcement  
3. webhook dedupe (`event_id`/`signature_hash`) unique constraint

## Phase 3: Fintech Accounting

1. ledger tables introduce  
2. all money movement write path ledger-first করা  
3. reconciliation job + mismatch alerting

## Phase 4: Compliance Hardening

1. field-level encryption/tokenization for PII/API secrets  
2. immutable audit pipeline  
3. retention + archival policy

---

## 7) `name` Error-এর Final Action Checklist

1. `pp-content/pp-install/db_bk.sql` import-ready কিনা verify  
2. একই pattern (`TEXT/BLOB/JSON DEFAULT`) অন্য SQL dump-এ আছে কিনা স্ক্যান  
3. fresh database-এ clean import run  
4. post-import smoke test:
   - invoice create
   - payment-link init
   - transaction create
5. CI pre-check script add: invalid DDL pattern detect করে fail

---

## 8) Suggested CI Guard (Regex Check)

```bash
rg -n "(?i)(text|blob|json|geometry)\\s+NOT\\s+NULL\\s+DEFAULT" pp-content/pp-install/*.sql
```

যদি output আসে, build fail করা উচিত।

---

## 9) Conclusion

`SQL 1101` issue schema DDL-level incompatibility থেকে এসেছে এবং এটি fix না করলে reliable deployment সম্ভব না।  
এখন immediate blocker remove হয়েছে; পরবর্তী ধাপে target schema v1 অনুযায়ী integrity + ledger + idempotency rollout করলে system fintech enterprise-grade readiness-এর দিকে যাবে।
