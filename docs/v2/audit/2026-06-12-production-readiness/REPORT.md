# OwnPay — Adversarial Production-Readiness Audit & Fix Report

**Date:** 2026-06-12
**Target:** OwnPay v0.1.0 — self-hosted payment gateway, PHP 8.3, custom MVC, Twig, MySQL
**Scope:** Full adversarial audit (filesystem → architecture → flow tracing → bug hunting) + production-hardening fixes
**Branch:** `fixing` (working tree only — not committed; pre-existing staged changes untouched)

> Status legend: ✅ Fixed · 🔁 Mitigated/verified-safe · 📋 Accepted (release-checklist) · ⏳ Pending

---

## 1. Architecture Summary

OwnPay is a **single-owner, multi-brand** (explicitly *not* multi-tenant SaaS) payment gateway platform. One superadmin operates multiple **brands** (`op_merchants`); every data row carries a `merchant_id` and repositories enforce brand scoping through the `TenantScope` trait (`forTenant($mid)->findScoped()/updateScoped()/createScoped()/deleteScoped()`).

**Request lifecycle:** `public/index.php` (sole front controller) → `src/Kernel.php` boot → `DomainMiddleware` resolves the brand from the request host (white-label custom domains in `op_domains`) → router (`config/routes/web.php`, `config/routes/api.php`) → ordered middleware group (`config/middleware.php`) → controller → `Response`. Twig renders views with autoescape on; plugin hook points use `{{ hook('...')|raw }}` (a deliberate trusted-HTML extension surface, sanitized server-side).

**Money path:** checkout token / payment link / invoice → `TransactionService::create` (amount sourced from the DB row, not the client) → gateway adapter `initiate()` → provider → inbound webhook (`/webhook/{gateway}` → `UnifiedWebhookController` → `WebhookInboundProcessor`) HMAC-verified and amount-re-verified server-side → `TransactionService::complete` → double-entry `LedgerService` postings (`op_ledger_*`). All amounts are `DECIMAL` columns and all arithmetic is **bcmath**; balances move via atomic `balance = balance ± X` SQL.

**Security baseline (verified present before this audit):** Argon2id passwords; `session_regenerate_id(true)` on login and full `session_destroy()` on logout; CSRF synchronizer-token-pool with `hash_equals`; `RateLimiterMiddleware` (Redis/DB, atomic INCR/UPSERT); API keys stored SHA-256-hashed and compared with `hash_equals`; JWT HS256 with `iss`/`aud`/`exp` pinned and device-revocation checks; PDO prepared statements throughout; encrypted PII (`*_enc` columns) and gateway credentials; webhook HMAC verification with 300 s timestamp skew; double-entry ledger with a `FOR UPDATE` idempotency backstop.

The codebase had already undergone prior audit passes (findings referenced in-code as `AUD-21`, `FIND-004`, `FIND-006`, `AUD-G7`). This pass goes deeper and fixes the confirmed-open findings below.

---

## 2. Phase 1 — Confirmed Findings (fixed)

All Phase 1 fixes verified against: `composer test` (510 tests, 1647 assertions, 1 skipped — all green), `composer analyse` (PHPStan level 9, 364 files, 0 errors), `composer lint:twig` (clean).

### F1 — Webhook idempotency TOCTOU + completion race · HIGH · ✅
**File:** `src/Gateway/WebhookInboundProcessor.php`, `database/schema.sql`, `src/Repository/TransactionRepository.php`, `src/Service/Payment/TransactionService.php`

**Issue.** Inbound webhook dedup was a `SELECT … WHERE payload_hash` followed by an `INSERT`, with **no unique constraint** on `op_webhook_deliveries`. Two identical webhooks arriving concurrently both passed the check and both processed. A second race existed in the completion path: `handlePaymentCompleted()` read the transaction, checked status in PHP, then called `complete()`, while `markCompleted()` was an unconditional `UPDATE` with no status guard — so concurrent distinct events for the same transaction could both fire completion events/audit/ledger. (The ledger's own `FOR UPDATE` idempotency check blocked an actual double-credit, but status, events, and audit still raced.)

**Fix.**
- New migration `database/migrations/009_webhook_inbound_dedup.sql` (mirrored into `schema.sql`): a `VIRTUAL` generated column `dedup_key = IF(direction='inbound' AND payload_hash IS NOT NULL, CONCAT(IFNULL(merchant_id,0),':',payload_hash), NULL)` plus `UNIQUE KEY uk_inbound_dedup (dedup_key)`. The key is generated only for inbound rows (outbound retries legitimately reuse a `payload_hash`) and collapses NULL `merchant_id` to `0` (a raw nullable column would not dedup). `VIRTUAL` not `STORED` — a `STORED` generated column forces an `ALGORITHM=COPY` rebuild that fails on FK tables (MySQL errno 1215).
- `WebhookInboundProcessor::process()` now records the delivery **INSERT-first** and catches the duplicate-key violation (errno 1062) as idempotent success — the unique index is the authority, closing the TOCTOU window completely.
- `handlePaymentCompleted()` and `handleRefundCompleted()` wrap resolve-check-mutate in `db->transaction()` with `SELECT … FOR UPDATE` on the transaction row (same idiom as `GatewayApiService::handleCallback`).
- New `TransactionRepository::markCompletedIfNotTerminal()` / `markStatusIfNotTerminal()` apply a conditional `UPDATE … WHERE status NOT IN (terminal)`; `TransactionService::complete/fail/cancel` skip events/audit/ledger when 0 rows transition, so a no-op never emits a phantom completion.

**Verification.** `tests/Integration/WebhookIdempotencyTest.php` — duplicate delivery processed once (1 delivery row, 1 ledger txn); unique index rejects a racing duplicate (errno 1062) while outbound retries stay duplicable; distinct events complete a transaction once; failed/cancelled/refunded cannot be resurrected or downgraded.

### F1-adjacent bugs found while fixing F1

#### F10 — Migration runner silently skips comment-led statements · HIGH · ✅
**File:** `src/Update/UpdateService.php` (`splitSqlStatements`)
**Issue.** Statements split on `;` retained any preceding `-- comment` line, and the runner discarded a statement that *started with* `--` — so a migration whose DDL was preceded by a comment (e.g. `008_add_provider_trx_id.sql`) was silently dropped while the migration was still marked executed. Result: schema drift on updated deployments with no error.
**Fix.** New `stripLeadingSqlComments()` removes only the leading comment/blank lines from each statement, preserving the DDL. Tests: `tests/Unit/UpdateServiceTest.php` (4 new cases incl. the `008` shape and string-embedded `;`/`--`).
**Operational note.** Instances updated through the self-updater between the introduction of migration 008 and this fix may be **missing `op_transactions.provider_trx_id`**. Verify with `SHOW COLUMNS FROM op_transactions LIKE 'provider_trx_id'` and re-run `008` if absent.

#### F11 — Webhook audit log call threw on every processed event · HIGH · ✅
**File:** `src/Gateway/WebhookInboundProcessor.php`
**Issue.** `AuditLogger::log()` was called with mis-aligned positional arguments (a `string` where `?int $userId` was expected), suppressed by `@phpstan-ignore`. Every *successfully* processed webhook threw a `TypeError`, was caught, and marked the delivery `failed` — returning an error to the gateway **after money had already moved**, inviting unnecessary retries and masking success.
**Fix.** Corrected both call sites to the real signature; removed the suppressions.

#### F12 — `TransactionService::fail()` destroyed transaction metadata · MEDIUM · ✅
**File:** `src/Service/Payment/TransactionService.php`
**Issue.** `fail()` overwrote the `metadata` JSON wholesale with `{failure_reason: …}`, detaching the `invoice_id`/`payment_link_id` **generated columns** (and their indexes) that are derived from that JSON.
**Fix.** `markStatusIfNotTerminal()` merges via `JSON_MERGE_PATCH`, preserving existing keys. Test: `WebhookIdempotencyTest::testFailPreservesExistingMetadata`.

#### F13 — Admin manual status change bypassed the state machine · MEDIUM · ✅
**File:** `src/Controller/Admin/TransactionController.php` (`updateStatus`)
**Issue.** An admin could mark a `failed`/`cancelled`/`expired` transaction `refunded` (or re-`complete`/`cancel` a terminal one), fabricating ledger entries for money that never moved.
**Fix.** Enforced transitions: terminal transactions reject `completed`/`canceled`; `refunded` is allowed only from `completed`.

### F2 — Installer re-arm to unauthenticated superadmin creation · HIGH · ✅
**File:** `src/Controller/Install/InstallerController.php`, `src/Kernel.php`, `config/middleware.php`, `.env.example`

**Issue.** Installed-state was `file_exists(storage/.installed)` only. If the marker were deleted, an unauthenticated visitor could re-run `/install/import-schema` (dropping every table) or `/install/create-admin` (minting a fresh superadmin) against the live database. The install route group also had **no rate limiting** (the route comment claimed otherwise).

**Fix.**
- `isInstalled()` now also runs `databaseLooksInstalled()` — when the marker is missing it connects to the configured DB (2 s timeout, fail-open on any error so genuine fresh installs are never blocked) and checks for an existing superadmin; if found it **self-heals** `storage/.installed`, logs a security warning, and locks every wizard endpoint (`show`/`testDatabase`/`importSchema`/`createAdmin`/`finalize`).
- A deliberate reinstall over a populated DB requires an `INSTALL_FORCE_KEY` (≥16 chars, sent as `X-Install-Force-Key`, compared with `hash_equals`) — documented in `.env.example`.
- `RateLimiterMiddleware` added to the `install` group (it fails open pre-DB, so fresh installs work; it protects the wizard once a DB exists).

**Verification.** `tests/Integration/InstallerLockTest.php` — populated DB + missing marker ⇒ `createAdmin`/`importSchema` refused (403) and marker self-healed; weak/absent force key never unlocks; unreachable DB (fresh box) lets the wizard proceed.

### F4 — Float cast on money in 24 gateway conversion sites · MEDIUM · ✅
**File:** `src/Gateway/GatewayDefaults.php` + 23 adapters under `modules/gateways/*/`

**Issue.** 24 sites computed minor units as `(int) bcmul((string)(float)$amount, '100', 0)`. The `(float)` round-trip corrupts large/high-precision amounts (binary FP can't represent them exactly) and silently accepts scientific notation, negatives, and arrays.

**Fix.** New `GatewayDefaults::toMinorUnits()` and `toDecimalString()` validate the input as a plain non-negative decimal (`/^\d{1,13}(\.\d+)?$/` + `is_numeric`) **before** any conversion, then use bcmath only. All 24 sites replaced mechanically; `php -l` clean across all modules.

**Verification.** `tests/Unit/GatewayDefaultsAmountTest.php` — `"10.05"→1005`, `"9999999999999.99"→999999999999999` (the float path corrupted both), rejects `1e3`/`-5`/`10,00`/array/oversized.

### F7 — Refunds stuck in `pending` forever · MEDIUM · ✅
**File:** `src/Cron/RefundReconciliationJob.php` (new), `config/services.php`

**Issue.** `RefundService` executes refunds in three phases (prepare → gateway call → reconcile). A crash between the gateway call and the reconcile phase leaves the refund `pending` forever — and pending refunds **withhold the merchant's available ledger balance**. No reconciliation path existed.

**Fix.** New hourly cron `RefundReconciliationJob`: refunds `pending` beyond a 24 h window are auto-failed under a `FOR UPDATE` re-check (a delayed phase-3 completing concurrently wins), with an audit entry and a `payment.refund.reconciliation_failed` event for manual gateway-dashboard verification. Auto-fail is funds-conservative — it releases the withheld balance so the merchant can retry. Batch-capped at 100/run. (Per user decision: auto-fail after 24 h, no schema change.)

**Verification.** `tests/Integration/RefundReconciliationJobTest.php` — stale pending auto-failed (recent + terminal untouched), audit row written, event fired once, idempotent across runs.

### F5 — Plugin hook HTML output sanitizer bypasses · MEDIUM · ✅
**File:** `src/View/TwigExtensions.php` (`hook()` / new `sanitizeHookOutput()`), `docs/v2/plugins/hooks-reference.md`

**Issue.** `{{ hook('…')|raw }}` renders plugin output as trusted HTML. The existing defense-in-depth sanitizer (AUD-G7) was single-pass (so `<scr<script>ipt>` reassembled after one strip), only matched **quoted** event handlers and `javascript:` URIs, and omitted self-closing `<link>` from its element strip list. Producers (`SettingsRenderer`, `ownpay_footer`) were verified to already escape all interpolated values, so no producer fix was needed.

**Fix.** `sanitizeHookOutput()` now loops to a stable fixed point (defeats split-tag reassembly; refuses output that won't stabilize), strips unquoted event handlers and unquoted `javascript:` URIs, and includes `link` in both element passes. Documented the plugin trust contract ("hook output is trusted HTML; plugins MUST escape user data").

**Verification.** `tests/Unit/HookOutputSanitizerTest.php` — split-tag, unquoted handlers, unquoted `javascript:`, self-closing `<link>`/`<meta>`, iframes/forms all stripped; safe markup preserved.

### F8 — CORS default + wildcard-credentials · MEDIUM-LOW · 🔁/✅
**File:** `src/Middleware/CorsMiddleware.php`, `.env.example`
**Finding.** `CorsMiddleware` already denies credentials in wildcard mode and defaults to deny-all when unset (verified safe — no code change needed there). The `.env.example` default of `CORS_ALLOWED_ORIGINS=*` was changed to empty with explicit-origin guidance.

### F6 — `rand()` for brand slug suffix · LOW · ✅
**File:** `src/Controller/Admin/DashboardController.php:552` — `rand()` → `random_int()`.

### F3 — `public/api-tester.php` dev tool in webroot · 📋 Accepted
Per user decision, the standalone API tester is **kept** for local testing and **must be removed before public release** (see §5 release checklist). No code change.

---

## 3. Phase 2 — Deep Subsystem Sweep

_Multi-agent adversarial sweep across 8 work packages (admin controllers, checkout, gateway adapters, mobile/SMS, plugin loader, installer, cron/queue/update, white-label domains). Every finder result was independently refute-or-confirm verified against the code before entering the fix queue. **17 findings confirmed, 4 refuted as false positives.**_

### Confirmed findings (by severity)

| ID | Sev | Location | Issue |
|----|-----|----------|-------|
| P-1 | 🔴 CRITICAL | `src/Plugin/PluginManager.php` `resolveIconPath()` | A plugin manifest `icon` is copied into `public/assets/img/gateways/<slug>.<ext>` preserving the extension. `icon: "shell.php"` drops an executable PHP file in the webroot → RCE for any staff with `plugins.manage`. |
| P-2 | 🔴 CRITICAL | `modules/gateways/amazon-pay/AmazonPayGateway.php` `verifyWebhook()` | Returned `true` unconditionally and compared the HMAC with `===`. Amazon Pay's `verify()` performs no independent crypto (it trusts the `_op_webhook_verified` flag set when `verifyWebhook` passes), so this was the gateway's only signature gate. *Latent* (the core amount-check blocks completion because `verify()` returns no amount), but a forged-webhook footgun. |
| P-3 | 🔴 CRITICAL | `src/Update/UpdateService.php`, `src/Plugin/PluginInstaller.php` | ZIP entry validation rejected `..` and leading `/` but not `\`. On Windows `ZipArchive::extractTo()` honors `\` as a separator → path-traversal/zip-slip out of the extraction root (`BackupService` already had the backslash check). |
| P-4 | 🟠 HIGH | `modules/gateways/easypaisa/EasypaisaGateway.php` `verifyWebhook()` | Returned `true` unconditionally. `verify()` does real HMAC (so the core path was protected), but the ingress gate was a no-op — contract violation and a bypass for any plugin webhook handler. |
| P-5 | 🟠 HIGH | `src/Service/Sms/SmsRegexParser.php` | Staff-authored SMS regex templates run against attacker-influenceable SMS bodies with no PCRE backtracking cap → ReDoS hangs the SMS cron / mobile endpoint. |
| P-6 | 🟠 HIGH | `src/Cron/CronJobRunner.php` | Lock was a non-atomic read-check-write; two concurrent `/cron/{secret}` hits both pass `isDue()` and run the same job twice (duplicate refund-reconciliation/webhook retries). |
| P-7 | 🟠 HIGH | `src/Update/UpdateService.php` `execute()` | `isUpdateInProgress()` + `startUpdate()` not atomic → two concurrent `/admin/system-update/apply` calls both start, running the download/extract/migrate pipeline twice. |
| P-8 | 🟠 HIGH | `src/Service/Domain/DomainUrlService.php` `resolveBaseUrl()` | Untrusted `Host` header used to build callback URLs handed to external gateways when `APP_URL` is unset → gateway callbacks redirectable to an attacker host. |
| P-9 | 🟡 MEDIUM | `src/Middleware/JwtAuthMiddleware.php`, `PairedDeviceRepository::findByDeviceId()` | Device lookup falls back to `merchant_id IS NULL` rows and the middleware never re-checks the match, so a global device could authenticate against any merchant's JWT. |
| P-10 | 🟡 MEDIUM | `src/Service/Domain/DomainService.php` (×2), `src/Controller/Admin/DomainController.php` | `gethostbyname()` fed from the untrusted `Host` header → merchant-user can drive DNS lookups of arbitrary hostnames. |
| P-11 | 🟡 MEDIUM | `src/Middleware/SecurityHeadersMiddleware.php` | No `Vary: Host` on Host-routed (brand) responses → a shared CDN could serve one brand's cached checkout to another brand's domain. |
| P-12 | 🟡 MEDIUM | `src/Cron/DnsVerificationJob.php` | Verified custom domains were never re-checked → a domain stays trusted forever after the owner removes the TXT record / loses the domain (DNS TOCTOU). |
| P-13 | 🟡 MEDIUM | `src/Controller/Admin/DashboardController.php` `exportCsv()` | CSV export cells (reachable via the `export.row` plugin filter) not neutralized against spreadsheet formula injection. |
| P-14 | 🟡 MEDIUM | `src/Service/Payment/InvoiceService.php` | Invoice line-item/tax/discount normalized through `(float)` before the bcmath totals — drops a cent at `DECIMAL(15,2)` extremes and accepts scientific notation. |
| P-15 | 🟢 LOW–info | Device pairing OTP validation | 6-digit OTP brute-force — mitigated by HTTP rate-limit (60/min) + 5-min expiry (~300 guesses per OTP lifetime vs 1M space). Accepted. |
| P-16 | 🟢 by-design | `Api/PaymentController` / `PaymentIntentCheckoutController` | Merchant-set `redirect_url`/`cancel_url` allow external hosts — standard for payment return URLs; `javascript:`/`data:` already blocked by the scheme allowlist. Accepted. |
| P-17 | 🟠 HIGH | `src/Controller/Install/InstallerController.php` (from sweep run 1) | `.env` value injection via newlines in DB credentials / `Host` header → could force `APP_DEBUG=true` on the fresh install. |

### Refuted (false positives — verified safe, no change)

- **SMS amount-only transaction spoofing** — the fallback path is dead code: SMS records are created with `match_status` of `accepted`/`admin_review`, never `pending`, and the matcher only reads `pending`.
- **`provider_trx_id` replay** — the column is never populated (not fillable, never written), and `findPendingMatch()` enforces a `count == 1` + time-window guard.
- **JWT algorithm confusion** — `firebase/php-jwt` v7 pins `HS256` at the library layer; `alg:none`/confusion is rejected before claim validation.
- **CSP `Report-To` CRLF injection** — `json_encode` + PHP 8 `header()` filtering neutralize control characters; at worst a Host-spoof of the report endpoint (low).

---

## 4. Phase 3 — Fixes Applied to Verified Phase 2 Findings

All confirmed findings were fixed (root-cause, codebase-idiomatic) with regression tests where unit-testable. The full gate (`composer test` / `analyse` / `lint:twig`) is green after every fix.

- ✅ **P-1 Plugin icon RCE** — `resolveIconPath()` now allowlists image extensions (`png/jpg/jpeg/gif/svg/webp/ico`) and returns `null` for anything else, so a non-image icon can never land in the webroot.
- ✅ **P-2 / P-4 Gateway webhook bypass** — Amazon Pay `verifyWebhook()` rewritten to a fail-closed `hash_equals` HMAC check (no unconditional `true`, no `===`); Easypaisa `verifyWebhook()` now verifies the `secureHash` over the parsed body, mirroring its `verify()`. Swept all 108 adapters: the 14 flag-pattern gateways already fail closed (`FIND-001`); the rest authenticate inside `verify()` (server-side status API or HMAC — spot-confirmed mollie/phonepe/rocket/jazzcash). Regression test: `GatewayWebhookBypassTest` (arbitrary wrong signature, tampered body, missing secret all rejected).
- ✅ **P-3 Windows zip-slip** — both `UpdateService::extractPackage()` and `PluginInstaller::scanZipSecurity()` now also reject entries containing `\`.
- ✅ **P-5 ReDoS** — new `SmsRegexParser::safeMatch()` runs every merchant pattern under a 50 000-step `pcre.backtrack_limit`/`recursion_limit`, so a catastrophic pattern fails fast (treated as no-match). Test `SmsRegexParserReDoSTest` confirms a `(a+)+b` pattern on 60×`a` completes in <1 s.
- ✅ **P-6 Cron race** — per-job `flock(LOCK_EX | LOCK_NB)` with an in-lock `isDue()` re-check; `runJob()` shares the lock so manual and scheduled runs can't overlap.
- ✅ **P-7 Update race** — `flock` guard around the whole `execute()` (acquired before the DB in-progress check; degrades to the DB check alone if the lock file is unavailable).
- ✅ **P-8 / P-10 Host-header trust** — callback-URL fallback validates the request host against `APP_DOMAIN`/the brand's verified domain; `gethostbyname()` sites use the configured `APP_DOMAIN` instead of the request `Host`.
- ✅ **P-9 Device scoping** — `JwtAuthMiddleware` now rejects a device whose `merchant_id` doesn't equal the JWT's `mid`, closing the NULL-merchant fallback.
- ✅ **P-11 Cache poisoning** — `SecurityHeadersMiddleware` adds a merge-safe `Vary: Host` on custom-domain responses.
- ✅ **P-12 DNS TOCTOU** — `DnsVerificationJob` now re-verifies active domains older than 24 h (`DomainRepository::findStaleVerified()`) and reverts a domain to `pending` if its TXT proof is gone.
- ✅ **P-13 CSV injection** — `DashboardController::csvCell()` prefixes a `'` to any cell beginning with `= + - @` / tab / CR.
- ✅ **P-14 Invoice float** — `InvoiceService::normalizeMoney()` validates a plain decimal and normalizes with bcmath (no `(float)` round-trip).
- ✅ **P-17 Installer `.env` injection** — `envToken()` strips control characters and quotes/escapes every written value; `parseTempEnv()` reverses it; the `Host` header is validated before it reaches `APP_URL`/`APP_DOMAIN`. Test: `InstallerEnvTokenTest`.
- 📋 **P-15 OTP brute-force**, **P-16 return-URL open redirect** — accepted with rationale above (existing mitigations; changing them would degrade UX or break a standard payment feature).

---

## 5. Remaining Risks & Release Checklist

- ✅ **`public/api-tester.php`** — now **gated**: a fail-safe guard at the top of the file returns **404** unless the environment is explicitly non-production (`APP_DEBUG=true` or `APP_ENV` ∈ local/development/dev/testing), verified to emit 0 bytes under the production `.env`. It remains usable for local testing (per operator preference). 📋 Still recommended to delete the file entirely for the final public release; it loads `cdn.tailwindcss.com` + Google Fonts and bypasses the front controller.
- 📋 **`provider_trx_id` migration (F10)** — on instances self-updated before this fix, confirm `op_transactions.provider_trx_id` exists; re-run migration `008` if missing.
- 🔁 **Secrets hygiene** — `.env` and `update/update_private_key.pem` are correctly git-ignored (verified). Rotate `APP_KEY`/`ENCRYPTION_KEY`/`JWT_SECRET`/`HMAC_KEY` if the working tree was ever shared. The update private key belongs in offline/CI key storage, never on production hosts.
- 🔁 **Gateway refund-status reconciliation (F7)** — adapters expose no refund-status query API, so stale pending refunds are time-based auto-failed (24 h) rather than gateway-confirmed. Operators should reconcile auto-failed refunds against the gateway dashboard before re-issuing.
- ⏳ **Live-gateway paths** — webhook signature/amount verification for each adapter cannot be exercised without live credentials; covered by static audit (WP3) + the existing adapter test suite, not live transactions.

---

## 6. Production Readiness Verdict

**Verdict: cleared for public beta**, conditional on the release-checklist items in §5 (remove `public/api-tester.php`, rotate any shared secrets, keep the update private key offline).

This pass discovered and fixed **1 live CRITICAL** (plugin-icon RCE), **2 latent/contained CRITICALs** (gateway webhook bypass already backstopped by the core amount-check; Windows zip-slip gated by the RSA signature), and a set of HIGH/MEDIUM hardening gaps (cron/update races, ReDoS, host-header trust, device scoping, DNS TOCTOU, cache-Vary). Combined with the Phase-1 fixes (webhook idempotency, installer re-arm, gateway float-casts, refund reconciliation, plugin-hook sanitizer, installer `.env` injection), every confirmed CRITICAL/HIGH/MEDIUM is closed with a root-cause fix.

The money core is sound: amounts are `DECIMAL` + bcmath end-to-end, payment completion is row-locked and idempotent with server-side amount re-verification, the ledger is double-entry with atomic balance updates, and gateway webhooks are signature-verified (or fail closed). The four refuted findings were confirmed non-exploitable.

**Verification (final):** `composer test` → **525 passed / 1 skipped**, `composer analyse` (PHPStan L9, 364 files) → **0 errors**, `composer lint:twig` → **clean**, fresh `schema.sql` imports clean. 52 regression tests were added across the phases. No application code was committed — all changes are in the working tree for review.

---

## 7. Phase 4 — External Audit Cross-Check (Codex + audit_findings)

Two prior external audit reports (`docs/v2/audit_fundings_codex/ownpay_master_audit_report.md` and `docs/v2/audit_findings/`) were cross-checked against the **current** code. Confirmed findings were fixed; refuted ones verified non-exploitable.

| ID | Sev | Source | Issue & Fix |
|----|-----|--------|-------------|
| XF-1 | 🔴 CRITICAL | detailed_findings #1 | **Device-pairing privilege escalation.** `generateOtp` never passed the admin id, so `created_by` was null and `pairDevice` fell back to the brand's first **superadmin** — a low-priv staffer's device got a superadmin token. Fix: controller passes the session user id; `pairDevice` fails closed (`PAIRING_CONTEXT_UNRESOLVED`) with no superadmin fallback. |
| XF-2 | 🟠 HIGH | detailed_findings #3 | **Invoice update wipe + non-atomic rebuild.** An empty/missing `items` payload zeroed the invoice and deleted all line items; UPDATE→DELETE→INSERT ran outside a transaction. Fix: reject empty items; wrap the rebuild in `db->transaction()`. |
| XF-3 | 🟠 HIGH | Codex F-003 | **Ledger account uniqueness missing currency.** `uk_merchant_name (merchant_id,name)` blocked a second currency for the same account name, failing multi-currency posting. Fix: migration 010 widens the key to `(merchant_id,name,currency)` (mirrored in `schema.sql`). |
| XF-4 | 🟡 MEDIUM | Codex F-004 | **Rate-limit decision non-atomic.** Read-then-increment let concurrent bursts exceed the limit. Fix: atomic increment-then-check on the returned count (Redis `INCR` / DB upsert + read-back). |
| F-002 | 🟠 HIGH | Codex | **SMS auto-verification dead.** Parsed SMS were written `match_status='accepted'`, but the cron matcher only reads `'pending'` — so SMS-based MFS confirmation never ran. Fix: parsed rows enter as `'pending'` (cron promotes to `'matched'`); the count==1 + time-window + tenant + device-scoping guards remain the controls. |
| F-001 | 🔴→🟡 | Codex + detailed #2 | **Gateway sandbox "simulation accept" in production.** ~28 adapters' `verify()` accept-on-unreachable-API, 7 more sandbox-accept paths, Easypaisa's no-key fallbacks, and **34 `refund()` methods that fake success without any API call** would complete real transactions / fake refunds if a gateway were left in sandbox mode on a live deployment. Live mode was already safe (server-side `status===PAID` confirmation — verified across dlocal/cybersource/moneris/mollie/phonepe/jazzcash). Fix: `GatewayDefaults::isProductionEnv()` (fail-safe default = production) gates every simulation path off in production; simulated refunds fail closed (must be done in the provider dashboard). A centralized "require live mode" guard was **rejected** because it would falsely block always-live gateways that have no `mode` field. |

**Refuted in cross-check (no change):** Codex F-001 is fully backstopped in live mode by `verify()`'s server-side confirmation; the only real exposure was sandbox-mode-in-production, now guarded.
