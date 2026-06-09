# Task Plan: OwnPay Ultimate Master Audit (v1.1)

## Goal
Produce an exhaustive, evidence-based pre-release security + architecture audit of the OwnPay payment gateway across 11 quests, delivering 4 markdown docs in `docs/v2/audit_findings/`. AUDIT-ONLY: no source edits; only new docs + planning/log artifacts. Guess nothing — every finding cites file + exact lines + pasted code.

## Current Phase
PAUSED — Deliverable 1 complete, awaiting user review before Phase 3 (Deliverables 2-4)

## Key Findings Summary (for report)
- FIND-003 CRITICAL: Database::getInstance() throws in prod -> refunds + gateway callbacks broken (empirically verified). Scenario A.
- FIND-004 CRITICAL: un-gated mock-token payment bypass in affirm/afterpay/bitpay verify() (+verifyWebhook=true).
- FIND-005 HIGH: gateway verifyWebhook/refund stubs (no-op/simulation) across fleet.
- FIND-001 HIGH: MfsService parser arg-swap (dead code, latent critical).
- FIND-002 MED-HIGH: external gateway HTTP call inside DB txn holding FOR UPDATE (RefundService).
- Strong PASS areas: tenant isolation, ledger double-entry/GAAP, refund atomicity, SQLi(prepared), XSS(autoescape), CSRF, headers/CSP, CORS, file upload, JWT, password Argon2id, session, schema column compliance + generated columns, installer, SSRF(IPv4).
- Notable leads/medium: rate-limiter fail-open on DB down; SSRF DNS-rebind TOCTOU + IPv6; plugin null-sandbox SQL bypass; PHPUnit unrunnable on PHP 8.2 (phpunit ^12.5).

## Phases

### Phase 0: Setup & tooling baseline
- [x] planning-with-files session created (.planning/2026-05-30-ownpay-master-audit/)
- [x] output/change-log/ scope-declaration entry (20260530-154038)
- [x] create docs/v2/audit_findings/
- [x] tooling baseline: validate/audit OK, phpstan L9 clean, parallel-lint/twig/eslint/stylelint clean, npm audit 0; PHPUnit blocked (8.2 vs ^12.5)
- [x] web-exposure check: PASS (all 403/404)
- **Status:** complete

### Phase 1: Deep audit (11 quests)
- [ ] Q1 Sovereign boundary / custom domains / brand isolation
- [ ] Q2 Concurrency / redirect gaps / payment integrity / ledger
- [ ] Q3 CLI / hooks-events / plugins / gateway two-pass (all 135-140)
- [ ] Q4 SMS ingestion / pairing / parsing (MfsService arg swap)
- [ ] Q5 Checkout / invoice / frontend + callback-amount + negative-amount
- [ ] Q6 Attack surface (webhook sig, SSRF, mass-assign, SQLi, XSS, upload, info-disclosure, logging, rate-limit)
- [ ] Q7 DB schema hardening & scalability
- [ ] Q8 Installer wizard bootstrap
- [ ] Q9 Ultra-low-resource / shared hosting
- [ ] Q10 Admin UI/UX + framework feasibility + mobile API
- [x] Q11 Auth / session / crypto correctness
- **Status:** complete

### Phase 2: Deliverable 1 + PAUSE
- [x] Write docs/v2/audit_findings/ownpay_master_audit_report.md (11-section schema, Compliance Tag field)
- [x] change-log + post-change report (output/change-log/20260530-162150-...)
- [x] PAUSE for user review
- **Status:** complete

### Phase 3: Deliverables 2-4 (after review)
- [ ] DESIGN.md
- [ ] mobile_architecture.md
- [ ] mobile_design.md
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| D1 first, then pause | User choice — early course-correct |
| Gateway two-pass (grep-classify all + full-read flagged + 20 sample) | Amendment 5 — reproducible evidence over 140 linear reads |
| Full static/lint/security suite | User choice — real evidence |
| Cross-quest dedup: canonical FIND-NNN at first discovery | Amendment 6 |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| CLAUDE.md rule path `.agents\skills` stale | Real path is `.claude\skills\planning-with-files\scripts` |
