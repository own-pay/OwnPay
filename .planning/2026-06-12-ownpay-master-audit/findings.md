# OwnPay Audit Findings

## Phase 1: Invariant Verification

**INV-1 Single Super-Administrator**
- Evidence: `MerchantUserRepository::listAllStaff()` allows superadmin to view all staff across all brands without merchant restriction.

**INV-2 Brand = Merchant**
- Evidence: `OwnPay\Repository\TenantScope` enforces `merchant_id = :mid` on all operations (Lines 72, 91).
- Status: HOLDS.

**INV-3 Staff are Brand-Bound**
- Evidence: `MerchantUserRepository` uses `TenantScope` and queries inject `merchant_id`.
- Status: HOLDS.

**INV-4 Custom Domains are Checkout-Only**
- Evidence: `OwnPay\Middleware\DomainMiddleware::handle()` explicitly returns 404 for any path starting with `/admin` on a custom domain (Lines 98-104).
- Status: HOLDS.

**INV-5 Ledger is Double-Entry**
- Evidence: `OwnPay\Service\Payment\LedgerService::postEntries()` calculates total debits and credits and strictly checks `bccomp($totalDebit, $totalCredit, 4) !== 0` before entering a database transaction (Line 102).
- Status: HOLDS.

**INV-6 Schema Prefix Discipline**
- Evidence: `schema.sql` `CREATE TABLE` statements exclusively use the `op_` prefix.
- Status: HOLDS.

**INV-7 Real Gateways Make Real HTTP Calls**
- Evidence: Implementation spans `modules/gateways/*`. Requires ongoing verification during Quest 3/6.

## Quest 1: Sovereign Boundary & Custom Domains

**Q1.1 Tenant Isolation & Scoping**
- **a)** All brand-owned repositories use the `TenantScope` trait. The scope application site is `TenantScope::forTenant()`.
- **b)** The brand context is explicitly enforced via `TenantScope::requireTenant()`.
- **c) FINDING (CRITICAL) - Scope Bypass via Parameter Mismatch:** In `InvoiceController::edit()` and `PaymentLinkController::edit()`, the controllers call `$this->invoices->find($mid, $id)` and `$this->links->find($mid, $id)`. However, `BaseRepository::find()` only accepts a single argument. PHP silently ignores the second argument, resulting in the query searching for `id = $mid` instead of `id = $id`.
- **c) FINDING (HIGH) - Scope Bypass in `PaymentLinkRepository::findBySlug()`:** The method calls `return $this->findBy('slug', $slug);` which uses `BaseRepository::findBy()`. This method does NOT inject the tenant scope, allowing cross-tenant fetching.

**Q1.2 Custom Domain Resolution**
- **a)** `DomainMiddleware::handle()` evaluates custom domains. Unverified domains return `503`. Unrecognized/inactive domains return `404 Not Found`.
- **b)** There is NO fallback that treats unknown domains as the master domain.

**Q1.3 Admin Route Protection**
- **a)** `DomainMiddleware::handle()` lines 102-104 explicitly guard `/admin`.
- **b)** `PermissionMiddleware` and `AuthMiddleware` are registered to the `admin` route group.

**Q1.4 Brand Theme Resolution**
- **a/b) FINDING (MEDIUM) - Theme Resolution Bypass:** `CheckoutController::show()` (Line 137) retrieves the merchant via `$this->merchants->find($mid)` instead of loading the theme via the `BrandThemeService`. This bypasses brand-scoped theme customization settings, violating the White-Label Domains rule.

## Quest 2: High-Volume Concurrency, Redirect Gaps & Payment Integrity

**Q2.1 100,000 Simultaneous Charge Requests**
- **a)** Row-level locks (`SELECT ... FOR UPDATE`) are taken in `PaymentIntentCheckoutController::pay()` on the `op_payment_intents` and `op_transactions` tables during checkout initiation, preventing double-charge races. Contention hotspot exists at the intent row level.
- **b)** The connection pool ceiling is NOT managed by OwnPay application code; `Database::init()` instantiates PDO per request. Exhaustion throws a PDOException resulting in a 500 Server Error (crash), rather than a graceful 503 or queue.
- **c) FINDING (MEDIUM) - Gateway Callback Reference Collision:** `GatewayApiService::handleCallback()` uses an array of fields (`reference`, `order_id`, etc.) to extract the `trxId`. An attacker supplying a valid `OP-XXX` reference belonging to another user within the same brand could force the callback to match User B's transaction row if the gateway signature is weak or does not cover the reference field.

**Q2.2 Checkout Redirect Divergence — Scenario A (Slow IPN / Session Desync)**
- **a)** The redirect handler (`PaymentIntentCheckoutController::status()`) queries only the local database. It does NOT actively query the gateway API for status.
- **b)** There is a frontend polling fallback. `checkout-status.js` checks for `.st-refresh-tip` in `_pending.twig` and executes a `window.location.reload()` every 15 seconds.
- **c)** Session expiry does NOT strand the customer because the checkout state is retrieved entirely via the intent `token` from the database, not ``.

**Q2.3 Checkout Redirect Divergence — Scenario B (Status Tampering)**
- **a/b/c) FINDING (HIGH) - Unsigned Client-Side Merchant Redirect:** The merchant return callback URL is constructed on the frontend in `checkout-status.js` (line 23) via `redirectUrl + '?token=' + token + '&status=' + status`. The status parameter is NOT cryptographically signed by the backend. An attacker can intercept the browser redirect, change the URL to `&status=success`, and trick naïve merchant integrations that rely on redirect parameters instead of secure webhooks.

## Quest 3: CLI, Hooks / Events & Plugins

**Q3.1 Core CLI Inventory**
- **System Update CLI & Currency-Rate Update CLI**: NOT standalone CLI binaries. Dispatched via HTTP (`CronController::run`) executing `SystemUpdateJob` and `CurrencyUpdateJob`. System Update is a **PARTIAL/STUB** (it only fetches the JSON manifest and fires a hook; it does not download/extract/rollback the update).
- **Update-Builder CLI** (`cli/build-update.php`): **FUNCTIONAL**. Interactive script that packages zip releases and runs composer.
- **Module-Creation CLI** (`cli/create-module.php`): **FUNCTIONAL**. Scaffolds plugins securely.

**Q3.2 Hook / Event Dispatcher Security**
- **a)** The dispatcher (`EventManager::applyFilter`) accepts and returns `mixed`. There are NO strict type signatures. If a plugin returns the wrong type, it crashes downstream code.
- **b)** A plugin can rewrite core ledger/payment values if it hooks the right filter (e.g., returning 0 amount). However, if the plugin throws a fatal exception, `EventManager` catches it, logs the error, and proceeds with the original value (fail-open for stability).
- **c)** There is NO protection against infinite recursion (e.g., if hook A fires hook A). PHP's request lifecycle prevents cross-request memory leaks.

**Q3.3 FINDING (HIGH) - Sandbox/Fallback Verification Anti-Pattern**
- **a/b/c/d)** Many gateways (e.g., Alipay, Easypaisa, CCAvenue) do not make outbound server-to-server API calls during verification, relying instead on signed frontend redirects. 
- **CRITICAL FLAW**: In `AlipayGateway.php` and `EasypaisaGateway.php` (and likely others), if the merchant configuration omits the Hash/Public Key AND the gateway is set to 'sandbox' or 'test' mode, the gateway forcefully simulates success (`\ = (\ === 'sandbox');`). There is **NO GUARD** preventing a merchant from enabling this configuration on a live production site. An attacker can thus exploit misconfigured/sandbox stores by simply dropping the signature parameter to instantly bypass payment verification.
## Quest 4: SMS Ingestion, Pairing & Parsing Engine

### Q4.1 AES Decryption Order
- **a)** Is AES decryption performed BEFORE extracting the sender and checking duplicates? **No.**
- **b)** The outer JSON envelope contains sender and it is used BEFORE decryption. This occurs in SmsController::receive and SmsParserService::processOne. This is a High Severity spoofing vector because the parser trusts the unencrypted outer sender field instead of extracting it from the trusted, decrypted payload.

### Q4.2 Template Injection via Hook
- **a)** Yes, in SmsParserService::attemptParse, the mfs.templates filter executes.
- **b)** Yes, a maliciously crafted plugin can inject an array into this filter to force a parse match, bypassing the database whitelist.
- **c)** The code calls $res = ->events->applyFilter('mfs.templates', );. It blindly typecasts keys to strings and iterates over the array without validating if the source is legitimate.

### Q4.3 Heuristic Fallback Bypass (SmartSmsAnalyzer)
- **a)** Yes, if a transaction fails the regex parser, it falls back to SmartSmsAnalyzer.
- **b)** mount and 	rx_id are extracted purely by proximity regexes.
- **c)** The regexes capture strictly alphanumeric sequences ([A-Z0-9]). Hyphens or non-alphanumeric characters in a transaction reference will cause the regex to drop/truncate it, creating a matching failure.

### Q4.4 Device Pairing & Owner Fallback
- **a)** Where the JWT claim maps to the owner (created_by), if the column is NULL or 0, DevicePairingService::pairDevice falls back to the current session user (  for API), then attempts to find the merchant's first superadmin.
- **b)** If no admin is found, it silently falls back to superadmin owner ID 1 rather than rejecting the pairing, a critical privilege escalation vector.

### Q4.5 Notification UUID Scoping & Casting
- **a)** In the mobile API controller (NotificationController::ack), the device UUID is strictly cast to string.
- **b)** There is NO cast to int that becomes 0; it safely uses is_string() ?  : ''.
- **c)** The ACK query in MobileNotificationRepository::acknowledgeIds DOES filter by BOTH device_uuid and merchant_id when deviceUuid is provided, preventing cross-device/cross-brand IDOR.
## Quest 5: Checkout, Invoice & Frontend Flows

### Q5.1 Dynamic Invoice Recalculation
- **a)** On invoice update, InvoiceService::update does recalculate totals (subtotal, 	ax, discount, 	otal) from the current request items.
- **b)** Old invoice items are purged via a DELETE query and new items are inserted via INSERT. However, this is **not atomic** because the operations are not wrapped in a database transaction, creating a race condition where a crash could orphan rows.
- **c)** Yes, there is a path where the recalculated total writes  .00. If the client submits an update request with an empty items array, the loops are skipped, all existing line items are deleted, and the invoice's total is overwritten to  .00 BDT. This is exactly the bug mentioned in the Developer Workflows documentation.

### Q5.2 Manual Gateway Asset Paths
- **a)** Yes, manual gateway logo and QR paths are explicitly prefixed with /storage/ in 	emplates/checkout/partials/manual-gateway.twig (e.g., <img src="/storage/{{ gateway.logo_path }}">).
- **b)** Because the paths utilize an absolute root reference (/storage/), nested admin and checkout routes render the exact same path correctly without generating 404 errors.
- **c)** QR code rendering uses the exact same absolute prefixing (<img src="/storage/{{ gateway.qr_code_path }}">).

### Q5.3 Clipboard Fallback
- **a)** Admin copy buttons (window.opCopyText in dmin.js) do not call 
avigator.clipboard.writeText directly as the first resort. Instead, they implement a robust fallback creating an offscreen DOM <textarea>, focusing it, and executing document.execCommand("copy").
- **b)** This ensures that self-hosted admin panels running over non-secure HTTP contexts still have functional copy buttons, preventing the silent no-op errors inherent to modern clipboard APIs on HTTP.

### Q5.4 Stranding Risk Audit
- **a)** At every external redirect (gateway hosted page), there is a visible return path. In checkout-status.twig, if the gateway redirects back with a failure, a "Try Again" button (directing to the checkout page) and a "Return to Merchant" button are explicitly rendered to prevent stranding.
- **b)** The checkout page status states are clear and non-misleading ("Processing", "Under Review", "Pending", "Payment Failed").
- **c)** Loading indicators are present on async actions (e.g., showLoading() in checkout.js upon form submission). However, **form validations are NOT synced** between the client and server. The client enforces HTML5 equired attributes for manual gateway fields, but the server (PaymentIntentCheckoutController::pay) blindly accepts whatever payment_details array is POSTed without verifying that the gateway's required schema was fulfilled.
### Quest 6: Attack Surface Mapping & Mitigation

#### Q6.1 Webhook Signature Spoofing
- **a)** Site: Internal Webhooks via WebhookInboundProcessor::validateRequest. Gateway Webhooks via UnifiedWebhookController delegating to GatewayBridge::verifyWebhookSignature (e.g. StripeGateway::verifyWebhook).
- **b)** Yes, signature comparisons strictly use constant-time hash_equals().
- **c)** Yes, a timestamp and replay window is enforced (e.g., bs(time() - (int) ) > 300 in Stripe and MAX_TIMESTAMP_SKEW in WebhookInboundProcessor). The timestamp is also signed (hash_hmac('sha256', "{\}.{\}", )).
- **d)** Yes, the raw unmutated request body ($rawBody) is securely utilized for HMAC calculation.

#### Q6.2 SMS Verification Manipulation
- **a)** The MFS ingestion endpoint is SmsController::receive. GCM decryption (SmsParserService::tryDecrypt) is performed **AFTER** business logic (deduplication via dataRepo->isDuplicate).
- **b)** No, if decryption fails on an unencrypted payload fallback ($msg['body']), it stores a failed decryption record and returns without reaching the matcher logic (ttemptParse).
- **c)** **No.** The openssl_decrypt AES-256-GCM call inside SmsParserService::decryptSmsPayload does NOT utilize Additional Authenticated Data (AAD). Furthermore, the deduplication logic relies on eceivedAt outside the encrypted envelope, meaning an attacker can intercept an encrypted payload and replay it by altering the outer timestamp.

#### Q6.3 Custom Domain & Webhook URL SSRF
- **a)** Validation against private IP segments occurs via UrlValidator::isSafeOutbound and isValidWebhookUrl. It correctly flags RFC 1918, link-local (169.254/16), cloud-metadata (169.254.169.254), and loopback via FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE and explicit string matches.
- **b)** **Yes.** DNS rebinding is effectively mitigated. UrlValidator::resolveSafeWebhookIp resolves the IP exactly once, validates it, and WebhookDispatcher::doSend pins that exact resolved IP via CURLOPT_RESOLVE, completely preventing TOCTOU / DNS rebinding attacks.
- **c)** URL-validation site: src/Security/UrlValidator.php.
