# Adversarial Production Audit - Progress

Plan: C:\Users\iamna\.claude\plans\own-pay-peaceful-stearns.md
Baseline (Phase 0, 2026-06-12): HEAD 99f6d6a (branch: fixing), composer test OK (476 tests / 1527 assertions / 1 skipped), phpstan OK (363 files, 0 errors), twig lint OK (79 files clean).

## Phase 1 - Confirmed fixes

- [x] F1 webhook idempotency TOCTOU + completion race (HIGH) - DONE:
  - migration 009 (VIRTUAL dedup_key + uk_inbound_dedup; STORED fails errno 1215 on FK tables in MySQL 9) + schema.sql mirror; applied to ownpay_test
  - WebhookInboundProcessor: INSERT-first dedup (catch 1062), FOR UPDATE locks in handlePaymentCompleted/handleRefundCompleted, refund amount validation (>0, <= original)
  - NEW BUG FOUND+FIXED (F11, HIGH): AuditLogger::log() called with mis-aligned args (suppressed via @phpstan-ignore) → every successfully processed webhook threw TypeError → marked 'failed' + error returned to sender after money moved. Fixed both call sites.
  - NEW BUG FOUND+FIXED (F10, HIGH): UpdateService::splitSqlStatements dropped statements starting with '--' comment → migration 008 silently skipped on updated deployments (marked executed, DDL never ran). Fixed + 4 unit tests. Report: deployed instances must verify provider_trx_id column exists.
  - NEW BUG FOUND+FIXED (F12, MED): TransactionService::fail() overwrote metadata JSON (destroying invoice_id/payment_link_id generated-column linkage) → now JSON_MERGE_PATCH merge.
  - TransactionRepository: markCompletedIfNotTerminal + markStatusIfNotTerminal (replaces unconditional markCompleted); TransactionService complete/fail/cancel skip events/audit on no-op → hardens 60+ gateway adapter call sites
  - Admin TransactionController: state machine enforced (no complete/cancel of terminal; refund only from completed - failed→refunded previously fabricated ledger entries)
  - tests: 8 integration (WebhookIdempotencyTest) + 4 unit (splitter); full suite 488 green, phpstan clean
- [x] F2 installer re-arm hardening (HIGH) - DONE:
  - InstallerController: isInstalled() now also probes the configured DB for an existing superadmin when marker missing; self-heals storage/.installed; INSTALL_FORCE_KEY (>=16 chars, X-Install-Force-Key header, hash_equals) escape hatch for deliberate reinstalls; documented in .env.example
  - config/middleware.php: RateLimiterMiddleware added to 'install' group (verified fail-open pre-DB via caught RuntimeException from container; fail-closed only for login/device routes)
  - tests: 5 integration (InstallerLockTest) incl. schema-overwrite refusal + fresh-install fail-open
- [x] F4 float-cast on money, 24 sites / 23 gateway adapters (MED) - DONE:
  - GatewayDefaults::toMinorUnits() + toDecimalString() with strict regex+is_numeric validation (rejects negatives/scientific/arrays/oversized); all 24 sites mechanically replaced; php -l clean on all modules; 7 unit tests incl. DECIMAL(15,2) max exactness
- [x] F7 refund reconciliation cron (MED) - DONE: src/Cron/RefundReconciliationJob.php (24h auto-fail, FOR UPDATE re-check, audit log + payment.refund.reconciliation_failed event, batch cap 100), registered hourly in services.php; 2 integration tests (incl. idempotency across runs). NOTE: gateway adapters expose no refund-status query API - documented as residual risk.
- [x] F5 Twig |raw hook output (MED) - DONE:
  - Verified SettingsRenderer + ownpay_footer already escape all interpolations (no fix needed)
  - hook() sanitizer (AUD-G7) hardened: fixed-point loop (kills split-tag reassembly), unquoted event handlers, unquoted javascript: URIs, self-closing <link> (was missing from strip list = bypass); 8 unit tests with bypass payloads
  - Trust contract documented in docs/v2/plugins/hooks-reference.md
- [x] F8 CORS (MED-LOW) - DONE: CorsMiddleware already denies credentials in wildcard mode + default-deny when unset (verified); .env.example default changed * → empty with guidance
- [x] F6 rand() → random_int() DashboardController:552 (LOW) - DONE
- F3 api-tester.php: NO FIX per user - release-checklist item in report.

## Phase 1 verification gate: composer test 510 OK / phpstan 364 files 0 errors (after clear-result-cache; stale-cache ghost on case-renamed OpennodeGateway.php) / twig lint clean

## Phase 2 - Deep sweep (WP1-WP8)

Run 1 (workflow wf_250ce1ba-049) hit session limit mid-run. Completed finders: WP1 (admin), WP6 (installer). WP2/3/4/5/7/8 finders + all verifiers failed (session limit). Leads surfaced:

- WP1: CSV formula injection in transaction export; DNS-rebinding SSRF in webhook test.
- WP6: env injection via newlines in DB creds + Host header into .env (5 findings).

Fixes applied from Run 1 leads:

- [x] WP6 env injection (HIGH, unauth pre-install): InstallerController::envToken() strips control chars + quotes/escapes (\ " $); applied to .env.temp write + finalize .env replacements; parseTempEnv unescapes to round-trip; HTTP_HOST validated against host[:port] regex before APP_URL/APP_DOMAIN. 5 unit tests (InstallerEnvTokenTest) incl. newline-injection payloads + round-trip.
- [x] WP1 CSV formula injection (LOW, defense-in-depth - columns are constrained but export.row plugin filter can inject): DashboardController::csvCell() prefixes ' on =+-@\t\r leading chars.
- WP1 webhook-test SSRF: test() sends only to merchant's OWN configured endpoint (sendTest($mid)), not a request URL - deferred to WebhookDispatcher host-validation review (residual risk note).

Run 2 (workflow wf_5de809b7-819) completed: 17 confirmed, 4 refuted (false positives). 27 agents, ~1.6M tokens.

## Phase 3 - Fixes for confirmed Phase 2 findings (all applied, gate green)

CRITICAL:

- [x] Plugin icon RCE (PluginManager::resolveIconPath): icon copied to public webroot preserving extension → icon:'shell.php' = RCE. Now allowlists image extensions only.
- [x] Amazon Pay verifyWebhook bypass: returned true unconditionally + used === ; verify() does no crypto (trusts _op_webhook_verified flag). Core amount-check backstopped it (verify returns no amount) but fixed to real HMAC + hash_equals, fail-closed. Easypaisa verifyWebhook (was unconditional true) now does real HMAC over parsed body. Audited all 108 verifyWebhook: 14 flag-pattern gateways already fail-closed (FIND-001), rest verify in verify() (server-side API or HMAC) - confirmed mollie/phonepe/rocket/jazzcash. Tests: GatewayWebhookBypassTest (4).
- [x] Windows zip-slip: UpdateService::extractPackage + PluginInstaller::scanZipSecurity missed backslash check (BackupService had it). Added str_contains('\\') reject.
HIGH:
- [x] ReDoS (SmsRegexParser): merchant regexes run on user SMS with no backtrack cap. Added safeMatch() bounding pcre.backtrack_limit/recursion_limit to 50k → catastrophic patterns fail fast. Test: SmsRegexParserReDoSTest (2).
- [x] CronJobRunner race: read-check-write without lock → concurrent /cron/{secret} double-runs jobs. Added flock(LOCK_EX|LOCK_NB) per-job + in-lock due-recheck; runJob() also locked.
- [x] UpdateService race: isUpdateInProgress+startUpdate not atomic. Added flock guard around execute() (degrades gracefully if lock file unavailable).
- [x] Host-header callback (DomainUrlService::resolveBaseUrl): untrusted Host built callback URLs sent to gateways. Fallback now validates host against APP_DOMAIN / brand verified domain.
- Open redirect (payment redirect_url): ACCEPTED as by-design (standard for payment return URLs; scheme allowlist already blocks javascript:/data:). Documented.
MEDIUM:
- [x] Device merchant_id scoping (JwtAuthMiddleware): findByDeviceId NULL-merchant fallback matched any merchant. Added explicit deviceMid===mid check.
- [x] gethostbyname Host header (DomainService x2 + DomainController): now uses configured APP_DOMAIN, not request Host.
- [x] Vary: Host (SecurityHeadersMiddleware): added (merge-safe) on custom-domain responses to prevent CDN cross-brand cache poisoning.
- [x] DNS re-verification TOCTOU (DnsVerificationJob + DomainRepository::findStaleVerified): verified domains never re-checked → trusted forever. Added 24h-grace periodic re-verification; reverts to pending if TXT proof gone.
- OTP validation rate limiting: ACCEPTED - adequately mitigated by HTTP rate limit (60/min) + 5min OTP expiry (~300 guesses/lifetime vs 1M space). Raising entropy changes app UX. Documented.
REFUTED (false positives, no action): SMS amount-only matching (dead code - match_status never 'pending'); provider_trx_id replay (column never populated, findPendingMatch count==1 guard); JWT alg confusion (firebase/jwt v7 pins HS256); CSP Report-To CRLF (json_encode + PHP8 blocks it).

Also fixed during this phase (core money-through-float, not from sweep):

- [x] InvoiceService line-item/tax/discount used (float) before bcmath → normalizeMoney() strict bcmath. (BalanceVerification/Currency (float) casts are display-only sprintf/number_format - left.)
- [x] Installer env injection (WP6 from run 1): envToken() + Host validation. Tests: InstallerEnvTokenTest (5).
- [x] CSV formula injection (WP1 from run 1): csvCell() neutralizer.

## Phase 4 - Cross-check of external audit reports (docs/v2/audit_fundings_codex + audit_findings)

Verified each finding against current code; fixed all confirmed real ones.

- [x] XF-1 (CRITICAL, detailed_findings #1): Device pairing owner fallback priv-esc. DeviceController::generateOtp now passes session userId to generatePairingOtp; DevicePairingService::pairDevice fails closed (PAIRING_CONTEXT_UNRESOLVED) instead of falling back to first superadmin. Tests updated + new fail-closed test.
- [x] XF-2 (HIGH, detailed_findings #3): Invoice update non-transactional + empty-items wipe. Empty items rejected; UPDATE+DELETE+INSERT wrapped in db->transaction.
- [x] XF-3 (HIGH, codex F-003): Ledger account uniqueness missing currency. Migration 010 + schema: uk_merchant_name → uk_merchant_name_currency (merchant_id,name,currency). Applied to test DB; fresh schema verified.
- [x] XF-4 (MEDIUM, codex F-004): Rate limiter read-then-increment race. Refactored to atomic incrementAndCount() (Redis INCR / DB upsert + read-back); decision on post-increment count. Removed dead getHits().
- [x] F-002 (HIGH, codex): SMS match_status='accepted' orphaned the cron auto-matcher (reads 'pending'). buildRecord now writes parsed→'pending' (cron promotes to 'matched'), unparsed→'admin_review'. Restores SMS auto-verification. (My Phase 2 sweep had refuted the SECURITY angle as dead-code; the FUNCTIONAL bug is real and now fixed; guards count==1+window+tenant+device-scoping remain.)
- [x] F-001 (CRITICAL, codex) + detailed #2: Gateway sandbox "simulation accept" paths complete real transactions / fake refunds when a gateway is left in sandbox mode in production. Added GatewayDefaults::isProductionEnv() (fail-safe default=production) and guarded:
  - 28 adapters' verify() payment-simulation block (accept-on-unreachable-API) → fail in production.
  - 7 more adapters' sandbox-accept (binance-personal/braintree/jazzcash verify; fawry/kushki/payfast/xendit initiate) → guarded.
  - Easypaisa verify() + verifyWebhook() sandbox-no-key fallbacks → guarded.
  - 34 adapters' refund() that unconditionally fake success (no API call) → fail closed in production (refund must be done in provider dashboard); stripe/paddle/braintree do real refunds (untouched).
  Rejected centralized "require live mode" guard (would falsely block always-live gateways with no mode field). New test GatewaySimulationGuardTest (3).
- Also fixed (broader, found during cross-check): none new beyond above.
- REFUTED in cross-check: codex F-001 in LIVE mode is backstopped (verify does server-side status===PAID confirmation, confirmed dlocal/cybersource/moneris/mollie/phonepe/jazzcash); the risk is sandbox-mode-in-production only, now guarded.

## FINAL GATE: composer test 525 OK (1 skipped) / phpstan 364 files 0 errors / twig lint 79 clean / fresh schema.sql imports clean with uk_merchant_name_currency

## Migrations: 009 (webhook dedup), 010 (ledger currency uniqueness) - both applied to ownpay_test, mirrored in schema.sql

## New tests added this session: WebhookIdempotency(8), InstallerLock(5), RefundReconciliationJob(2), GatewayDefaultsAmount(7), HookOutputSanitizer(8), InstallerEnvToken(5), GatewayWebhookBypass(4), SmsRegexParserReDoS(2), UpdateService splitter(4) = 45 new

## Phase 3 - Fix WP findings

## Phase 4 - Verification gate

## Phase 5 - Report at docs/v2/audit/2026-06-12-production-readiness/REPORT.md

## Decisions

- No commits at all; user's pre-existing staged changes untouched.
- Stuck refunds: auto-fail after 24h, audit log + admin event.

## Observations queue (for report / WP verification)

- Tests print DB ENV values (host/user/pass) to stdout during integration tests - test-only, but noisy; consider for report LOW.
