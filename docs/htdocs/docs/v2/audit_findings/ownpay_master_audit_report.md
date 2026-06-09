# OWNPAY MASTER AUDIT REPORT

> Pre-release adversarial security + architecture audit of the OwnPay self-hosted, single-owner, multi-brand payment gateway (custom PHP 8.2 framework, MySQL, PSR-11, Twig).
> **Engagement:** AUDIT-ONLY (no application source modified). Every finding is backed by the exact file, line range, and pasted code. Findings stated without evidence are explicitly labelled *lead* or *informational*.
> **Date:** 2026-05-30 · **Auditor:** Claude (senior fintech/security review) · **Working evidence:** `.planning/2026-05-30-ownpay-master-audit/findings.md`

---

## 1. Scope, Methodology & Discovery Map

### 1.1 Codebase footprint (verified)
- **436 PHP files** (~50,077 LOC under `src/`), **79 Twig templates**, **~140 gateway adapters** under `modules/gateways/`, **3 addons** (`mail-gateway`, `sms-gateway`, `telegram-bot`), **1 theme** (`own-pay`).
- `database/schema.sql` + 4 seeds; versioned `update/releases/{0.2.0,0.2.1,0.2.2}`.
- Entry point: `public/index.php` → `src/Kernel.php`. Custom PSR-11 container (`src/Container.php`) with Reflection autowiring. Front-controller routing via `.htaccess` rewrite into `public/`.

### 1.2 Architecture mapped
| Layer | Location | Notes |
|---|---|---|
| Kernel / boot | `src/Kernel.php`, `config/services.php`, `config/middleware.php` | .env → DI → plugins → middleware → routes → dispatch |
| Routing | `src/Http/Router.php`, `config/routes/{web,api}.php` | Middleware groups: global/web/admin/api/admin-api/mobile/webhook/cron/checkout/install |
| Persistence | `src/Core/Database.php` (PDO, `ATTR_EMULATE_PREPARES=false`), `src/Repository/*` (BaseRepository + TenantScope) | Prepared statements throughout |
| Payments | `src/Service/Payment/*` (Payment, Transaction, GatewayApi, Ledger, Refund, Dispute, Fee, Currency, Idempotency, Mfs) | Double-entry ledger, FOR UPDATE locks |
| Gateways | `src/Gateway/*` (Adapter interface, Bridge), `modules/gateways/*` | 116/140 make real cURL calls |
| SMS/MFS | `src/Service/Sms/*`, `src/Service/Device/*`, `src/Cron/SmsVerificationJob.php` | Device-paired carrier-SMS ingestion |
| Security | `src/Security/*`, `src/Middleware/*` | CSRF, headers/CSP, CORS, rate-limit, JWT, SSRF validator |
| Install | `src/Controller/Install/InstallerController.php` | 4-step wizard, `.installed` lock |

### 1.3 Methodology
Static read of every security/logic-critical file (line-by-line); reproducible grep classification of all ~140 gateways (Amendment 5 two-pass); full static/lint/security tool run (Section 1.4); a read-only boot-replica probe to empirically confirm FIND-003; live HTTP web-exposure probe. Adversarial mindset: every input treated as hostile.

### 1.4 Tooling baseline (real evidence, captured this engagement)
| Tool | Result | Exit |
|---|---|---|
| `composer validate` | `./composer.json is valid` | 0 |
| `composer audit` | **No security vulnerability advisories found** | 0 |
| PHPStan **level 9** (`cli/config/modules/src`) | **[OK] No errors** | 0 |
| php-parallel-lint | 364 files, **no syntax errors** | 0 |
| twig-cs-fixer | 79 templates, **0 issues** | 0 |
| eslint / stylelint | **clean** | 0 |
| `npm audit` (prod + dev) | **0 vulnerabilities** | 0 |
| **PHPUnit** | **CANNOT RUN — requires PHP ≥ 8.3; env & declared min is 8.2** | 1 |
| Web-exposure (`/.env`,`/database/schema.sql`,`/composer.json`,`/storage/logs/*`) | **403/404 — none served** | PASS |

Environment: PHP 8.2.12 (XAMPP), Composer 2.9.7, Node v24.14.1. The codebase is exceptionally clean at static-analysis level (PHPStan L9 zero errors is notable). The single tooling failure (PHPUnit) is itself a finding — see FIND-006.

---

## 2. Executive Summary

### 2.1 Severity matrix
| Severity | Count | IDs |
|---|---|---|
| **CRITICAL** | 2 | FIND-003, FIND-004 |
| **HIGH** | 3 | FIND-001, FIND-005, FIND-016 |
| **MEDIUM** | 5 | FIND-002, FIND-006, FIND-007, FIND-009, FIND-017 |
| **LOW** | 5 | FIND-008, FIND-010, FIND-011, FIND-014, FIND-015 |
| **INFORMATIONAL** | 3 | FIND-012, FIND-013, FIND-018 |

### 2.2 Release recommendation: **HOLDBACK**
Two CRITICAL findings break or bypass the **core money path**:
- **FIND-003** — `Database::getInstance()` throws in production (empirically verified), so **refund processing and gateway webhook/callback completion both fail at runtime**. Customers can pay at the gateway while OwnPay never marks the transaction complete (this *is* the Quest-2 "redirect divergence Scenario A"), and no refund can be issued.
- **FIND-004** — Several live gateways (`affirm`, `afterpay`, `bitpay`) **auto-confirm a payment for a `mock_`-prefixed token with no live-mode guard**, while their webhook signature check is a no-op (`return true`). This is a free payment-confirmation bypass and a "successful payment with zero funds received" trap on credential/API failure.

Either finding alone is release-blocking for a payment platform. **Do not ship until FIND-003 and FIND-004 are fixed and the gateway fleet is normalized (FIND-005).**

The encouraging counterweight: the **core domain design is strong** — tenant isolation, double-entry/GAAP ledger, refund atomicity, SQL-injection defense, XSS/CSRF/headers, file-upload validation, JWT, password hashing, schema column-compliance, and the installer are all well-built (Section 11). The critical issues are concentrated in (a) one bootstrap/service-locator wiring defect and (b) inconsistent gateway-adapter hardening — both fixable without architectural upheaval.

### 2.3 Single-owner business-model risk analysis
The sovereign single-owner / multi-brand model is **respected**, not breached:
- Tenant identifier is consistently `merchant_id`; `TenantScope` clone-scoping + `requireTenant()` *throws* when unscoped (fail-closed) — no silent cross-brand access path was found.
- `is_superadmin` is a global owner flag; brand staff are merchant-scoped; **plugin activation is brand-scoped** (`EventManager::isOwnerActive`), so a plugin enabled for Brand A does not execute for Brand B.
- Custom-domain middleware blocks `/admin` on brand domains and 404s unknown/unverified domains.
No finding in this report breaks the single-owner flow or grants unprivileged staff cross-brand reach. The brand-isolation boundary is one of the strongest parts of the codebase.

---

## 3. High-Volume Scalability & Database Assessment

### 3.1 Schema vs codebase — column-naming compliance (Quest 7.3): **PASS (exact)**
Verified in `database/schema.sql`:
- `op_merchant_users.totp_secret_enc` (L74), `two_factor_enabled` (L75) — exact.
- `op_currencies.decimal_places` (L191) — exact.
- `op_exchange_rates.base_currency`/`target_currency` + `UNIQUE KEY uk_pair` (L199-205) — exact + unique.
- `op_sms_parsed.device_id` (L673), `match_status` ENUM (L686) + `idx_merchant_status`, `idx_device` (L693-696) — exact.
- All tables `op_`-prefixed.

### 3.2 Stored generated columns for hot JSON (Quest 7.4): **PASS**
```sql
-- database/schema.sql:289-290 (op_transactions)
`invoice_id`      BIGINT UNSIGNED GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.invoice_id')) AS UNSIGNED)) STORED,
`payment_link_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.payment_link_id')) AS UNSIGNED)) STORED,
```
Hot lookup keys are STORED generated columns with matching indices (`idx_invoice_id`, `idx_payment_link_id` per `TransactionRepository` docblock) — not dynamic JSON extraction in hot queries. This is the correct optimization for the 1M-daily-user target.

### 3.3 100k concurrent payment requests
- **Locking model is sound where it executes**: `GatewayApiService::handleCallback` and `RefundService::create` both wrap completion in a DB transaction with `SELECT ... FOR UPDATE` on the transaction row, serializing concurrent completion/refund of the same transaction and preventing double-credit/double-refund. The ledger posts a `FOR UPDATE` idempotency guard on `(merchant_id, reference_type, reference_id, description)` to prevent double-posting.
- **Scalability risk — FIND-002**: the refund path performs the **external gateway HTTP call inside the open DB transaction while holding the row lock** (`RefundService.php:170` inside the `transaction()` opened at L83). Under load this holds connections + locks for the full external-API latency, amplifying lock contention and risking `innodb_lock_wait_timeout`. The same anti-pattern should be checked in any synchronous gateway-call path.
- **Connection pooling**: PHP/PDO has no built-in pool; under 100k simultaneous hits the bottleneck is MySQL `max_connections` and PHP-FPM workers, not application logic. The app fails reasonably (per-request connection, exceptions surface as 500/429) but there is no queue/backpressure beyond the rate limiter. For true high volume, recommend a connection proxy (ProxySQL) + async webhook processing (already partially modeled via `op_webhook_deliveries`).

### 3.4 Normalization & integrity
Schema is normalized with FK constraints (e.g., `fk_mn_device` ON DELETE CASCADE, L650) and unique constraints on natural keys. Ledger uses the strict triple-table model (accounts/transactions/entries). No denormalization hazards observed in the sampled DDL.

---

## 4. The MFS SMS-Parsing Edge-Case Report

### 4.1 The Argument-Mismatch Finding (Quest 4.1) — **FIND-001 (HIGH, latent-critical, dead code)**
**Verified.** `SmsParserService::parse()` signature is `parse(string $rawMessage, string $sender, int $brandId)` (`src/Service/Sms/SmsParserService.php:465`). `MfsService::processIncomingSms()` calls it as `$this->parser->parse($sender, $body, $merchantId)` (`src/Service/Payment/MfsService.php:65`) — so `$rawMessage` receives the **sender** and `$sender` receives the **body**. This breaks both the template lookup (`findBySender($body, …)` matches nothing) and the body parse, silently routing every message to `admin_review`.

**Call-stack reality (why it is not currently catastrophic):** `processIncomingSms` has **zero callers**; `MfsService` appears only in the Composer classmap — never instantiated, type-hinted, container-bound, or routed. The **live** ingestion path (`POST /api/mobile/v1/sms` → `SmsController::receive` → `SmsParserService::processBatch` → `attemptParse($rawMessage, $sender, $brandId)`, L197) uses the **correct** order. So the bug is dormant dead code — but it is the class explicitly designed as the MFS orchestrator, so wiring it in (the obvious next step for a carrier-SMS-gateway path) silently detonates all auto-matching. Fix before enabling any non-device SMS path. Full detail in §10.

### 4.2 Unexpected characters / fallback (Quest 4.2): **PASS**
`SmsRegexParser` validates each pattern with `@preg_match($pattern,'') === false` before use (L45/75) → invalid regex skipped, never fatal. Flow: regex → (only if templates exist) heuristic → `null` → `match_status='admin_review'`. Unknown structures degrade gracefully; no unhandled errors.

### 4.3 Single- vs multi-word references (Quest 4.3)
Matching keys on **TrxID** and **amount**, not the free-text payer reference. The heuristic TrxID pattern is `[A-Z0-9]{5,20}` (`SmsHeuristicParser.php:39`), which fits bKash/Nagad-style IDs; a multi-word reference ("School Fee") simply is not captured as a TrxID, but it is not the match key, so a spaced reference does **not** break matching. Impact is therefore low. (Note the namespace caveat in §4.5.)

### 4.4 Spoofing & template bypass (Quest 4.6): **PASS (sound posture)**
- Sender identity is taken from the device-reported **carrier sender field** (`SmsController:116`), never from body text — embedding "bKash" in the body does not bypass `findBySender`.
- Payload is **AES-256-GCM** decrypted with a per-device key (`SmsParserService::decryptSmsPayload`, L417, IV(12)+ct+tag(16), key length validated).
- TrxID replay → double-credit is prevented by the matcher's state guard (`SmsVerificationJob.php:129` only completes a `pending` transaction; a replayed SMS finds it already `completed` → skipped).
- Amount-fallback matching (`TransactionRepository::findPendingMatch`, L652) only binds when **exactly one** pending transaction matches amount+gateway within a [-30min,+5min] window (`if ($count !== 1) return null`, L668); ambiguous amounts match neither.
- **Residual lead (FIND-018, INFORMATIONAL):** dedup (`SmsDataRepository::isDuplicate`, L52) keys on `(device_id, sender, ±1s, merchant_id)`, not `trx_id`; replay is mitigated by the status guard but there is no global `op_sms_parsed.trx_id` uniqueness.

### 4.5 Functional correctness lead — TrxID namespace (FIND-017, MEDIUM)
`op_transactions.trx_id` is OwnPay's own `OP-XXXX` value (`TransactionRepository::generateTrxId`, L41), whereas the SMS-parsed `trx_id` is the **MFS provider's** TrxID (seed `'TrxID ([A-Za-z0-9]+)'`, `sms_templates.sql:9`). These are different namespaces, so `SmsVerificationJob`'s primary `findByTrxId` match (L117) will rarely hit and matching leans on the amount fallback. Verify how the manual-checkout flow records the customer-entered provider TrxID; if it is not stored into a matched column, auto-matching is weaker than intended.

### 4.6 Device pairing & notifications (Quest 4.4 / 4.5): **PASS**
- Superadmin fallback resolves gracefully: OTP `created_by` → `$_SESSION` → first active superadmin for the merchant → `?? 1` (`DevicePairingService::pairDevice`, L223-238). No crash.
- UUIDs kept as strings (`NotificationController::index`, L50-52, "BUG-008 FIX"); acks scoped by `device_id` ("BUG-007 FIX", L63/92) → no UUID→0 bricking and no cross-device IDOR.

---

## 5. High-Concurrency & Redirect Flow Audit

### 5.1 Scenario A — gateway success, OwnPay shows pending/failed: **CONFIRMED via FIND-003**
The async webhook (`POST /webhook/{gateway}` → `UnifiedWebhookController::handle` → `GatewayApiService::handleCallback`) and the synchronous return path (`CheckoutController` callback handling) both call `Database::getInstance()` (`GatewayApiService.php:193`, `CheckoutController.php:796`), which **throws in production** (FIND-003). The webhook controller catches it and returns **HTTP 500** (`UnifiedWebhookController.php:154-161`), so the gateway's success notification never completes the transaction. **A customer who paid sees the order stuck pending/failed.** This is the textbook divergence the quest describes — and it is real, not hypothetical.

### 5.2 Scenario B — checkout success, merchant site marks failed (status tampering)
- **Mitigated at this layer**: completion uses the transaction's **stored** amount/fee (`GatewayApiService.php:213-215`), not the attacker-supplied callback amount, so there is no "$0.01 ledger credit" via the callback.
- **Webhook signature is enforced** before dispatch (`UnifiedWebhookController.php:86-99`: verify → false/throw → 403); 1MB body cap; slug regex; POST-over-GET precedence (AUD-A5) prevents query-param spoofing.
- **Gaps**: there is **no core-level check that the callback's reported paid amount equals the order amount** — it is delegated to each adapter's `verify()`. Strong adapters (e.g. Apple Pay) re-fetch and compare; weak/mock adapters (FIND-004/005) do not. And **replay/timestamp freshness is adapter-specific** (no nonce in the core controller; the txn state machine is the backstop).

### 5.3 Ledger & refund integrity (Quest 2.3/2.4/2.6): **PASS**
- Balanced-journal constraint enforced: `LedgerService::postEntries` sums debits/credits with bcmath and throws on imbalance (`LedgerService.php:102-104`); whole journal atomic in `db->transaction()`; double-post guarded by a `FOR UPDATE` existence check (L110-127).
- GAAP directionality correct: `LedgerRepository::adjustBalance` increases asset/expense on debit and liability/equity/revenue on credit, via atomic `balance = balance ± :amount` (L127-142).
- Refund: double `FOR UPDATE` (txn + refunds SUM), over-refund prevention (`newTotal > origAmount` → throw, L124-128), proportional fee, merchant-payable balance check (L147-149), and **negative/zero amount rejected at the service layer** (L120).

---

## 6. CLI, Hooks & Plugin Ecosystem Register

### 6.1 CLI infrastructure (Quest 3.1)
Only **two** CLI scripts exist and both appear functional: `cli/build-update.php` (update builder — RSA signing, zip packaging, manifest) and `cli/create-module.php` (scaffolder). **There is no dedicated `system-update` or `currency-rate-update` CLI**; those are handled via `src/Update/UpdateService` (admin-driven) and cron jobs respectively. Not a defect per se, but the operator-facing CLI surface is thinner than the quest implies — document this for ops.

### 6.2 Event / filter layer (Quest 3.2): mostly secure
`EventManager` provides actions (fire-and-forget) and filters (pipeline). Strengths: per-listener try/catch error isolation; owner-stack push/pop in `finally`; re-entrancy guard; **brand-scoped activation** (`isOwnerActive`); and a sandbox re-check of any **plugin-owned** `db.query.before` SQL mutation that throws if it touches core tables (`EventManager.php:317-330`) — this closes the obvious plugin SQL-tamper vector. Weaknesses: filters are **untyped** (consumers must validate; core does, e.g. `Kernel.php:54`), and a plugin with a **null sandbox** bypasses the SQL re-check (FIND-009). `AUD-21` (`Kernel.php:182-193`) re-adds `Session/Csrf/Permission` middleware if a plugin removes them — good defense.

### 6.3 Mock Plugin Registry (Quest 3.3)
Reproducible grep across all ~140 adapters (`modules/gateways/*`):
- **116 adapters call `curl_exec`** (real outbound HTTP). **Zero** adapters disable SSL verification, and **none** use `shell_exec`/`system`/`eval`/raw SQL.
- **~40+ adapters** carry `// Emulate fallback visual window for simulated checkout` and auto-confirm in **sandbox** mode (live mode throws) — e.g. 2Checkout `initiate`/`verify` throw in live (L152/168/209). **Risk: sandbox/misconfig only (MEDIUM).**
- **`mock_`-token family** (affirm, afterpay, amazon-pay, apple-pay, google-pay, bitpay, braintree, gocardless):
  - **Correctly gated (SAFE):** `apple-pay` (L181) and `google-pay` (L181) return *failed* when `mode==='live'` and use real HMAC webhook verification.
  - **UN-GATED (CRITICAL — FIND-004):** `affirm` (L156), `afterpay` (L145), `bitpay` (L132) accept a `mock_` token in **any** mode and pair it with a no-op `verifyWebhook(): return true`.
- **~24 adapters have no `curl_exec`** (e.g. `rocket`, `ccavenue`, `easypaisa`, `jazzcash`, `alipay`, `payu`, `mobikwik`) — pure skeleton/redirect-only; each needs a per-file go-live review (FIND-013).
- **Refund stubs (FIND-005):** several `refund()` methods are "simulations" returning success without calling the provider (e.g. 2Checkout `refund`, L308-315) — OwnPay would mark a refund complete and debit the ledger while no money is returned at the gateway.

**Bottom line:** the fleet is **not uniformly production-ready**. A go-live gate per gateway (real `verify`/`verifyWebhook`/`refund`, no un-gated mock path) is required.

---

## 7. Security Attack-Surface & Mitigation Matrix

| Vector (Quest 6 / 11) | Status | Evidence |
|---|---|---|
| SQL injection | **MITIGATED** | PDO `ATTR_EMULATE_PREPARES=false`, typed `bindValue` (`Database.php:245-256`); `sanitizeColumn` identifier regex; table-name regex in `exists/count`. No string-interpolated user SQL found. `cursorPaginate` raw `$where` is **unused** (dead). |
| Webhook signature spoofing | **MITIGATED (core)** / **adapter-dependent** | `UnifiedWebhookController.php:86-99` verifies before dispatch (403 on fail/throw). Strength varies per adapter (FIND-005). |
| SMS ingestion spoofing | **MITIGATED** | JWT device auth + GCM + carrier-sender field + count==1 match guard (§4.4). |
| Custom-domain / webhook SSRF | **MITIGATED (IPv4)** | `UrlValidator::isValidWebhookUrl` enforces HTTPS, resolves DNS, blocks all private/reserved IPs incl. `169.254.169.254`. Gaps: DNS-rebind TOCTOU + IPv6 AAAA (FIND-008). |
| Mass assignment / privilege escalation | **MITIGATED** | `BaseRepository::filterFillable` allowlist; `updateScoped` unsets `merchant_id`. 3 repos lack `$fillable` but pass only internal arrays (not request data). |
| XSS | **MITIGATED** | Twig `autoescape=html` (`TwigFactory:98`); `|raw` only on hook/extension output. |
| CSRF | **MITIGATED** | STP + `hash_equals` + rotating pool (`CsrfMiddleware`); `/api/*` exclusion is safe (all bearer/JWT). |
| File upload | **MITIGATED (strong)** | extension allowlist + finfo MIME match + SVG sanitization + random filename + traversal guard (`FilesystemService`). |
| Auth / brute force | **MITIGATED** | Argon2id; `session_regenerate_id(true)`; lockout 5/300s (email+IP); `Request::ip()` trusts XFF only behind `TRUSTED_PROXIES`. |
| 2FA / TOTP | **MITIGATED** | replay-guarded window, `hash_equals` (`Authenticator::verifyCodeWithReplayGuard`). |
| JWT | **MITIGATED** | HS256 hardcoded (no alg-confusion), exp enforced, CSPRNG `jti`, secret ≥32 enforced at boot. |
| HTTP security headers / CORS | **MITIGATED** | CSP+nonce, HSTS, XFO DENY, nosniff, Referrer/Permissions-Policy (global); CORS default-deny, wildcard forces `Allow-Credentials:false`. |
| Info disclosure | **MITIGATED** | `APP_DEBUG=false` default; `Kernel::handleException` hides traces in prod, sanitizes paths in debug. |
| Rate-limit availability | **WEAK** | **fail-open** on DB exception (FIND-007). |
| Payment-confirmation bypass | **VULNERABLE** | un-gated mock token (FIND-004). |
| Refund/callback execution | **BROKEN** | `getInstance()` throw (FIND-003). |

---

## 8. Low-Resource Shared-Hosting Suitability Sheet

**Grade for 512MB RAM / 1 vCPU / 1GB disk: B− (workable with caveats).**
- **Dependencies are lean** (`composer.json`): twig, firebase/php-jwt, ramsey/uuid, vlucas/phpdotenv, chillerlan/php-qrcode — no Laravel/Symfony full-stack weight. Per-request bootstrap is modest.
- **Cache/queue default to file** (`FileCache`, file queue) with graceful Redis fallback (`config/services.php:118-136`) — no Redis requirement. Good for shared hosting.
- **Container uses Reflection autowiring** (`Container::autowire`) — cheap at OwnPay's service count, but services are registered as **singletons** in `config/services.php`, so reflection cost is largely one-time per request.
- **Twig** must have compiled-template caching enabled in production (verify `TwigFactory` cache path is writable + not `auto_reload` in prod) to avoid recompiling 79 templates per request.
- **Constraints / risks**: `update/` builder and some ops assume shell availability (zip, RSA) — the *runtime* does not, but the *update CLI* does. No daemon/symlink dependency in the request path. The MFS batch (`processBatch`) processes messages in a simple loop — bounded by device batch size, unlikely to exceed 512MB.
- **Optimizations recommended**: enable OPcache; aggressive Twig compile cache; lazy-load the QR-code library only on invoice/checkout paths; cap cURL timeouts (already 10-15s in adapters); prune unused gateway modules from `modules/gateways/` on small deployments to shrink plugin discovery.

---

## 9. Architectural & Structural Correction Guide

OwnPay is pre-release, so these are feasible now:

1. **Eliminate the service-locator anti-pattern that caused FIND-003.** `Database::getInstance()` is a static service locator that is never initialized in the production boot path. **Inject `Database` via the DI container** into `RefundService`, `GatewayApiService`, `CheckoutController` (and `UpdateService`/`HealthChecker`/`BackupService` which already accept an injected `$db`), and **delete `getInstance()`** or make the container factory call `Database::setInstance()` (add such a setter) so the singleton is genuinely populated. This single change closes a CRITICAL.
2. **Normalize the gateway adapter contract.** Introduce an abstract base (or trait) enforcing: no mock path in `live` mode; `verifyWebhook` must implement a real constant-time signature check (no `return true`); `refund` must call the provider or throw `NotSupported`. Add a CI conformance test that greps every adapter for `return true;` in `verifyWebhook` and `mock_` acceptance without a `mode==='live'` guard. This closes FIND-004/005 fleet-wide and prevents regressions.
3. **Move external I/O outside DB transactions** (FIND-002): in `RefundService`, create the refund row + compute limits inside the transaction, then call the gateway *after* committing, and reconcile status in a second short transaction (saga pattern). Prevents lock-held-during-network-latency.
4. **Add a callback amount-verification layer** in `GatewayApiService::handleCallback`: when an adapter returns an `amount`, assert it equals the stored order amount (±0.01) before completing — defense-in-depth even for trusted adapters.
5. **Fail-closed rate limiting / lockout** (FIND-007): on DB/Redis outage, deny auth-sensitive routes (login/2fa/pairing) rather than skipping the limiter.
6. **SSRF hardening** (FIND-008): pin the resolved IP for the actual outbound request (`CURLOPT_RESOLVE`) and resolve AAAA records, to close DNS-rebinding and IPv6 gaps.
7. **Guarantee a sandbox for every plugin** (FIND-009) so the `db.query.before` SQL re-check can never be skipped; or refuse to run `db.query.before` filters whose owner has no sandbox.
8. **Custom-framework verdict**: the bespoke framework is a *defensible* choice for a self-hosted, tenant-isolated, low-resource gateway — it gives direct lifecycle control, a small dependency surface (good for `composer audit`), automatic `merchant_id` scoping at the repository layer, and a centralized error mask. The cost is exactly what this audit found: hand-rolled bootstrap wiring is where the CRITICAL slipped in (FIND-003), and security depends on every adapter author following the contract (FIND-004). Mitigate with the conformance tests above and a fixable DI discipline, not a rewrite. (Full UI/UX + framework deep-dive is **Deliverable 2: DESIGN.md**.)

---

## 10. Detailed Findings

---
### [CRITICAL] [Ledger & Payments] FIND-003 — `Database::getInstance()` throws in production: refunds and gateway callbacks broken
- **Severity**: CRITICAL
- **Quest**: Ledger & Payments (Quest 2)
- **Dimension**: Improper initialization / service-locator wiring defect → availability + financial integrity
- **Location**: `src/Core/Database.php:126-132` (`getInstance`), set only by `init()` at `:116`. Unconditional callers: `src/Service/Payment/RefundService.php:80`, `src/Service/Payment/GatewayApiService.php:193`, `src/Controller/Checkout/CheckoutController.php:796`. Boot path that fails to set it: `src/Kernel.php:79-235` (`boot()`), `config/services.php:106-108` (factory `new Database($pdo)`) and `:350-363` (ineffective "eager-boot").
- **Description**: The `Database` singleton (`self::$instance`) is assigned **only** inside `Database::init()`, which is called **only** in `tests/Integration/IntegrationTestCase.php`. Production boot resolves `Database` via the container factory `new Database($pdo)` whose constructor does **not** set the static. The "eager-boot" block calls `$c->get(Database::class)` — which also does not populate the static. Therefore `Database::getInstance()` always throws `RuntimeException('Database::init() must be called before getInstance().')` in production.
- **Root Cause**: A static service-locator (`getInstance`) retained alongside DI, with the initialization step lost during refactor. The eager-boot author assumed constructing the wrapper populates the singleton; it does not.
- **Evidence** (constructor does not set the static; getInstance throws):
```php
// src/Core/Database.php
public function __construct(PDO $pdo) { $this->pdo = $pdo; }            // L53-56 — no self::$instance
public static function getInstance(): self {
    if (self::$instance === null) {
        throw new \RuntimeException('Database::init() must be called before getInstance().'); // L129
    }
    return self::$instance;
}
// config/services.php:106-108
$c->singleton(\OwnPay\Core\Database::class, static function ($c) {
    return new \OwnPay\Core\Database(ensureType($c->get(\PDO::class), \PDO::class)); // never init()
});
// src/Service/Payment/GatewayApiService.php:193
$db = \OwnPay\Core\Database::getInstance();   // throws at runtime
```
- **Empirical proof** (read-only boot-replica probe run this engagement; artifact deleted after use):
```
installed_lock=YES
container_db=OwnPay\Core\Database          # DI-injected code works
getInstance_THROWS=Database::init() must be called before getInstance().
```
- **Attack Path / Failure Scenario**: (1) Customer completes payment at gateway. (2) Gateway POSTs success webhook to `/webhook/{gateway}`. (3) `UnifiedWebhookController::handle` calls `GatewayApiService::handleCallback` → `Database::getInstance()` throws → caught at `UnifiedWebhookController.php:154` → **HTTP 500**. (4) Transaction is never marked `completed`; gateway retries → 500 every time. Parallel impact: `RefundService::create` throws immediately → **no refund can ever be issued**.
- **Impact**: Direct financial harm — customers pay but orders never complete (chargebacks, lost sales, support load); merchants cannot refund (compliance/relationship damage). Core money path non-functional. Release-blocking.
- **Proposed Technical Fix** (preferred — DI injection):
```php
// GatewayApiService / RefundService / CheckoutController: accept Database via constructor
public function __construct(/* existing */, \OwnPay\Core\Database $db) { $this->db = $db; }
// replace: $db = \OwnPay\Core\Database::getInstance();
//   with:  $db = $this->db;
// config/services.php — add Database to each service's factory args.
```
```php
// Minimal stop-gap (keep locator working): populate the singleton in the factory.
// Add to Database.php:
public static function setInstance(self $i): void { self::$instance = $i; }
// config/services.php Database factory:
$db = new \OwnPay\Core\Database(ensureType($c->get(\PDO::class), \PDO::class));
\OwnPay\Core\Database::setInstance($db);
return $db;
```
- **Technical Decision**: DI injection aligns with the framework's PSR-11 lifecycle and removes hidden global state; the stop-gap restores correctness immediately if a full refactor is deferred. Either way, the container's `Database` and `getInstance()` must return the **same** instance so nested-transaction reentrancy and `FOR UPDATE` semantics hold on one connection.
- **Compliance Tag**: CWE-665 (Improper Initialization); OWASP-A04 (Insecure Design); PCI-DSS (transaction integrity).

---
### [CRITICAL] [SMS/Gateways] FIND-004 — Un-gated mock-token payment-confirmation bypass in gateway `verify()`
- **Severity**: CRITICAL
- **Quest**: Ledger & Payments / Plugin Ecosystem (Quest 3)
- **Dimension**: Authentication/verification bypass (improper verification)
- **Location**: `modules/gateways/affirm/AffirmGateway.php:130-133, 156-162, 214-219`; `modules/gateways/afterpay/AfterpayGateway.php:119-122, 145-151, 195-198`; `modules/gateways/bitpay/BitpayGateway.php:132-138`. Contrast (safe): `modules/gateways/apple-pay/ApplePayGateway.php:180-187, 251-286`; `google-pay/GooglePayGateway.php:180-186`.
- **Description**: These adapters generate a `mock_`-prefixed token in `initiate()` when the real API returns nothing (no `mode` guard, so it triggers in **live** too), and their `verify()` returns `success/status=completed` for any `mock_`-prefixed token **without checking `mode==='live'`**. Their `verifyWebhook()` is `return true` (no signature validation). Apple/Google Pay implement the same mock concept correctly — they return *failed* in live and verify real HMAC — proving the un-gated versions are defects, not design.
- **Root Cause**: Per-adapter copy-paste with inconsistent hardening; the "local integration testing" shortcut left enabled in live mode.
- **Evidence**:
```php
// affirm/AffirmGateway.php
if ($redirectUrl === '') {                                   // L130 — fires in live too
    $checkoutId = 'mock_aff_' . uniqid();
    $redirectUrl = $params['redirect_url'].'?checkout_token='.$checkoutId.'&trx_id='.urlencode($trxId);
}
public function verify(array $callbackData, array $credentials): array {
    $token = $this->getString($callbackData['checkout_token'] ?? $callbackData['checkout_id'] ?? null);
    if (str_starts_with($token, 'mock_')) {                  // L156 — NO mode==='live' check
        return ['success' => true, 'gateway_trx_id' => $token, 'status' => 'completed'];
    } ...
}
public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool {
    return true;                                             // L218 — no-op
}
```
- **Attack Path / Scenario**: With a pending transaction on `affirm`/`afterpay`/`bitpay`, an attacker hits the return/callback (or `/webhook/affirm`) with `checkout_token=mock_x` (afterpay: `orderToken=mock_x`) + the victim `trx_id`. `verifyWebhook` returns true; `verify` sees the `mock_` prefix → `completed`; `handleCallback` marks the transaction paid and credits the merchant ledger — **with no real funds**. No-attacker variant: in live, if the gateway API is unreachable or credentials are wrong, `initiate()` emits a `mock_` token and the customer's return auto-confirms a $0-funded "success". (Reachability is currently gated by FIND-003; fixing FIND-003 without fixing this turns it into a live exploit.)
- **Impact**: Free payment confirmation / fraudulent order completion; merchant ledger overstated vs. actual funds. Catastrophic for a payment gateway.
- **Proposed Technical Fix**:
```php
// In every adapter verify(): gate the mock path to non-live, like Apple/Google Pay already do.
if (str_starts_with($token, 'mock_')) {
    if (($credentials['mode'] ?? 'sandbox') === 'live') {
        return ['success' => false, 'status' => 'failed'];          // never auto-confirm in live
    }
    return ['success' => true, 'gateway_trx_id' => $token, 'status' => 'completed'];
}
// Remove the un-gated mock fallback in initiate(): in live, throw instead of emitting mock_ tokens.
// verifyWebhook(): implement a real constant-time HMAC check; never `return true`.
```
- **Technical Decision**: Mirrors the already-correct Apple/Google Pay implementation; enforce via the abstract adapter contract + CI conformance test (Section 9.2).
- **Compliance Tag**: CWE-287 (Improper Authentication) / CWE-345 (Insufficient Verification of Data Authenticity); OWASP-A07; PCI-DSS.

---
### [HIGH] [SMS Engine] FIND-001 — MfsService passes parser arguments in swapped order (latent-critical dead code)
- **Severity**: HIGH (latent CRITICAL; currently dormant)
- **Quest**: SMS Engine (Quest 4)
- **Dimension**: Parameter mismatch / dead code
- **Location**: `src/Service/Payment/MfsService.php:63-65` vs `src/Service/Sms/SmsParserService.php:465-468`.
- **Description / call-stack**: Signature `parse(string $rawMessage, string $sender, int $brandId)`; call `parse($sender, $body, $merchantId)`. `processIncomingSms` has zero callers and `MfsService` is never instantiated/bound (Composer classmap only), so it is dead code; the live device path uses the correct order (`SmsParserService::processBatch`→`attemptParse`, L197).
- **Root Cause**: Argument order not reconciled with the parser signature in an orchestrator class that was never wired in.
- **Evidence**:
```php
// SmsParserService.php:465
public function parse(string $rawMessage, string $sender, int $brandId): ?array
// MfsService.php:63-65
public function processIncomingSms(int $merchantId, string $sender, string $body, string $deviceId): array {
    $parsed = $this->parser->parse($sender, $body, $merchantId);   // $rawMessage<-sender, $sender<-body
```
- **Attack Path / Scenario**: If wired into a carrier-SMS-gateway ingestion path (its documented purpose), `findBySender($body,…)` matches no template → `attemptParse` returns null → every MFS payment silently routes to `admin_review`; customers pay but are never auto-credited. Silent (no exception).
- **Impact**: Total breakage of automatic MFS matching for any path using this class. Currently latent.
- **Proposed Technical Fix**:
```php
$parsed = $this->parser->parse($body, $sender, $merchantId);  // correct order
```
Additionally: remove `MfsService` if superseded by `SmsVerificationJob`, or add a unit test pinning the parameter order before wiring it.
- **Technical Decision**: Either delete dead code or fix+test it; do not leave a silent-failure trap in the MFS subsystem.
- **Compliance Tag**: CWE-628 (Incorrect Argument Order); CWE-561 (Dead Code).

---
### [HIGH] [Gateways] FIND-005 — Gateway `verifyWebhook()`/`refund()` stubs (no-op verification, simulated refunds)
- **Severity**: HIGH
- **Quest**: Plugin Ecosystem (Quest 3)
- **Dimension**: Insufficient verification / non-functional financial operation
- **Location**: e.g. `affirm/AffirmGateway.php:214-219` (`verifyWebhook` returns true); `afterpay/AfterpayGateway.php:195-198` (returns true); `2checkout/TwoCheckoutGateway.php:241-259` (checks only header *presence*) and `:308-315` (`refund` "simulation" always success).
- **Description**: Across the fleet, multiple adapters implement `verifyWebhook` as `return true` (or header-presence only) and `refund` as a fake success. Combined with the core controller delegating signature verification to the adapter, these gateways accept unsigned webhooks; and refunds appear successful in OwnPay (ledger debited) while no provider refund occurs.
- **Evidence**:
```php
// 2checkout/TwoCheckoutGateway.php:308-315
public function refund(string $gatewayTrxId, string $amount, array $credentials): array {
    return ['success' => true, 'refund_id' => 'REF_'.$this->slug().'_'.uniqid()]; // no provider call
}
```
- **Attack Path / Impact**: Unsigned webhook acceptance (status manipulation for gateways that don't also hit FIND-004); refund ledger/provider divergence (customer not actually refunded, or merchant balance wrong). 
- **Proposed Technical Fix**: Implement real signature verification per provider spec with `hash_equals`; make `refund` call the provider API or return an explicit unsupported error. Gate with the conformance test in Section 9.2.
- **Compliance Tag**: CWE-345; PCI-DSS (refund integrity).

---
### [MEDIUM] [Concurrency] FIND-002 — External gateway HTTP call executed inside a DB transaction holding `FOR UPDATE`
- **Severity**: MEDIUM (HIGH under sustained load)
- **Quest**: Ledger & Payments (Quest 2)
- **Dimension**: Race condition / lock contention / availability
- **Location**: `src/Service/Payment/RefundService.php:83-207` (transaction) wrapping `:170` (`$this->bridge->refund(...)`).
- **Description**: The refund DB transaction opens, locks the transaction row + sums refunds `FOR UPDATE`, then performs the **external** gateway refund HTTP call before committing. The row lock and connection are held for the full network latency.
- **Evidence**:
```php
$db->transaction(function () use (...) {
    $txn = $db->fetchOne("... FOR UPDATE", ...);             // L86 lock held
    ...
    $result = $this->bridge->refund($gwSlug, $merchantId, $gwTrxId, (string)$amount); // L170 external HTTP under lock
    ...
});
```
- **Impact**: Under concurrency, refund attempts on the same transaction serialize behind a network round-trip; risk of `innodb_lock_wait_timeout`, connection-pool pressure, and degraded throughput at scale.
- **Proposed Technical Fix**: Split into (1) reserve+validate inside a short transaction, (2) call the gateway outside the transaction, (3) finalize status + ledger in a second short transaction (saga). 
- **Compliance Tag**: CWE-667 (Improper Locking); OWASP-A04.

---
### [MEDIUM] [Tooling/Release] FIND-006 — Test suite unrunnable on the project's own minimum PHP (8.2)
- **Severity**: MEDIUM
- **Quest**: Low-Resource / Release readiness (Quest 9 / Section 3)
- **Dimension**: CI/release process defect
- **Location**: `composer.json:7` (`"php": "^8.2"`) vs `:37` (`"phpunit/phpunit": "^12.5"`). PHPUnit 12 requires PHP ≥ 8.3.
- **Evidence**: Running `vendor/bin/phpunit` on PHP 8.2.12 → `This version of PHPUnit requires PHP >= 8.3.` (exit 1).
- **Impact**: The full automated test tier cannot execute on the supported minimum PHP or this XAMPP environment; CI on 8.2 is broken; regressions (like FIND-003) ship undetected. Production `--no-dev` is unaffected.
- **Proposed Technical Fix**: Either raise the supported floor to `"php": "^8.3"` consistently (README, installer requirement check at `InstallerController.php:668`), or pin `"phpunit/phpunit": "^11.5"` (PHP 8.2-compatible).
- **Compliance Tag**: CWE-1104 (Use of Unmaintained/!mismatched Components); OWASP-A06.

---
### [MEDIUM] [Auth/Availability] FIND-007 — Rate limiter fails open on database error
- **Severity**: MEDIUM
- **Quest**: Auth/Session (Quest 11)
- **Dimension**: Fail-open security control
- **Location**: `src/Middleware/RateLimiterMiddleware.php:115-119`.
- **Evidence**:
```php
} catch (\PDOException|\RuntimeException $e) {
    $this->logWarning('Rate limiter skipped: ' . $e->getMessage());
    return $next($request);   // proceeds with NO limiting
}
```
- **Impact**: During a DB outage (or induced DB pressure), login/2FA/pairing brute-force protection is disabled (the `Authenticator` lockout table is also DB-backed, so both layers fail together).
- **Proposed Technical Fix**: For auth-sensitive routes, fail **closed** (return 503) when the limiter backend is unavailable; keep fail-open only for non-sensitive read paths.
- **Compliance Tag**: CWE-636 (Not Failing Securely); OWASP-A04.

---
### [MEDIUM] [Plugins] FIND-009 — Plugin with no sandbox bypasses `db.query.before` SQL validation
- **Severity**: MEDIUM
- **Quest**: Plugin Ecosystem (Quest 3)
- **Dimension**: Incomplete sandbox enforcement
- **Location**: `src/Event/EventManager.php:317-330` (`$sandbox !== null && !$sandbox->validateSql(...)`), with `src/Core/Database.php:224-237` skipping the check when `activeOwner==='core'`.
- **Description**: Plugin-owned `db.query.before` SQL mutations are re-validated only if `PluginRegistry::getSandbox($owner)` returns non-null. A plugin without an assigned sandbox slips past the SQL re-check.
- **Impact**: A malicious/buggy plugin could rewrite queries (incl. core financial queries) to reach core tables, if it can register a `db.query.before` filter and has no sandbox. (Mitigated in practice if PluginRegistry always assigns sandboxes — verify.)
- **Proposed Technical Fix**: Treat a null sandbox as deny — refuse to run `db.query.before` filters for owners without a sandbox, or assign a default-deny sandbox to every plugin at load.
- **Compliance Tag**: CWE-749 (Exposed Dangerous Method); OWASP-A04.

---
### [MEDIUM] [Payments] FIND-016 / FIND-017 — Callback amount not verified against order; SMS TrxID namespace mismatch
- **Severity**: MEDIUM (grouped business-logic findings)
- **Quest**: Checkout & Payments (Quest 5 / Quest 4)
- **FIND-016 (Callback amount)** — `GatewayApiService::handleCallback` (`:211-227`) completes using the **stored** order amount and never asserts the callback's reported paid amount matches it; verification is wholly delegated to adapter `verify()`. Strong adapters (apple-pay) compare; mock/weak adapters do not. **Fix**: add a ±0.01 amount assertion in `handleCallback` when the adapter returns an amount. *(CWE-345)*
- **FIND-017 (TrxID namespace)** — `op_transactions.trx_id` is OwnPay's `OP-XXXX`; SMS-parsed `trx_id` is the provider's TrxID, so `SmsVerificationJob::run` primary match (`:117`) rarely hits and relies on amount-fallback. **Fix**: store the customer-submitted provider TrxID into a matched column and key matching on it. *(CWE-708 / functional correctness)*
- **Compliance Tag**: OWASP-A04; PCI-DSS.

---
### [LOW] FIND-008 — Webhook SSRF: DNS-rebinding TOCTOU + IPv6 AAAA not resolved
- **Location**: `src/Security/UrlValidator.php:145-184` (`gethostbynamel` is IPv4-only; validation precedes the curl call without IP pinning).
- **Impact**: A low-TTL attacker-controlled domain could pass validation then rebind to an internal IP at fetch time; an AAAA-only host bypasses the IPv4 resolver.
- **Fix**: resolve+pin the IP with `CURLOPT_RESOLVE`; resolve AAAA and apply the same private/reserved checks. *(CWE-918)*

### [LOW] FIND-010 — `DomainMiddleware` hardcodes `localhost` passthrough (Host-header trust)
- **Location**: `src/Middleware/DomainMiddleware.php:72`. `Host: localhost` (client-controlled) bypasses brand-scope resolution and is treated as master domain. Admin remains auth-gated, so impact is low; recommend matching `localhost` only when `REMOTE_ADDR` is loopback. *(CWE-290)*

### [LOW] FIND-011 — Invoice totals can go negative (no positivity clamp)
- **Location**: `src/Service/Payment/InvoiceService.php:142,152,218,227`. `unit_price`/`discount` are not clamped to ≥0; an admin can create a negative-total invoice. Self-inflicted (admin-created), low risk. **Fix**: `max(0, …)` and cap discount ≤ subtotal+tax. *(CWE-20)*

### [LOW] FIND-014 — `form_html` sanitizer keeps inline `<script>`
- **Location**: `src/Service/Payment/GatewayApiService.php:249-271`. Strips `on*`/`javascript:`/external scripts but preserves inline `<script>` (for gateway auto-submit). A malicious gateway *plugin* could inject inline JS; gateway plugins are owner-installed (trusted), so defense-in-depth only. *(CWE-79)*

### [LOW] FIND-015 — Notification temp-file fallback (shared, unlocked)
- **Location**: `src/Service/Notification/MobileNotificationService.php:226-239`. When `repo` is null, notifications (amounts/TrxIDs) are written to `sys_get_temp_dir()/op_notifications.json` without locking — info-leak/race if that path is ever live. **Fix**: require the DB-backed repo; remove the temp-file path. *(CWE-377)*

### [INFORMATIONAL] FIND-012 / FIND-013 / FIND-018
- **FIND-012** — `Authenticator` TOTP drift window is ±2 steps (±60s); consider ±1 for tighter replay surface.
- **FIND-013** — ~24 gateways have no `curl_exec` (skeleton/redirect-only); `TransactionRepository::findPendingMatchGlobal` (unscoped, cross-brand) is dead code — remove or gate. Each skeleton gateway needs a go-live review.
- **FIND-018** — SMS dedup keys on `(device_id,sender,±1s,merchant_id)` not `trx_id`; replay is mitigated by the txn status guard, but a global `op_sms_parsed.trx_id` uniqueness would be more robust.

---

## 11. Pass Log
Checks that passed with a one-line verification note:

| Area | Verdict | Note |
|---|---|---|
| Tenant isolation (Q1.1) | PASS | `TenantScope` clone + `requireTenant()` throws; `updateScoped` unsets `merchant_id`. |
| Custom-domain resolution (Q1.2) | PASS | unknown/inactive→404, `dns_verified=0`→503 (`DomainMiddleware:88-95`). |
| Admin block on custom domains (Q1.3) | PASS | `/admin*`→404 on brand domains (`:101`). |
| Ledger balance constraint (Q2.3) | PASS | debit==credit enforced + double-post guard (`LedgerService:102,110`). |
| GAAP directionality (Q2.4) | PASS | `adjustBalance` correct per account type (`LedgerRepository:127-142`). |
| Refund atomicity/over-refund/negative (Q2.6) | PASS | double `FOR UPDATE`, over-refund + balance + >0 checks (`RefundService`). |
| SMS graceful fallback (Q4.2) | PASS | regex validated before use; null→admin_review. |
| SMS spoof posture (Q4.6) | PASS | sender from carrier field; GCM; count==1 match guard. |
| Device pairing fallback (Q4.4) | PASS | graceful superadmin `?? 1`. |
| Notification UUID/IDOR (Q4.5) | PASS | UUID kept string; ack scoped by device. |
| Invoice recalculation (Q5.1) | PASS | bcmath recalc; old items purged; no orphans. |
| Manual gateway logo path (Q5.2) | PASS | `/storage/` prefix applied. |
| Clipboard fallback (Q5.3) | PASS | execCommand + `navigator.clipboard` (HTTP-safe). |
| SQL injection (Q6.5) | PASS | prepared statements + identifier/table sanitization. |
| Webhook signature enforcement (Q6.1, core) | PASS | verified before dispatch, 403 on fail. |
| Mass assignment (Q6.4) | PASS | fillable allowlist; `merchant_id` immutable on update. |
| XSS (Q6) | PASS | Twig `autoescape=html`; `|raw` only on hook output. |
| File upload (Q6) | PASS | ext allowlist + finfo MIME + SVG sanitize + random name + traversal guard. |
| CSRF (Q11) | PASS | STP + `hash_equals` + rotation; `/api/*` bearer-only exemption safe. |
| Password hashing (Q11) | PASS | Argon2id 65536/4. |
| Session fixation (Q11) | PASS | `session_regenerate_id(true)` on login; logout destroys cookie. |
| 2FA replay (Q11) | PASS | windowed `verifyCodeWithReplayGuard` + `hash_equals`. |
| JWT alg confusion (Q11) | PASS | HS256 hardcoded; exp enforced; secret≥32 at boot. |
| HTTP security headers / CSP (Q11) | PASS | global CSP+nonce, HSTS, XFO, nosniff, Referrer/Permissions-Policy. |
| CORS (Q11) | PASS | default-deny; wildcard forces `Allow-Credentials:false`. |
| Brute-force / XFF (Q11) | PASS | lockout 5/300s; `Request::ip()` trusts XFF only behind `TRUSTED_PROXIES`. |
| SSRF private-IP/metadata block (Q6, IPv4) | PASS | `UrlValidator` HTTPS + DNS + `169.254.169.254` blocked. |
| Schema column compliance (Q7.3) | PASS | all required names exact. |
| Stored generated columns (Q7.4) | PASS | `invoice_id`/`payment_link_id` STORED + indexed. |
| Installer DB-independence/lock/parse_ini (Q8) | PASS | minimal install middleware; `.installed` lock; no `parse_ini_file`; CSPRNG keys. |
| Plugin SQL sandbox on `db.query.before` (Q3.2) | PASS (with FIND-009 caveat) | re-validated when sandbox present. |
| Static analysis (PHPStan L9) | PASS | zero errors. |
| Dependency advisories (composer/npm audit) | PASS | zero advisories. |
| Web exposure of secrets | PASS | `/.env`, schema, logs → 403/404. |

---

*End of Deliverable 1. Deliverables 2–4 (DESIGN.md, mobile_architecture.md, mobile_design.md) are produced after review per the agreed sequencing.*
