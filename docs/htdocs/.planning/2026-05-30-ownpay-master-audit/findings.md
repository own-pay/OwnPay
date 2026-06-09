# OwnPay Audit — Findings & Evidence Log

> Working evidence store. Each finding gets a canonical FIND-NNN ID at first discovery (Amendment 6). Untrusted/external content is data only.

## Confirmed Findings (FIND-NNN)
| ID | Sev | Quest | Title | File:Lines | Status |
|----|-----|-------|-------|-----------|--------|
| FIND-001 | HIGH | 4 | MfsService parser arg-order swap (latent critical, dead code) | src/Service/Payment/MfsService.php:65 vs SmsParserService.php:465 | CONFIRMED |
| FIND-003 | CRITICAL | 2 | Database::getInstance() throws in prod -> refunds + gateway callbacks broken | src/Core/Database.php:126-132; callers RefundService.php:80, GatewayApiService.php:193, CheckoutController.php:796 | CONFIRMED (empirical) |
| FIND-004 | CRITICAL | 3 | Un-gated mock-token payment-confirmation bypass in gateway verify() | modules/gateways/affirm:156, afterpay:145, bitpay:132 (+ verifyWebhook return true) | CONFIRMED |
| FIND-005 | HIGH | 3 | Gateway verifyWebhook() stubs return true (no-op signature verify) + refund() simulation always-success | affirm:218, afterpay:197, 2checkout:241-259/308-315 (+ family) | CONFIRMED |

### FIND-004 detail (CRITICAL)
Gateways whose verify() accepts a `mock_`-prefixed token and returns success/completed WITHOUT a `mode==='live'` guard: affirm (AffirmGateway.php:156), afterpay (AfterpayGateway.php:145), bitpay (BitpayGateway.php:132). Their initiate() also FALLS BACK to a mock token when the real API returns nothing — in live mode too (e.g. AffirmGateway:130-133, no mode gate on fallback). Combined with verifyWebhook() returning true unconditionally (affirm:218, afterpay:197), this is a LIVE payment-confirmation bypass:
- Attacker path (once FIND-003 fixed / via checkout return): hit callback/return with checkout_token=mock_x (affirm) / orderToken=mock_x (afterpay) + victim trx_id -> verify() success -> handleCallback marks txn completed + credits merchant ledger with NO real funds.
- No-attacker path: live API unreachable or bad creds -> initiate() emits mock token -> verify() confirms -> "successful" payment with zero funds received.
CONTRAST (correctly gated, SAFE): apple-pay:181 + google-pay:181 return FAILED when mode==='live'; apple-pay verifyWebhook does real Stripe HMAC+timestamp+hash_equals (251-286). So remediation is INCONSISTENT across fleet. CWE-287/CWE-345. Release-blocking.

### FIND-005 detail (HIGH)
verifyWebhook() no-op `return true`: affirm:218, afterpay:197 (and others) => UnifiedWebhookController signature gate (which delegates to adapter) passes ANY payload for these gateways. 2checkout:241-259 only checks signature HEADER PRESENCE, not value. refund() simulation: 2checkout:308-315 always returns success+fake refund_id without calling provider => OwnPay marks refund done + debits ledger but no money returned at gateway. Sample more gateways' refund() in registry.

### Gateway Registry (Q3 two-pass)
- Total adapters ~140; **116 call curl_exec** (real outbound HTTP). NO SSL-verify-disabled, NO shell_exec/system/eval/raw-SQL in any module (clean).
- ~40+ gateways carry `// Emulate fallback visual window for simulated checkout` => offline/sandbox auto-confirm. MOST gate it to sandbox (live throws) e.g. 2checkout:152,168,209 -> MEDIUM (sandbox/misconfig).
- ~8 "mock_ token" family (affirm, afterpay, amazon-pay, apple-pay, google-pay, bitpay, braintree, gocardless): apple-pay/google-pay GATED (safe); affirm/afterpay/bitpay UN-GATED (FIND-004); amazon-pay/braintree/gocardless verify() needs confirm (mock_ in initiate).
- ~24 gateways have NO curl_exec (rocket, ccavenue, easypaisa, jazzcash, alipay, payu, mobikwik, ...) -> pure skeleton/redirect-only; need per-file check for real vs stub. List in report.
- verifyWebhook/refund stubs (FIND-005) pervasive -> fleet not production-ready uniformly.

### FIND-003 detail (CRITICAL — empirically verified)
Database::$instance set ONLY by init() (Database.php:116); init() called ONLY in tests/Integration/IntegrationTestCase.php:32. Constructor (53-56) does NOT set it. Container factory (services.php:107) = `new Database($pdo)`. Kernel::boot() (read in full) never calls init()/setInstance — line 111 uses container->get. services.php eager-boot (350-363) calls $c->get(Database::class) which ALSO doesn't set the static => INEFFECTIVE.
**Empirical probe** (boot-replica: autoload + .env + services.php): installed_lock=YES; container_db=OwnPay\Core\Database (DI works); **getInstance_THROWS="Database::init() must be called before getInstance()."**
**Live unconditional callers => throw at runtime:** RefundService::create:80 (=> ALL refunds throw), GatewayApiService::handleCallback:193 (=> gateway webhook/callback completion throws; called by UnifiedWebhookController:138, CheckoutController:824, PaymentIntentCheckoutController:827), CheckoutController:796.
**Impact:** refunds 100% broken; gateway callback cannot mark txn completed => customer pays at gateway but OwnPay stays pending/failed (this IS Quest 2.2 Scenario A). Merchant/customer financial harm. Release-blocking.
**Fix:** populate singleton at boot — in services.php Database factory `static function($c){ $db = new Database(...); \OwnPay\Core\Database::setInstanceForBoot($db); return $db; }` (add a setter), OR (better) inject Database via DI into RefundService/GatewayApiService/CheckoutController instead of getInstance(). CWE-665 (improper initialization).

### FIND-001 detail
**Swap CONFIRMED.** Signature `parse(string $rawMessage, string $sender, int $brandId)` (SmsParserService.php:465). Call `$this->parser->parse($sender, $body, $merchantId)` (MfsService.php:65) inside `processIncomingSms(int $merchantId, string $sender, string $body, string $deviceId)` (line 63). So $rawMessage<-sender, $sender<-body.
**Call stack:** `processIncomingSms` has ZERO callers; `MfsService` appears only in autoload classmap (no `new`, no type-hint, no container binding, no route/cron). => DEAD/orphaned code.
**Impact if wired (its documented purpose = carrier/gateway SMS ingestion):** findBySender($body,...) matches no template -> attemptParse returns null -> 0 auto-matches; every MFS payment silently -> admin_review. Silent failure (no exception). Customer pays, not auto-credited.
**Severity HIGH** = latent-critical + dead-code trap; must fix before enabling any non-device SMS path. Live device path (SmsController->processBatch->attemptParse, SmsParserService.php:197) uses CORRECT order => NOT a production-active break. CWE-628 / dead-code.

## Leads (to verify/refute)
| Lead | Location | Quest | Verdict |
|------|----------|-------|---------|
| Parser arg-order swap | src/Service/Payment/MfsService.php:65 | 4 | pending |
| SMS dedup ignores trx_id | src/Repository/SmsDataRepository.php | 4 | pending |
| Gateway mock_ auto-confirm | modules/gateways/affirm + scan all | 3 | pending |
| Mock txn entity stub | PaymentIntentCheckoutController ~211 | 5 | pending |
| Webhook no timestamp/replay | UnifiedWebhookController ~85-99 | 6 | pending |
| SSRF DNS-rebinding TOCTOU | WebhookDispatcher ~252 / UrlValidator | 6 | pending |
| Pairing superadmin-id-1 fallback | DevicePairingService ~230-237 | 4 | pending |
| Notification UUID int-cast | mobile controllers | 4 | pending |
| Generated cols for hot JSON | database/schema.sql | 7 | pending |
| CSRF excluded routes | CsrfMiddleware + routes | 11 | pending |
| Twig autoescape / `\| raw` | src/View, templates | 6 | pending |
| uniqid/mt_rand in token gen | src/ | 11 | pending |
| Session cookie flags | config/session.php, Core/Session | 11 | pending |
| APP_DEBUG default | .env.example | 6 | pending |
| CORS on mobile API | config/routes/api.php | 11 | pending |
| Password reset token method | PasswordResetService | 11 | pending |
| Callback amount vs order amount | PaymentService callback | 5 | pending |
| .env/vendor HTTP status | .htaccess + nginx | 0 | RESOLVED: all 403/404 (PASS); cli not in dir-deny (hardening note, mitigated) |
| composer/npm audit CVEs | tooling output | 0 | RESOLVED: none (composer audit + npm audit both 0) |
| PHPUnit unrunnable on PHP 8.2 (phpunit ^12.5 needs 8.3+) | composer.json:7,37 | 0 | CONFIRMED lead — release-readiness |
| File upload MIME/path | upload handler | 6 | pending |
| Exception handler stack trace | src/Core handler | 6 | pending |

## Tooling Baseline (Phase 0 raw evidence)
- composer validate: VALID (0). composer audit: NO advisories (0). Composer 2.9.7.
- PHPStan level 9 (cli/config/modules/src): NO ERRORS (0).
- php-parallel-lint: 364 files, no syntax errors (0). twig-cs-fixer: 79 files clean (0).
- eslint + stylelint: clean (0). npm audit (prod+dev): 0 vulnerabilities.
- PHPUnit: CANNOT RUN — phpunit ^12.5 needs PHP 8.3+, env/min is 8.2 → LEAD (release-readiness).
- Web-exposure: all sensitive paths 403/404, `/` 200. PASS. cli omitted from htaccess dir-deny (mitigated by rewrite) → hardening note.

## Per-Quest Notes
### Q0 (tooling/exposure)
### Q1 (sovereign/domains)
- **1.1 tenant isolation STRONG:** TenantScope::forTenant clone (29-34), requireTenant THROWS if null (56), scoped CRUD all enforce merchant_id=:mid; updateScoped UNSETS merchant_id (118) -> no cross-tenant migration; createScoped injects merchant_id (103). Misuse -> LogicException (fail-closed). PASS.
- **1.2 domain resolution STRONG:** DomainMiddleware unknown/inactive->404 (88), dns_verified=0->503 (93), inactive merchant->404 (116). PASS.
- **1.3 admin block PASS:** /admin or /admin/* on custom domain -> 404 (101). Minor LOW: localhost hardcoded passthrough (72) — Host header client-controlled, `Host: localhost` bypasses brand scoping (admin still auth-gated). Also path()-normalization bypass? (verify Request::path).
- **1.4 brand theme:** pending (BrandThemeService + checkout).
- **2.5 scoped-clone capture:** pattern sound (callers chain `->forTenant(x)->createScoped()`); calling scoped op on unscoped original -> requireTenant throws. PASS-pattern (spot-check callers).
### Q2 (concurrency/ledger)
- **2.3 journal balance PASS:** LedgerService::postEntries sums bcadd(4dp), bccomp(debit,credit)!=0 -> throw (102-104). Atomic in $db->transaction (108). Double-post guard: FOR UPDATE existence check on (mid,ref_type,ref_id,desc) (110-127). 
- **2.4 GAAP directions:** recordPaymentReceived CASH debit(asset+)/MERCHANT_PAYABLE credit(liab+)/FEE credit(rev+); recordRefund reversed. Balanced. Sign-application correctness depends on LedgerRepository::adjustBalance (VERIFY).
- **2.6 refund STRONG (PASS):** double FOR UPDATE (txn 86-88 + refunds SUM 102-106), over-refund block newTotal>orig->throw (124-128), proportional fee ratio=origFee/origAmount (137-142), merchant-payable balance>=refundNet check (147-149), negative/zero amount rejected at service layer (120) [satisfies Amendment-4].
- **FIND-002 (MED/HIGH, Q2 concurrency):** RefundService.create runs external gateway HTTP `bridge->refund()` (line 170) INSIDE $db->transaction holding FOR UPDATE on op_transactions (83-207). Row lock + open txn held for full external API latency -> lock contention, held DB connections, innodb_lock_wait_timeout under load. Same anti-pattern to check in GatewayApiService::handleCallback. CWE-? availability/scalability.
- **Arch note:** RefundService uses Database::getInstance() (80, service-locator) vs LedgerService uses repo->getDatabase(); nested-transaction reentrancy correctness REQUIRES same singleton connection (VERIFY Database singleton + container wiring).
- **2.4 GAAP CONFIRMED PASS:** LedgerRepository::adjustBalance (127-131) asset/expense debit=+, liability/equity/revenue credit=+; atomic `balance=balance±:amount` (142).
- **2.1 concurrency (DB):** Database::transaction reentrant (321 inTransaction guard; nested joins outer, no savepoints; inner throw -> outer rollback). Good IF single connection.
- **6.5 SQLi DB-layer PASS:** ATTR_EMULATE_PREPARES=false (114), typed bindValue (245-256), table-name regex in exists/count (391,410). No param interpolation.
- **Mass-assign (6.4):** LedgerRepository no $fillable but inserts fixed internal arrays (71-77) -> not exploitable. Defense-in-depth gap. (DisputeRepository/RateLimitRepository similar — spot-check.)
- **LEAD (CRITICAL? verify): Database singleton** getInstance() returns self::$instance only set by init() (116); container uses new Database($pdo). If bootstrap never sets singleton -> RefundService::create throws (refunds broken); if different PDO -> refund atomicity breaks. VERIFY config/Kernel wiring NOW.
- **LEAD (Q3 plugin escalation, HIGH?):** Database::execute fires db.query.before filter letting plugins rewrite SQL+params for EVERY query (209-218); sandbox validateSql SKIPPED when activeOwner==='core' (227). Malicious plugin filter could tamper core financial SQL unchecked. Verify EventManager filter ownership + sandbox in Q3.
### Q3 (cli/hooks/plugins/gateways)
- **Gateways**: FIND-004 (un-gated mock bypass affirm/afterpay/bitpay), FIND-005 (verifyWebhook/refund stubs), registry above. 116/140 real curl_exec; no shell/SQLi/SSL-off.
- **3.1 CLI**: only cli/build-update.php + cli/create-module.php exist (both functional per recon). NO dedicated system-update or currency-rate CLI (done via UpdateService/admin + cron). Note in report.
- **3.2 hooks/events**: db.query.before plugin SQL-rewrite MITIGATED — applyFilter sandbox-validates plugin-owned filter SQL + throws (EventManager:317-330); Database::execute also sandboxes when owner!=core. RESIDUAL (MED): plugin with null sandbox (getSandbox null, 323) bypasses SQL validation. Brand-scoped plugin activation isOwnerActive (170-198) — good isolation. Owner stack push/pop try/finally + re-entrancy guard -> no loop/leak. Error isolation per listener. Filters UNTYPED (consumers validate; core does Kernel:54) — design note. AUD-21 (Kernel:182-193) re-adds security middleware if plugin removes it.
- **5.2 logo /storage/ PASS**: manual-gateway.twig:10,24 + _gateway-grid.twig conditional /storage/ prefix.
### Q4 (sms)
Live path: POST /api/mobile/v1/sms -> SmsController::receive -> SmsParserService::processBatch -> processOne -> attemptParse($rawMessage,$sender,$brandId) CORRECT (SmsParserService.php:197). Matching cron: src/Cron/SmsVerificationJob.php::run.
- **FIND-001** (HIGH, dead code): MfsService arg swap — see above.
- **Dedup** (SmsDataRepository::isDuplicate:52): keys (device_id,sender,±1s received_at,merchant_id), NOT trx_id. BUT trx_id replay -> double-credit is MITIGATED: SmsVerificationJob.php:129 only completes if tx status=='pending'; once completed a replay finds non-pending tx -> skipped. => NOT a double-credit vuln. INFORMATIONAL.
- **Amount fallback** (SmsVerificationJob:121-127 -> TransactionRepository::findPendingMatch:652): well-guarded — with receivedAt requires EXACTLY ONE pending tx (amount+gateway, window -30/+5min); `if($count!==1) return null` (line 668). Ambiguous amounts match neither. Good defensive design. PASS (note: amount-only soft-match by design).
- **LEAD (correctness, verify in Q5):** op_transactions.trx_id = OwnPay 'OP-XXXX' (generateTrxId:41); SMS-parsed trx_id = MFS provider TrxID (seed sms_templates.sql:9 'TrxID ([A-Za-z0-9]+)'). Different namespaces => findByTrxId (cron:117) likely never matches; relies on amount-match. Must check manual checkout submit (does customer-entered bKash TrxID get stored to op_transactions.trx_id?).
- **findPendingMatchGlobal** (TransactionRepository:707) is UNSCOPED (cross-brand, no merchant_id). Need to grep usage — if used in live path = cross-brand match risk. (cron uses scoped findPendingMatch.)
- **Spoofing posture (4.6):** sender identity = device-reported carrier `sender` field (SmsController:116), not body => "whitelisted-name-in-body" bypass does NOT work. findBySender matches sender_pattern vs sender field (verify). GCM + device JWT + count==1 guard. PASS.
- **4.2 fallback:** SmsRegexParser validates each regex via @preg_match($p,'')===false before use (line 45,75) -> invalid skipped gracefully; unknown structure -> regex null -> heuristic (only if templates exist) -> null -> match_status 'admin_review'. No unhandled errors. PASS.
- **4.3 multi-word ref:** heuristic trx_id `[A-Z0-9]{5,20}` (SmsHeuristicParser:39) — fits bKash-style IDs; reference-with-spaces not captured but reference is NOT the match key. Minor.
- **ReDoS LEAD (Q6 xref):** template regex_pattern/amount_regex/trx_id_regex are admin/brand-staff-supplied, executed via preg_match on every SMS (SmsRegexParser:49,79,91,103). Brand staff could inject catastrophic-backtracking regex -> cron DoS. LOW-MED (configurer is semi-trusted).
- GCM decrypt (SmsParserService:417) AES-256-GCM, IV(12)+ct+tag(16), key len validated. Correct.
- **4.4 superadmin fallback PASS:** pairDevice (DevicePairingService:223-238) created_by -> $_SESSION -> first active superadmin(mid) -> ?? 1. Graceful, no crash.
- **4.5 UUID cast PASS:** NotificationController:50-52 keeps device_id string (BUG-008 fix); ack scoped by device_id (BUG-007 fix, line 63/92) -> no UUID->0, no cross-device IDOR. DeviceController treats device_id as string.
- **OTP brute-force LEAD (Q11):** validatePairingOtp resolves OTP globally by sha256 hash, no merchant binding on consume; 6-digit (10^6); single active OTP/merchant. If POST /api/mobile/v1/devices (pair) not rate-limited -> brute-force pair-to-victim-brand in 5min window. Verify middleware. JWT can't guard pair (pre-auth bootstrap).
- JWT refresh (refreshAccessToken:299) = verify + JTI blacklist (op_cache) + status + fingerprint + rotation. Solid.
Q4 STATUS: thoroughly covered. Open cross-refs: (a) manual-checkout trx_id namespace (Q5), (b) pair endpoint rate-limit (Q6/Q11 middleware).
### Q5 (checkout/invoice)
- **5.1 invoice recalc PASS:** InvoiceService create/update recalc subtotal via bcmul/bcadd from items (137-154, 213-230); update DELETEs old items then re-inserts (262-280) -> no orphans, no stale/0.00. Status whitelist (233). Parameterized.
- LOW: no positivity clamp on unit_price/discount (142,152,218,227) -> invoice total can go negative (admin-created, self-inflicted; Amendment-4 refund/payment guarded elsewhere).
- Callback amount + Scenario A/B -> see Q6/Q2 (FIND-003, callback-amount lead).
- TODO: manual gateway logo /storage/ prefix (5.2), clipboard fallback (5.3) — grep.

### Q7 (db schema)
- **PASS column compliance (EXACT):** totp_secret_enc(74), two_factor_enabled(75), decimal_places(191), base_currency/target_currency + UNIQUE(199-205), op_sms_parsed.device_id(673)/match_status ENUM(686)+indexes(693-696). All op_-prefixed.
- **PASS 7.4 scalability:** STORED generated cols invoice_id/payment_link_id from JSON metadata (schema:289-290) + idx (TransactionRepository docblock). Hot JSON fields indexed, not dynamic.
- (Remaining: FK coverage + full index review — schema large; spot-checks good.)

### Q6 (cont'd)
- **File upload STRONG PASS:** FilesystemService extension whitelist(58) + finfo MIME-vs-ext(63-70, NOT $_FILES type) + SVG sanitize(206-231) + random filename(87) + realpath traversal guard(155). Stored in storage/ (uploads/gateways web-served but images only, no PHP exec).
- **APP_DEBUG=false default** (.env.example:12). handleException hides traces in prod, sanitizes paths in debug (Kernel:414-484). PASS info-disclosure.
- **Request::ip() XFF SAFE** — trusts XFF only behind TRUSTED_PROXIES (Request:425,517). 
- **XSS PASS:** Twig autoescape=html (TwigFactory:98); |raw only on hook/extension output (LOW: plugins self-sanitize).
### Q6 (attack surface)
- **6.1 webhook signature PASS (controller):** UnifiedWebhookController:86-99 verifyWebhookSignature before dispatch; false->403, throw->403. 1MB body cap (60-66) DoS guard. slug regex (71). log-forging stripped (226). PCI: logs only sha256 hash (logDelivery 244). Replay mitigated by txn state machine (handleCallback:211 only completes pending). Constant-time/timestamp = adapter-specific (sample adapters).
- **merchant resolution** resolveMerchantFromPayload (176) reads merchant_id from UNVERIFIED payload by trx_id (parameterized) only to pick whose secret to verify against -> can't bypass sig (needs that merchant's secret). OK.
- **form_html sanitize** (GatewayApiService:249) regex strips on*/javascript:/external script but KEEPS inline <script> (auto-submit) -> malicious gateway plugin could inject inline JS; gateway plugins are owner-installed (trusted) -> defense-in-depth limitation, LOW.
- **Q2.2 Scenario A = FIND-003** (handleCallback getInstance throws -> 500 -> txn never completed).
- **Q2.2 Scenario B / Amendment-4 callback amount:** handleCallback completes using STORED txn amount (213-215), NOT callback amount -> no $0.01-ledger attack here. BUT no explicit callback-amount==order-amount check; delegated to adapter verify(). LEAD: sample stripe/sslcommerz/bkash verify() for amount validation.
### Q7 (db schema)
### Q8 (installer) — STRONG PASS
- 8.1 DB-independent: 'install' middleware group = SecurityHeadersMiddleware only (middleware.php:96).
- 8.2 NO parse_ini_file: parseTempEnv line-by-line explode('=',2) preserves base64 '=' (InstallerController:271-300). grep confirms parse_ini_file vendor-only.
- 8.3 .installed lock on every endpoint (48/85/172/310/474) -> 403/locked.
- CSPRNG keys random_bytes (496-500), ARGON2ID admin (365), APP_DEBUG=false forced (525), .env 0640. Schema integrity >10KB, prefix regex-validated.
- NOTE: install window unauthenticated (inherent); admin pwd min 8 only (no complexity). INFO.

### Q5.3 clipboard PASS: admin.js:196-212 execCommand + navigator.clipboard fallback (HTTP-safe); checkout.js:454-465 same.
### Q9 (low-resource)
### Q10 (admin ui / framework / mobile api)
### Q11 (auth/session/crypto)
- **PASS**: password Argon2id (Authenticator:133), session_regenerate_id(true) on login (154), logout destroys cookie (174), user-enum constant-time dummy verify (92), brute-force lockout 5/300s email+ip (79-86).
- **2FA PASS**: verifyCodeWithReplayGuard skips windows<=lastUsed (293), hash_equals (297); discrepancy ±2 (±60s) slightly generous (note). TOTP HMAC-SHA1 standard.
- **JWT PASS**: HS256 hardcoded (Firebase lib rejects alg confusion/none), exp enforced, CSPRNG jti, secret>=32 enforced at Kernel boot (205). Minor: aud/iss not asserted.
- **CSRF PASS**: STP hash_equals + rotating pool (CsrfMiddleware:81-98); excludes GET/HEAD/OPTIONS, /api/*, /webhook/*, /ipn/*. /api/* exclusion SAFE — all /api/ routes use bearer/JWT groups (routes/api.php: api/mobile/admin-api), NO cookie-auth /api/ route. 
- **Headers PASS**: SecurityHeadersMiddleware GLOBAL (middleware.php:20). CSP+nonce, HSTS+includeSubDomains (HTTPS-only), XFO DENY, nosniff, Referrer-Policy, Permissions-Policy. (style-src 'unsafe-inline' minor.)
- **CORS PASS**: default-deny; wildcard forces Allow-Credentials:false (CorsMiddleware:76).
- **RateLimiter wired** in web-auth/admin/api/admin-api/mobile/checkout/cron. Mobile pairing IS rate-limited (mitigates OTP brute-force). LEADS: (a) Request::ip() XFF trust? (VERIFY) — affects login/pairing/lockout key; (b) FAIL-OPEN on DB exception (RateLimiterMiddleware:115-119) — limiter skipped if DB down (lockout table also DB -> both fail in outage). MED.
