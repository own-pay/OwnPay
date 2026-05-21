# Fintech Schema Migration: Codebase Impact Guide (বাংলা)

**প্রজেক্ট:** OwnPay  
**উদ্দেশ্য:** `Fintech-grade target schema v1` এ যেতে কোডবেসে কোথায় কী পরিবর্তন লাগবে, কেন লাগবে, কী পরিবর্তন করতে হবে, কীভাবে করতে হবে  
**তারিখ:** 17 February 2026

---

## 1) Executive Summary

হ্যাঁ, এই schema plan অনুযায়ী যেতে **শুধু DB migration নয়**, application code-এও উল্লেখযোগ্য পরিবর্তন লাগবে।  
প্রধান কারণ:

1. Existing helper/API layer dynamic SQL + loose constraints ভিত্তিক  
2. New design-এ FK, unique, idempotency, ledger, audit, encryption-ready model enforce হবে  
3. Money flow একক `pp_transaction` row থেকে **event/ledger-driven** model-এ যাবে

---

## 2) High-Level Change Map (Where / Why / What / How)

## A) Database Access Layer

**Where:** `pp-content/pp-include/pp-functions.php:242`, `pp-content/pp-include/pp-functions.php:274`, `pp-content/pp-include/pp-functions.php:304`  
**Why:** `getData/updateData/deleteData` condition string-based; FK/unique/idempotency modelে safe query contract দরকার  
**What পরিবর্তন:** repository/service layer introduce, strict parameterized query policy  
**How:**

1. table-specific repository class (`TransactionRepository`, `InvoiceRepository`)  
2. raw condition string remove  
3. query builder pattern বা prepared statement only contract enforce  
4. write operations-এ transaction boundary (`BEGIN/COMMIT/ROLLBACK`)

---

## B) Payment Initialization Flow

**Where:** `pp-content/pp-include/pp-adapter.php:8655`, `pp-content/pp-include/pp-adapter.php:8743`, `index.php:381`, `index.php:601`, `index.php:739`  
**Why:** এখন single insert into `pp_transaction`; target schema-এ `payment_intents`, `payment_attempts`, `idempotency_keys` লাগবে  
**What পরিবর্তন:**

1. `create payment` -> intent create  
2. idempotency key যাচাই  
3. attempt record  
4. successful state transition + transaction snapshot

**How:**

1. `PaymentService::initiate()` বানান  
2. প্রথমে `idempotency_keys` lookup/insert  
3. `payment_intents` row create  
4. response reuse (same idempotency key এ duplicate create না করা)  

---

## C) Transaction Status Update + Webhook

**Where:** `index.php:1126`, `pp-content/pp-include/pp-adapter.php:9047`, `pp-content/pp-include/pp-adapter.php:9134`, `pp-content/pp-include/pp-adapter.php:9289`  
**Why:** Webhook spoof/replay + duplicate processing রোধে event-level dedupe/signature দরকার  
**What পরিবর্তন:**

1. inbound webhook signature verification  
2. `webhook_events` table (unique provider event id/signature hash)  
3. status update idempotent handler  
4. audit log entry বাধ্যতামূলক

**How:**

1. `WebhookService::ingest()`  
2. verify signature + timestamp  
3. duplicate event হলে ignore (`200` + no reprocess)  
4. valid event হলে payment attempt + transaction state machine update

---

## D) Ledger Posting (New Core)

**Where:** current flowে scattered amount update, যেমন `pp-content/pp-include/pp-adapter.php:9151`, `pp-content/pp-include/pp-adapter.php:9180`, `pp-content/pp-include/pp-adapter.php:9241`  
**Why:** fintech-grade accounting-এ immutable double-entry দরকার  
**What পরিবর্তন:**

1. direct balance অর্থবহ field update কমিয়ে ledger entry first  
2. debit/credit pair enforce  
3. refunds/chargeback event-based reverse entries

**How:**

1. `LedgerService::postJournal(eventType, ref, lines[])`  
2. transaction status change-এর সাথে ledger post transactionally tie  
3. reconciliation job দিয়ে ledger sum বনাম transaction summary মিলানো

---

## E) API Authentication + Key Model

**Where:** `index.php:337`, `index.php:341`, `pp-content/pp-install/db_bk.sql` (`pp_api.api_key`)  
**Why:** plaintext API key storage enterprise-grade না  
**What পরিবর্তন:**

1. `api_keys` table-এ hashed key (`key_hash`)  
2. one-time visible raw token  
3. scopes normalized table

**How:**

1. create key -> raw token userকে একবার দেখান  
2. DB-এ শুধু hash রাখুন  
3. request এ presented key hash করে compare করুন

---

## F) Customer / PII / Encryption-Ready

**Where:** `pp_transaction.customer_info`, `pp_invoice.customer_info`, `pp_customer.email/mobile` usage across `index.php`/`pp-adapter.php`  
**Why:** PII minimization, masking, encryption, compliance  
**What পরিবর্তন:**

1. structured columns (name/email/mobile আলাদা)  
2. sensitive columns encryption বা tokenization  
3. response serializer-এ masking

**How:**

1. migration এ নতুন columns + backfill  
2. read pathে decrypt/mask helper  
3. logs/webhook payloadে raw PII avoid

---

## G) Date/Time Model

**Where:** DB schemaতে `created_date/updated_date` varchar/text, appে `getCurrentDatetime('Y-m-d H:i:s')` usage  
**Why:** ordering/reporting consistency, timezone-safe analytics  
**What পরিবর্তন:**

1. `created_at`, `updated_at` DATETIME(6) UTC  
2. old columns deprecate  
3. reporting query update

**How:**

1. dual-write phase (old + new)  
2. read switch feature flag  
3. পরে legacy columns drop

---

## H) Query & Index Compatibility

**Where:** filter/list queries যেমন `pp-content/pp-include/pp-adapter.php:3442` ব্লক  
**Why:** new unique/FK/check constraints এ query plan ও join pattern বদলাবে  
**What পরিবর্তন:**

1. joins explicit করা  
2. search/filter input parameterized  
3. new composite indexes aligned with API/list endpoints

**How:**

1. slow query log capture  
2. endpoint-by-endpoint EXPLAIN plan review  
3. index tune + regression test

---

## I) Admin Workflows (Invoice/Payment Link/Gateway)

**Where:** `pp-content/pp-include/pp-adapter.php` admin actions (invoice/payment-link/gateway CRUD blocks)  
**Why:** schema normalization-এ payload shape ও validation rules বদলাবে  
**What পরিবর্তন:**

1. DTO/validator layer add  
2. sentinel `'--'` বাদ  
3. enum transitions strict করা

**How:**

1. request schema define (required/nullable/range)  
2. save আগে sanitize+validate  
3. domain error code return

---

## J) Theme / Frontend Contract

**Where:** `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php`, transaction/payment render paths in `index.php`  
**Why:** response object ও field names normalized হবে  
**What পরিবর্তন:** frontend data contract update  
**How:**

1. API response versioning (`v1`, `v2`)  
2. fallback mapper during migration  
3. deprecated fields release note

---

## 3) New Modules You Should Introduce

1. `pp-content/pp-include/services/PaymentService.php`  
2. `pp-content/pp-include/services/LedgerService.php`  
3. `pp-content/pp-include/services/WebhookService.php`  
4. `pp-content/pp-include/services/IdempotencyService.php`  
5. `pp-content/pp-include/repositories/*Repository.php`  
6. `pp-content/pp-include/audit/AuditLogger.php`

---

## 4) Recommended Migration Sequence (Safe Rollout)

1. **Schema add-only phase**  
   New tables/columns/index যোগ; existing flow untouched
2. **Dual-write phase**  
   পুরনো `pp_transaction` + নতুন `payment_intents/ledger` একসাথে লিখুন
3. **Read switch phase**  
   feature flag দিয়ে report/API নতুন schema থেকে পড়া শুরু
4. **Cutover phase**  
   old write path disable
5. **Cleanup phase**  
   legacy columns/table deprecate/drop

---

## 5) Minimal Code Snippet Direction

```php
// Pseudocode only
$db->beginTransaction();
try {
    $idem = $idempotencyService->acquire($scope, $idemKey, $requestHash);
    if ($idem->isReplay()) { return $idem->cachedResponse(); }

    $intent = $paymentService->createIntent($payload);
    $attempt = $paymentService->createAttempt($intent, $gatewayPayload);

    $ledgerService->postInitiated($intent);
    $audit->log('payment_initiated', $intent->id, $payloadMeta);

    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    throw $e;
}
```

---

## 6) Testing Changes You Must Add

1. idempotency replay test  
2. duplicate webhook event test  
3. ledger balance invariant test (sum(debit)=sum(credit))  
4. refund/partial refund state transition test  
5. migration backfill integrity test  
6. rollback test (failed gateway call এ partial write না থাকা)

---

## 7) Risk যদি কোড পরিবর্তন না করেন

1. DB constraints add করলেই runtime insert/update break  
2. duplicate payment/refund risk থাকবে  
3. reconciliation mismatch বাড়বে  
4. audit/compliance evidence দুর্বল থাকবে

---

## 8) Final Answer to “কোথায় কী কী পরিবর্তন”

সংক্ষেপে, পরিবর্তন লাগবে:

1. DB helper layer  
2. payment init ও verify flow  
3. webhook ingestion  
4. transaction status machine  
5. ledger posting  
6. API key handling  
7. PII serialization/masking  
8. admin CRUD validation  
9. frontend contract mapping  
10. test suite + migration scripts

এইগুলোর কোনোটা বাদ দিলে fintech-grade schema rollout safe হবে না।
