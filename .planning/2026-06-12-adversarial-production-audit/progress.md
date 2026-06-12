# Adversarial Production Audit — Progress

Plan: C:\Users\iamna\.claude\plans\own-pay-peaceful-stearns.md
Baseline (Phase 0, 2026-06-12): HEAD 99f6d6a (branch: fixing), composer test OK (476 tests / 1527 assertions / 1 skipped), phpstan OK (363 files, 0 errors), twig lint OK (79 files clean).

## Phase 1 — Confirmed fixes
- [x] F1 webhook idempotency TOCTOU + completion race (HIGH) — DONE:
  - migration 009 (VIRTUAL dedup_key + uk_inbound_dedup; STORED fails errno 1215 on FK tables in MySQL 9) + schema.sql mirror; applied to ownpay_test
  - WebhookInboundProcessor: INSERT-first dedup (catch 1062), FOR UPDATE locks in handlePaymentCompleted/handleRefundCompleted, refund amount validation (>0, <= original)
  - NEW BUG FOUND+FIXED (F11, HIGH): AuditLogger::log() called with mis-aligned args (suppressed via @phpstan-ignore) → every successfully processed webhook threw TypeError → marked 'failed' + error returned to sender after money moved. Fixed both call sites.
  - NEW BUG FOUND+FIXED (F10, HIGH): UpdateService::splitSqlStatements dropped statements starting with '--' comment → migration 008 silently skipped on updated deployments (marked executed, DDL never ran). Fixed + 4 unit tests. Report: deployed instances must verify provider_trx_id column exists.
  - NEW BUG FOUND+FIXED (F12, MED): TransactionService::fail() overwrote metadata JSON (destroying invoice_id/payment_link_id generated-column linkage) → now JSON_MERGE_PATCH merge.
  - TransactionRepository: markCompletedIfNotTerminal + markStatusIfNotTerminal (replaces unconditional markCompleted); TransactionService complete/fail/cancel skip events/audit on no-op → hardens 60+ gateway adapter call sites
  - Admin TransactionController: state machine enforced (no complete/cancel of terminal; refund only from completed — failed→refunded previously fabricated ledger entries)
  - tests: 8 integration (WebhookIdempotencyTest) + 4 unit (splitter); full suite 488 green, phpstan clean
- [ ] F2 installer re-arm hardening (HIGH)
- [ ] F4 float-cast on money, 24 gateway adapters (MED)
- [ ] F7 refund reconciliation cron, auto-fail 24h (MED)
- [ ] F5 Twig |raw hook output producers (MED)
- [ ] F8 CORS default + credentials guard (MED-LOW)
- [ ] F6 rand() → random_int() DashboardController:552 (LOW)
- F3 api-tester.php: NO FIX per user — release-checklist item in report.

## Phase 2 — Deep sweep (WP1-WP8)
- [ ] not started

## Phase 3 — Fix WP findings
## Phase 4 — Verification gate
## Phase 5 — Report at docs/v2/audit/2026-06-12-production-readiness/REPORT.md

## Decisions
- No commits at all; user's pre-existing staged changes untouched.
- Stuck refunds: auto-fail after 24h, audit log + admin event.

## Observations queue (for report / WP verification)
- Tests print DB ENV values (host/user/pass) to stdout during integration tests — test-only, but noisy; consider for report LOW.
