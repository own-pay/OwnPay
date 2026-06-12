# Task Plan: OwnPay Ultimate Master Audit (v1.1)

## Goal
Produce an exhaustive, evidence-based pre-release security + architecture audit of the OwnPay payment gateway across 11 quests, delivering 4 markdown docs in `docs/v2/audit_findings/`. AUDIT-ONLY: no source edits; only new docs + planning/log artifacts. Guess nothing — every finding cites file + exact lines + pasted code.

## Current Phase
ROUND 3 COMPLETE — authorized code fixes applied (cli/build-update.php, cli/create-module.php, update/index.html) + new cli/.buildignore; php -l clean; .buildignore logic probe 33/33; docs updated; schema unused-tables reported (op_maintenance_locks, op_queue_jobs, op_plugin_settings). Snapshots + change-logs written. No other code touched.

### Round 3b — update/index.html branding (COMPLETE)
Real logo (ownpay.org/ownpay_logo.png) + favicon (ownpay_icon.png) + footer ecosystem nav (home/blog/learn/support/github) + social (GitHub, Facebook fb.com/ownpay.org). Kept premium glassmorphism design; offered optional further upgrades. Snapshot + change-log written.

### Round 3g — Feature gap analysis, scope-corrected (COMPLETE)
User asked what features are missing/good-to-have/useless/improvable; then corrected the model: OwnPay = single-owner multi-brand gateway AGGREGATOR (funds → owner directly, no settlement; merchant owns history/coupons/installments/customer-email/subscription-scheduling; test "gap" is false-positive on 8.3 build box). Verified via schema.sql table inventory + src/Service/** inventory + greps: confirmed disputes/reconciliation/ledger/idempotency/mail-interface/multi-currency EXIST (not missing); op_alerts ABSENT (alerting broken), op_job_queue vs op_queue_jobs mismatch; NO gateway routing/failover/health engine; NO tokenization/3DS. Wrote docs/v2/features/feature-gap-analysis.md (§0 model, §1 out-of-scope parked, §2 missing/broken [2a defects, 2b aggregator value-adds routing+health], §3 good-to-have [tokenization/mandates,3DS,fuller REST API,OpenAPI,sandbox,webhook normalization,MCP], §4 dead weight, §5 improvable, §6 already-good, §7 confidence+order). Doc-only; no code. Change-log 20260531-102945.

### Round 3f — MCP server AI implementation runbook (COMPLETE)
User: write a zero-mistake, production-ready implementation plan an AI model can follow to build the entire MCP server (from mcp.md) without breaking anything; detailed for a non-expert AI; save in same dir as mcp.md. Verified the real framework first (read config/middleware.php, config/routes/api.php, Container.php [autowire throws on scalar ctor params], Http/Request.php + Response.php exact signatures, BearerAuthMiddleware [middleware contract], Kernel.php 240-368 [middleware resolution + dispatch], services.php, HealthController, ApiKeyRepository + BaseRepository [Database API + hashed-credential template]). Wrote docs/v2/features/mcp-implementation-plan.md: §0 Rules of Engagement (12 hard rules + verified conventions + autowire trap), §1 Phase -1 pre-flight verification (adapt-to-reality, no guessing), §2-8 Phases 0-6 (DB+settings, OAuth2.1 AS w/ PKCE+rotation, McpResourceMiddleware+mcp group, MCP core w/ deny-by-default ToolRegistry+hard deny-list+tripwire, 23 tools w/ 3 full exemplars + exact spec table all forTenant-scoped, admin UI, hardening+4 tests), §9 DoD acceptance, §10 rollback, §11 troubleshooting; every phase gated by CHECK. Additive-only edits to shared files; VERIFY flags on un-read internals. Doc-only; no code touched. Change-log 20260531-084002.

### Round 3e — Native MCP server feature plan (COMPLETE)
User: plan a native, OAuth-2.1-secured MCP server for OwnPay (create invoice/payment link/customer, search tx, customer search, tx history + safe extras), admin enable/disable, must NOT refund / add+verify transaction / cause financial loss / access API key. Save to docs/v2/features/mcp.md. Plan-mode: 3 Explore agents (domain services/repos; auth/api-keys/settings/audit; HTTP/routing/DI/controllers). Locked decisions: (1) full OAuth 2.1 AS+RS w/ PKCE+resource-indicators+rotation; (2) customer reads = full PII (audit-logged); (3) all 4 optional tools (update/cancel invoice&link, balance&stats, export, refund-read). Wrote docs/v2/features/mcp.md (14 sections: capability matrix, architecture+2 mermaid, MCP protocol, OAuth design, admin toggle, defense-in-depth, op_oauth_* DDL, P0-P6 plan, full code examples, client integration, verification, risks). Hard no-loss guarantee = deny-by-default registry + hard deny-list regex + CI tripwire + OAuth scopes + forTenant. Forbidden (never registered): RefundService::create, GatewayApiService::initiatePayment/handleCallback, CheckoutController::submitVerification, ApiKeyService, unscoped findPendingMatchGlobal/getGlobalBrandBreakdown. Doc-only; no code touched. Change-log 20260531-082540.

### Round 3d — new-findings implementation plan addendum (COMPLETE)
User: docs got new findings but IMPLEMENTATION_PLAN is still old; ADD new findings' plan, do not remove existing. Verified the original plan covered FIND-001..020/S1/S2 + build-update B1-B6 + create-module M1-M6 + index.html U1-U5, but the Round 3b/3c index.html work + sharpened M4 had no plan entries. ADDITIVE edits only: IMPLEMENTATION_PLAN.md gained an "ADDENDUM — New Findings Implementation Plan (Round 3b/3c)" with U7 (list-resilience bug, FIXED), U8 (GitHub-first download, DONE), U9 (branding/UX, DONE; SRI/self-host remaining), M4 (scaffold CSRF, FIXED) + 1 coverage-map row + addendum gate checklist. UPDATE_SERVER_REVIEW.md synced: added U7/U8/U9 + implemented table + annotated stale U6 (checkZipExists removed). Snapshots before-20260531-021807 for both docs. Change-log 20260531-021807. Markdown-only; nothing removed; no code touched.

### Round 3c — update/index.html design upgrades + list-resilience bug fix (COMPLETE)
Applied the four accepted premium upgrades: (2) sticky top nav + hero verify-command band, (3) light/dark theme toggle w/ anti-flash init + persisted pref, (4) release search/filter via data-search attrs, (5) CSP meta + pinned marked@12/DOMPurify@3 + self-host note. **BUG FIX**: `renderChannel` no longer hides the entire list when the latest release has a config error / missing download — made sync, filters `valid=releases.filter(r=>r&&r.version)`, features valid[0], renders history valid.slice(1) independently; `downloadBlock` shows an "unavailable" badge inside the card instead of blanking. **GitHub-first download**: `githubUrl()` (manifest github url else conventional release-asset url) primary + `localMirrorUrl()` same-origin fallback link. Verified: node probe ALL 2 INLINE SCRIPTS OK; read-back of lines 993-1143 confirms logic; HTML structure intact. Change-log 20260531-015730; verified-good snapshot index.after-design-upgrade-20260531-015730.html. Only update/index.html touched (no git repo at root — snapshots are the rollback path).

### Round 3 follow-up (COMPLETE)
New findings propagated into relevant docs: FIND-020 (dead tables op_maintenance_locks/op_queue_jobs/op_plugin_settings) -> master report §10+matrix, IMPLEMENTATION_PLAN Phase C, SECURITY_AUDIT_V2 A04, SCHEMA_REVIEW §6. MODULE_BUILDER_REVIEW M4 sharpened (CSRF field-name + raw-PHP csrf_token bug, FIXED). Verified hook() is_safe=html (non-finding, recorded).

## ROUND 2 (COMPLETE)
All 10 markdown deliverables written to docs/v2/audit_findings/ (no code edited).

### Round 2 deliverables
- [x] 6. UPDATE_BUILDER_REVIEW.md (build-update.php)
- [x] 7. MODULE_BUILDER_REVIEW.md (create-module.php)
- [x] 8. UPDATE_SERVER_REVIEW.md (update/index.html)
- [x] 9. SCHEMA_REVIEW.md (op_alerts missing, op_job_queue/op_queue_jobs mismatch — both wired/live)
- [x] 1. IMPLEMENTATION_PLAN.md (production fixing plan -> first release; release gate)
- [x] 10. SECURITY_AUDIT_V2.md (independent OWASP/PCI/CWE pass)
- [x] 4. ARCHITECTURE.md
- [x] 3. CODEBASE_GRAPH.md (mermaid + criticality table)
- [x] 2. DEVELOPER_GUIDE.md
- [x] 5. AGENTS.md
- [x] post-change report + attest
- **Round 2 verified findings:** op_alerts MISSING (AlertService.php:42+); op_job_queue!=op_queue_jobs (QueueWorkerJob.php:77 vs schema:774); build-update storage/ excluded -> fresh-install break + empty-dir loss + exclusion gaps (output/,.claude/,prompt.txt) + composer update ships dev; create-module jsonBody()/insecure verify defaults/missing icon; index.html formatBytes bug + unsanitized marked.js.

## ROUND 1 (COMPLETE)
- All 4 deliverables produced. Phase 3 surfaced FIND-019 HIGH, folded into master report + mobile_architecture.md.

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
- [x] DESIGN.md (admin UI/UX eval + HSL design system + custom-framework feasibility)
- [x] mobile_architecture.md (API readiness + FIND-019 + Play SMS compliance [grounded] + privacy gate)
- [x] mobile_design.md (visual language + nav flows + battery/temp grid + biometric audit trail)
- **Status:** complete

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
