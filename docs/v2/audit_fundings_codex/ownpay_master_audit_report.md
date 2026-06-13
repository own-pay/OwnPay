# OwnPay Master Audit Report

Generated: 2026-06-12

Deliverable status: Deliverable 1 only. Deliverables 2, 3, and 4 were intentionally not created.

## 1. Scope, Methodology & Discovery Map

Scope was limited to static, read-only audit work against the current local OwnPay worktree. No application source, schema, migration, template, configuration, or runtime logic was changed. Prior audit folders were treated only as navigation leads; findings below are based on current file and line evidence.

Method followed the requested prompt phases in order:

| Phase | Execution |
| --- | --- |
| Discovery map | Inventoried front controller, routes, middleware, services, schema, migrations, seeds, CLI, modules, templates, and tests. |
| Invariant verification | Checked tenant scoping, ledger balancing, webhook signature flow, SMS verification flow, domain isolation, API/mobile auth, plugin loading, installer/update routes, and cron paths. |
| Quest execution | Walked payment, ledger, webhook, SMS, redirect/concurrency, CLI, hook, plugin, shared-hosting, and security surfaces. |
| Adversarial self-review | Rechecked CRITICAL and HIGH claims for alternate controls and false positives before inclusion. |
| Synthesis | Consolidated confirmed findings, pass entries, correction guidance, and validation outcomes into this report. |

Discovery inventory:

| Surface | Evidence |
| --- | --- |
| Front controller | `public/index.php:26-30` requires Composer autoload, instantiates `OwnPay\Kernel`, and calls `handle()`. |
| Routes | `config/routes/web.php` registers 179 web routes; `config/routes/api.php` registers 35 API routes; total route registrations: 214. |
| Middleware | `config/middleware.php:18-86` defines global, web, admin, API, admin API, mobile, bootstrap, and webhook stacks. |
| Service registry | `config/services.php:420-432` registers tenant repositories for audit logs, domains, SMS, devices, rate limits, plugins, and related subsystems. |
| Schema | `database/schema.sql` contains 51 `CREATE TABLE` statements, all under the `op_` table prefix. |
| Migrations and seeds | `database/migrations/001_schema_sync.sql` through `008_add_provider_trx_id.sql`; seed files for currencies, roles, SMS templates, and system settings. |
| CLI | `cli/build-update.php` and `cli/create-module.php`. |
| Module inventory | 123 gateway modules, 3 addon modules, and 1 theme module under `modules/`. |
| Tests | PHPUnit suites exist for Unit, Integration, Service, Middleware, Controller, Event, Plugin, and Security in `phpunit.xml`. |

Validation commands run:

| Check | Result |
| --- | --- |
| Target report existence check | Report did not exist before creation, so no snapshot was required. |
| Route inventory scan | 214 current route registrations found. |
| Schema inventory scan | 51 `op_` tables found. |
| Module inventory scan | 123 gateways, 3 addons, 1 theme found. |
| Gateway simulation scan | 27 webhook validation simulation markers and 29 refund simulation markers found. |
| Dangerous-function candidate scan | 15 candidates, mostly CLI/update/install paths requiring manual triage. |
| Legacy `op_env` and SQLite candidate scan | 3 candidates: two test references to SQLite memory DB and one docblock reference in `EnvironmentService`. |
| Composer audit | No advisories and no abandoned packages reported. |
| PHPStan | Level 9 analysis completed with no errors. |
| PHPUnit | Not run because the configured suite targets local MySQL database `ownpay_test` and can mutate test data. |

## 2. Executive Summary

Overall result: OwnPay has strong structural controls in several areas, especially tenant-scoped repositories, domain isolation, double-entry balancing, update package verification, API key comparison, mobile JWT checks, and refund row locking. The audit also found four confirmed findings:

| ID | Severity | Area | Summary |
| --- | --- | --- | --- |
| F-001 | CRITICAL | Webhooks, gateways, ledger | 27 gateway adapters contain simulated webhook signature validation that returns success when a signature header is merely present. |
| F-002 | HIGH | MFS, SMS parsing | Parsed SMS rows are inserted with `match_status = 'accepted'`, but verification jobs only process `pending`, so legitimate MFS confirmations can be stranded. |
| F-003 | HIGH | Ledger, multi-currency | Ledger code resolves accounts by merchant, name, and currency, but schema uniqueness is only merchant and name, breaking multi-currency ledger posting for the same account name. |
| F-004 | MEDIUM | Rate limiting, concurrency | Rate limiting checks the current hit count before the atomic increment, allowing concurrent bursts to exceed configured limits. |

Highest-risk conclusion: the webhook adapter issue is the only CRITICAL finding because it can convert a simulated provider-signature check into a trusted `webhookVerified=true` callback path that can complete transactions and post ledger entries when the adapter verification result is accepted.

## 3. High-Volume Scalability & Database Assessment

Positive observations:

| Area | Evidence | Assessment |
| --- | --- | --- |
| Transaction lookup indexes | `database/schema.sql:295-305` defines primary, unique, merchant status, merchant created, gateway, provider, payment intent, invoice, and payment link indexes. | Core transaction reads have useful indexes for high-volume dashboard, callback, and lookup paths. |
| Idempotency uniqueness | `database/schema.sql:310-321` defines `UNIQUE KEY uk_merchant_key (merchant_id, idempotency_key)`. | API idempotency has a database-level uniqueness guard. |
| Idempotency service | `src/Service/Payment/IdempotencyService.php:43-91` checks existing keys and inserts a processing lock. | Duplicate request handling is present for mutating API methods. |
| Ledger balance check | `src/Service/Payment/LedgerService.php:75-108` calculates total debit/credit and throws when unbalanced before posting entries. | Journal entries are balanced before DB posting. |

Confirmed scalability and schema findings:

| Finding | Impact |
| --- | --- |
| F-003 | Multi-currency posting can fail when a merchant already has the same ledger account name in another currency. |
| F-004 | Login, API, mobile, admin, and checkout rate-limit enforcement can be exceeded under concurrent bursts. |

Operational watch items:

| Item | Evidence | Note |
| --- | --- | --- |
| SQL candidate scan | Broad heuristic found 663 SQL interpolation candidates. | Many are dynamic table names or safe query assembly patterns; this scan is too broad to treat as a finding without per-case review. |
| Dangerous-function scan | 15 candidates in CLI, installer, update, and service paths. | CLI and installer contexts explain several hits; production exposure should still be reviewed during hardening. |

## 4. MFS / SMS-Parsing Edge Case Report

Primary result: MFS/SMS automatic verification has a confirmed status mismatch.

| Issue | Evidence | Effect |
| --- | --- | --- |
| F-002 | `src/Service/Sms/SmsParserService.php:367` writes parsed rows as `accepted`, while `src/Cron/SmsVerificationJob.php:85` and `src/Repository/SmsParsedRepository.php:45` process only `pending`. | Parsed SMS confirmations can remain outside the auto-match queue, leaving related transactions pending until manual action or a separate path changes status. |

Mobile authentication passes:

| Control | Evidence | Assessment |
| --- | --- | --- |
| Mobile SMS route requires mobile middleware | `config/routes/api.php:44` maps `/api/mobile/v1/sms` to `SmsController@receive` with `mobile`. | Route is behind the mobile middleware group. |
| Mobile middleware includes JWT auth | `config/middleware.php:71-76` adds CORS, rate limiter, and `JwtAuthMiddleware`. | SMS ingestion is not public after pairing. |
| JWT validates signature, issuer, audience, and device status | `src/Middleware/JwtAuthMiddleware.php:67-106` decodes HS256, checks claims, and checks device revocation. | Device-bound mobile API authentication is present. |

SMS-specific residual risks:

| Risk | Status |
| --- | --- |
| Ambiguous MFS amount-only matching | Not reported as a finding because `SmsVerificationJob` limits fallback matching by merchant, amount, gateway, and received time through repository calls. |
| Cross-merchant SMS leakage | Not reported as a finding because current cron path scopes repository access with `forTenant($mid)`. |

## 5. High-Concurrency & Redirect Flow Audit

Positive observations:

| Flow | Evidence | Assessment |
| --- | --- | --- |
| Gateway callback completion | `src/Service/Payment/GatewayApiService.php:219-230` performs transaction lookup inside a DB transaction with `FOR UPDATE`. | Callback completion avoids double processing of the same transaction row. |
| Gateway amount verification | `src/Service/Payment/GatewayApiService.php:249-257` fails closed when provider-verified amount is missing, nonnumeric, or different from expected amount. | Amount mismatch does not complete a transaction. |
| Refund preparation | `src/Service/Payment/RefundService.php:83-126` locks parent transaction and existing refunds, then caps total refunds against original amount. | Concurrent refund overage is guarded. |
| Refund balance check | `src/Service/Payment/RefundService.php:135-170` locks merchant payable balance and subtracts pending refunds before allowing a refund. | Refunds are checked against current ledger availability. |
| Refund finalization | `src/Service/Payment/RefundService.php:228-272` locks refund and transaction rows before final status changes. | Final refund state changes are transaction-protected. |

Confirmed concerns:

| Finding | Affected flow |
| --- | --- |
| F-001 | The callback completion lock is sound, but it trusts a webhook-verification result that 27 adapters simulate. |
| F-004 | Rate limiting is not an atomic compare-and-increment decision, so concurrent bursts can pass over the configured limit. |

Redirect and checkout note: checkout, payment-link, invoice, and intent flows were mapped, but no redirect finding is reported because the verified high-risk state transitions reviewed here either use transaction locks or are gated by route middleware. A deeper browser-assisted redirect test suite remains recommended for dynamic gateway return URLs.

## 6. CLI, Hooks & Plugin Ecosystem Register

CLI register:

| File | Role | Risk note |
| --- | --- | --- |
| `cli/build-update.php` | Builds release packages, updates manifests, copies migrations, and can run Composer. | Contains command execution candidates in CLI context; not web-exposed by route inventory. |
| `cli/create-module.php` | Scaffolds gateway/addon/theme modules and hook examples. | Generates plugin templates that should be reviewed before publication. |

Module register:

| Type | Count | Evidence |
| --- | --- | --- |
| Gateway | 123 | Directory inventory under `modules/gateways`. |
| Addon | 3 | Directory inventory under `modules/addons`. |
| Theme | 1 | Directory inventory under `modules/themes`. |

Mock and simulation register:

| Marker | Count | Examples |
| --- | --- | --- |
| Webhook validation simulation | 27 | dlocal, cybersource, worldpay, biller-genie, bluesnap, chase-paymentech, elavon, fastspring, fiserv, moneris, rapyd. |
| Refund simulation | 29 | 2checkout, checkout-com, dlocal, cybersource, first-data, global-payments, payoneer, trustcommerce, tsys. |

Hook register:

| Hook family | Evidence | Assessment |
| --- | --- | --- |
| System lifecycle | `src/Kernel.php` fires boot, route, response, shutdown, and request hooks. | Core lifecycle extension points exist. |
| Payment lifecycle | `src/Service/Payment/TransactionService.php` fires transaction created, completed, failed, and cancelled hooks. | Payment hooks are available for addons. |
| Plugin lifecycle | `src/Plugin/PluginManager.php:192-267` fires before/after activation hooks and runs migrations. | Activation flow is centralized. |
| Webhook plugin hooks | `src/Controller/Webhook/UnifiedWebhookController.php:101-116` dispatches `webhook.incoming.{gateway}` after adapter verification. | Plugin webhook hook dispatch depends on F-001 being fixed. |

Plugin sandbox observations:

| Control | Evidence | Assessment |
| --- | --- | --- |
| Dangerous function list | `src/Plugin/PluginSandbox.php:118-132` lists system execution, eval, file write, reflection, callback, and related risky functions. | Canonical dangerous function list exists. |
| Loader token scan | `src/Plugin/PluginLoader.php:210-267` scans PHP tokens for restricted constructs and references before loading plugin entrypoints. | Runtime plugin loading has static token checks. |
| Runtime SQL sandbox | `src/Core/Database.php:235-247` validates SQL when active owner is a plugin; `src/Event/EventManager.php:316-333` validates plugin-modified query hooks. | Runtime plugin SQL has a containment path. |
| Activation migrations | `src/Plugin/PluginMigrator.php:64-72` executes migration statements during activation. | Treat as manual review zone because activation migrations intentionally alter schema outside normal runtime SQL rules. |

## 7. Security Attack Surface & Mitigation Matrix

| Surface | Current control | Finding or pass | Mitigation priority |
| --- | --- | --- | --- |
| Inbound gateway webhooks | Webhook route uses IP allowlist and adapter verification. | F-001 CRITICAL. | Immediate. Replace simulated adapter checks with real provider HMAC/signature verification and tests. |
| Mobile SMS ingestion | Mobile route uses JWT middleware and device status lookup. | F-002 HIGH. | Immediate. Align parser status and verification cron. |
| Ledger posting | Balanced debit/credit checks and tenant-scoped repositories. | F-003 HIGH. | Immediate. Fix multi-currency uniqueness. |
| Rate limiting | Middleware and DB/Redis counters exist. | F-004 MEDIUM. | Near term. Make limit decision atomic with increment. |
| Custom domains | `src/Middleware/DomainMiddleware.php:89-103` rejects unknown, inactive, unverified, and `/admin` custom-domain requests. | Pass. | Continue tests for custom-domain admin denial. |
| API keys | `src/Middleware/BearerAuthMiddleware.php:66-84` hashes bearer token and uses `hash_equals`. | Pass. | Keep prefix lookup monitored for collision rate. |
| Admin API keys | `src/Middleware/AdminBearerAuthMiddleware.php:71-113` uses timing-safe comparison and requires admin scope. | Pass. | Maintain scope tests. |
| CSRF | `src/Middleware/CsrfMiddleware.php:44-103` checks mutating non-API, non-webhook requests and rotates token. | Pass. | Keep API/webhook auth independent. |
| Security headers | `src/Middleware/SecurityHeadersMiddleware.php:53-89` sets content type, frame, referrer, permissions, HSTS over HTTPS, and CSP mode. | Pass. | Review checkout CSP manifests for least privilege. |
| Outgoing webhooks | `src/Service/Payment/WebhookService.php:107-129` blocks unsafe URLs and signs payloads with HMAC. | Pass. | Keep SSRF tests and HMAC receiver examples. |
| Update packages | `src/Update/UpdateService.php:236-259` requires download URL, checksum, signature, and allowed host; `src/Update/UpdateService.php:274-315` verifies checksum and RSA signature. | Pass. | Keep public key rotation documented. |
| Installer | `src/Kernel.php:245-247` redirects to install only before `.installed`; installer methods reject when installed. | Pass. | Keep storage marker permissions locked down. |
| Cron | `src/Controller/Page/CronController.php:44-70` accepts route, header, or bearer secret and compares with `hash_equals`. | Pass. | Prefer header or bearer secret over route secrets in deployment docs. |

## 8. Low-Resource Shared Hosting Suitability Sheet

Suitability: conditional.

| Dimension | Assessment |
| --- | --- |
| PHP/runtime | `composer.json` requires PHP `^8.3` and extensions bcmath, json, mbstring, openssl, pdo. Hosts lacking PHP 8.3 or bcmath are unsuitable. |
| Database | MySQL/InnoDB schema is assumed. Shared hosts can work if MySQL supports generated columns, JSON, foreign keys, and `FOR UPDATE`. |
| Queue and cron | HTTP cron endpoint exists and is secret-protected, but reliable shared hosting needs scheduler support for cron jobs. |
| Redis | Rate limiter has DB fallback, so Redis is not mandatory. High-traffic deployments should use Redis or a stronger atomic DB limiter. |
| Storage | Update, backup, cache, language, session, and upload paths depend on writable `storage/` and public upload directories. |
| Update system | Self-update creates backups, downloads packages, verifies signatures, extracts archives, and runs migrations. Low disk quotas can fail updates. |
| Module load | 123 gateway modules increase filesystem scan and autoload pressure. Shared hosting with low CPU or slow disks will feel this during plugin discovery and admin pages. |
| Security | Shared hosting must support HTTPS, secure environment variables, restricted document root to `public/`, and protected `storage/`. |

Verdict: OwnPay can run on strong shared hosting for low to moderate traffic, but high-volume payments, many active gateways, SMS automation, and self-update workflows are better suited to VPS or managed PHP hosting with predictable cron, backups, Redis, and database tuning.

## 9. Architectural & Structural Correction Guide

Priority corrections:

| Priority | Target | Correction |
| --- | --- | --- |
| P0 | F-001 webhook adapter verification | Replace every simulated `verifyWebhook()` implementation with provider-specific HMAC/signature verification using stored credentials. Add tests that reject missing, malformed, replayed, and body-tampered signatures. Keep default fail-closed behavior. |
| P0 | Webhook middleware | Consider adding a common request-signature middleware or verified-provider adapter contract so route comments and runtime enforcement match. IP allowlisting should remain a compensating control, not the primary signature proof. |
| P1 | F-002 SMS status flow | Either write parsed SMS rows as `pending` when they need auto-verification, or update the verification job and repository to process a clearly named accepted-for-matching state. Add tests from mobile SMS ingestion through cron completion and ledger posting. |
| P1 | F-003 ledger schema | Change ledger account uniqueness to include `currency`, for example `(merchant_id, name, currency)`, after migrating or deduplicating existing account rows. Add multi-currency payment and refund tests. |
| P2 | F-004 rate limiting | Make rate-limit decision and increment one atomic operation. Redis should use increment result as the decision value; DB should use one atomic upsert or transaction returning the new count before allowing the request. |
| P2 | Plugin release quality | Block production activation of gateway modules whose webhook or refund paths contain simulation markers unless the platform is explicitly in sandbox/demo mode. |
| P3 | Shared hosting docs | Document minimum PHP version, required extensions, cron setup, writable directories, storage hardening, self-update disk needs, and recommended Redis/MySQL settings. |

No public API or interface change was made by this audit. All recommendations are report-only.

## 10. Detailed Findings

### F-001 - CRITICAL - Simulated gateway webhook validation is treated as verified

Affected files:

| Path | Lines | Evidence |
| --- | --- | --- |
| `config/routes/web.php` | 293-297 | Registers `POST /webhook/{gateway}` to `UnifiedWebhookController@handle` with middleware group `webhook`. |
| `config/middleware.php` | 84-86 | `webhook` group contains `IpAllowlistMiddleware` only. |
| `src/Controller/Webhook/UnifiedWebhookController.php` | 85-96 | Calls `$bridge->verifyWebhookSignature($gateway, $merchantId, $rawBody, $req->allHeaders())` and returns 403 only when it returns false or throws. |
| `src/Controller/Webhook/UnifiedWebhookController.php` | 138-140 | Calls `$svc->handleCallback($merchantId, $gateway, $callbackData, true)`. |
| `src/Gateway/GatewayBridge.php` | 155-162 | Calls `$adapter->verifyWebhook($rawBody, $headers, $credentials)`. |
| `src/Gateway/GatewayDefaults.php` | 57-59 | Default `verifyWebhook()` returns false. |
| `modules/gateways/dlocal/DLocalGateway.php` | 254-259 | If a header is present, adapter returns true after a simulation marker. |
| `src/Service/Payment/GatewayApiService.php` | 177-181 | Adds `_op_webhook_verified` when caller passes true, then verifies callback data. |
| `src/Service/Payment/GatewayApiService.php` | 260-270 | Completed or processing transaction is marked complete and ledger posting begins. |

Call chain:

`POST /webhook/{gateway}` -> `webhook` middleware -> `UnifiedWebhookController::handle()` -> `GatewayBridge::verifyWebhookSignature()` -> gateway adapter `verifyWebhook()` -> `GatewayApiService::handleCallback($merchantId, $gateway, $callbackData, true)` -> `TransactionService::complete()` -> `LedgerService::recordPaymentReceived()`.

Key excerpts:

```php
// config/middleware.php:84-86
'webhook' => [
    \OwnPay\Middleware\IpAllowlistMiddleware::class,
],
```

```php
// src/Controller/Webhook/UnifiedWebhookController.php:90-92
if (!$bridge->verifyWebhookSignature($gateway, $merchantId, $rawBody, $req->allHeaders())) {
    return Response::json(['error' => 'Webhook signature verification failed'], 403);
}
```

```php
// modules/gateways/dlocal/DLocalGateway.php:254-259
if ($signature === '') {
    return false;
}
return true;
```

```php
// src/Service/Payment/GatewayApiService.php:260-270
if (in_array($transaction['status'], ['pending', 'processing', 'callback_processing'], true)) {
    $this->transactions->complete((int) $txnId, $merchantId);
    $this->ledger->recordPaymentReceived(
        $merchantId,
        (int) $txnId,
```

Impact:

An inbound webhook for any affected gateway can pass adapter verification when the expected signature header is merely non-empty. If the payload also satisfies the adapter callback verification and transaction amount checks, OwnPay treats the request as provider-verified, completes a transaction, and posts ledger entries.

Affected adapter class:

The exact simulation marker `Webhook timing-safe validation check simulation` appears in 27 gateway PHP files under `modules/gateways/`, including `dlocal`, `cybersource`, `worldpay`, `biller-genie`, `bluesnap`, `chase-paymentech`, `elavon`, `fastspring`, `fattmerchant`, `fiserv`, `global-payments`, `heartland`, `helcim`, `moneris`, `neteller`, `nmi`, `payline-data`, `payment-depot`, `payoneer`, `paytrace`, `rapyd`, `shift4`, `skrill`, `stax`, `trustcommerce`, and `tsys`.

Refutation notes:

| Possible refutation | Result |
| --- | --- |
| Default gateway verification fails closed. | True for adapters that do not override `verifyWebhook()`, but the affected adapters do override it and return true after a header check. |
| Webhook middleware verifies signatures. | Not supported by current middleware evidence; the group contains only `IpAllowlistMiddleware`. |
| IP allowlisting prevents exploitation. | It reduces exposure if configured strictly, but it is not cryptographic payload authentication and does not validate body integrity. |
| Amount mismatch blocks completion. | True and valuable, but not sufficient because a forged payload with a correct transaction reference and amount can still ride through simulated signature success. |

### F-002 - HIGH - Parsed SMS records are not processed by the verification job

Affected files:

| Path | Lines | Evidence |
| --- | --- | --- |
| `config/routes/api.php` | 40-44 | Mobile SMS ingestion route maps to `Api\Mobile\SmsController@receive` with `mobile` middleware. |
| `src/Controller/Api/Mobile/SmsController.php` | 128-133 | Builds parsed messages and calls `$this->parser->processBatch($deviceId, $mid, $parsedMessages)`. |
| `src/Service/Sms/SmsParserService.php` | 197-204 | Parses, stores the SMS record, notifies device, and returns `accepted`. |
| `src/Service/Sms/SmsParserService.php` | 352-367 | Stores parsed rows with `match_status` set to `accepted` when parsing succeeds. |
| `src/Cron/SmsVerificationJob.php` | 83-86 | Selects only merchants with SMS rows where `match_status = 'pending'`. |
| `src/Repository/SmsParsedRepository.php` | 42-47 | Fetches only rows where `match_status = 'pending'`. |
| `database/schema.sql` | 688-695 | `op_sms_parsed.match_status` supports both `pending` and `accepted`; index covers merchant and status. |

Call chain:

`POST /api/mobile/v1/sms` -> `SmsController::receive()` -> `SmsParserService::processBatch()` -> `SmsParserService::processOne()` -> `SmsDataRepository::create()` with `match_status = 'accepted'` -> `SmsVerificationJob::run()` queries only `pending` -> `SmsParsedRepository::findUnmatched()` queries only `pending`.

Key excerpts:

```php
// src/Service/Sms/SmsParserService.php:197-204
$parsed = $this->attemptParse($rawMessage, $sender, $brandId);
$record = $this->buildRecord($deviceUuid, $brandId, $localId, $sender, $receivedAt, $encrypted, $rawMessage, $parsed);
$id = $this->dataRepo->create($record);
return $this->makeResult($localId, 'accepted', 'sms_' . $id);
```

```php
// src/Service/Sms/SmsParserService.php:367
'match_status'     => ($parsed === null) ? 'admin_review' : 'accepted',
```

```php
// src/Cron/SmsVerificationJob.php:83-86
$merchants = $this->db->fetchAll(
    "SELECT DISTINCT merchant_id FROM op_sms_parsed WHERE match_status = 'pending'"
);
```

```php
// src/Repository/SmsParsedRepository.php:44-47
"SELECT * FROM {$this->table} WHERE merchant_id = :mid AND match_status = 'pending'
 ORDER BY received_at DESC LIMIT :lim",
```

Impact:

Successfully parsed MFS/SMS payment confirmations can be stored outside the queue consumed by the verification cron. The affected transaction can remain pending even though a valid confirmation exists, causing delayed settlement, manual reconciliation, and customer support load.

Refutation notes:

| Possible refutation | Result |
| --- | --- |
| Schema default is `pending`. | The service explicitly supplies `accepted`, so the default does not apply. |
| Cron may process accepted elsewhere. | Current scans found verification cron and repository methods filtering on `pending`; tests and dashboards reference `accepted`, but not as an auto-verification queue. |
| This marks unpaid invoices paid. | Not supported by current evidence. The verified issue is the opposite: valid parsed confirmations can fail to complete payments automatically. |

### F-003 - HIGH - Ledger account uniqueness conflicts with multi-currency account resolution

Affected files:

| Path | Lines | Evidence |
| --- | --- | --- |
| `src/Service/Payment/GatewayApiService.php` | 266-270 | Successful callback completion posts payment ledger entries. |
| `src/Service/Payment/LedgerService.php` | 84-85 | Resolves each ledger account through `findOrCreateAccount($code, $acctType, $currency, $merchantId)`. |
| `src/Repository/LedgerRepository.php` | 55-65 | Looks up account by `name`, `currency`, and `merchant_id`. |
| `src/Repository/LedgerRepository.php` | 71-79 | Inserts a new account if the exact name/currency/merchant row is not found. |
| `database/schema.sql` | 491-500 | Unique key is `uk_merchant_name (merchant_id, name)`, without `currency`. |
| `src/Repository/LedgerRepository.php` | 319-322 | Merchant balance lookup filters by `merchant_id`, `currency`, and `name`. |

Call chain:

Payment or refund ledger posting -> `LedgerService::postEntries()` -> `LedgerRepository::findOrCreateAccount(name, type, currency, merchantId)` -> schema unique key blocks a second currency for the same merchant/account name.

Key excerpts:

```php
// src/Service/Payment/LedgerService.php:84-85
$acctType = $this->getAccountType($code);
$account = $this->ledger->findOrCreateAccount($code, $acctType, $currency, $merchantId);
```

```php
// src/Repository/LedgerRepository.php:55-65
$where = '`name` = :name AND `currency` = :cur';
$params = ['name' => $name, 'cur' => $currency];
$where .= ' AND `merchant_id` = :mid';
$account = $this->db->fetchOne("SELECT * FROM {$this->table} WHERE {$where} LIMIT 1", $params);
```

```sql
-- database/schema.sql:491-500
CREATE TABLE `op_ledger_accounts` (
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'BDT',
  UNIQUE KEY `uk_merchant_name` (`merchant_id`, `name`)
)
```

Impact:

A merchant can create ledger account rows for one currency, but a later payment or refund in another currency using the same account name can fail on the database unique key. This blocks multi-currency accounting despite service code and balance reads being currency-aware.

Refutation notes:

| Possible refutation | Result |
| --- | --- |
| The service only uses one currency. | Not supported by schema and routes; transactions store `currency`, currencies table exists, and balance methods accept currency. |
| Unique key protects duplicate accounts. | It protects duplicates too broadly because it omits currency while code treats currency as part of identity. |
| Balance query ignores currency. | It does not; `merchantBalance()` filters by currency, confirming currency-aware design intent. |

### F-004 - MEDIUM - Rate-limit decision is not atomic with increment

Affected files:

| Path | Lines | Evidence |
| --- | --- | --- |
| `config/middleware.php` | 31-82 | Login, admin, API, admin API, API public, mobile, and mobile bootstrap groups include `RateLimiterMiddleware`. |
| `src/Middleware/RateLimiterMiddleware.php` | 93-108 | Reads hits, checks `if ($hits >= $limit)`, then increments separately. |
| `src/Middleware/RateLimiterMiddleware.php` | 198-202 | DB fallback reads `hits` with a separate `SELECT`. |
| `src/Middleware/RateLimiterMiddleware.php` | 251-257 | DB fallback increments with atomic upsert after the allow/deny decision. |
| `database/schema.sql` | 754-762 | `op_rate_limits` stores `key_name`, `hits`, and expiry with unique key on key name. |

Call chain:

Protected route group -> `RateLimiterMiddleware::handle()` -> `getHits()` -> compare to limit -> `increment()` -> downstream handler.

Key excerpts:

```php
// src/Middleware/RateLimiterMiddleware.php:96-108
$hits = $this->getHits($key, $now, $window);
if ($hits >= $limit) {
    return Response::json([
        'success' => false,
        'message' => 'Rate limit exceeded. Try again later.',
    ], 429);
}
$this->increment($key, $now, $window);
```

```php
// src/Middleware/RateLimiterMiddleware.php:198-202
$row = $db->fetchOne(
    "SELECT hits FROM op_rate_limits WHERE key_name = :k AND expires_at > :now LIMIT 1",
    ['k' => $key, 'now' => $now]
);
```

```php
// src/Middleware/RateLimiterMiddleware.php:251-257
$db->execute(
    "INSERT INTO op_rate_limits (key_name, hits, window_start, expires_at)
     VALUES (:k, 1, :ws, :exp)
     ON DUPLICATE KEY UPDATE
        hits = IF(expires_at > :now2, hits + 1, 1),
```

Impact:

Concurrent requests can read the same pre-increment value below the limit, all pass the comparison, and only then increment. The limiter eventually records the burst, but enforcement is delayed by the race. Login and mobile bootstrap endpoints are the most security-sensitive affected routes.

## 11. Pass Log

| ID | Quest item | Evidence | Result |
| --- | --- | --- | --- |
| P-001 | Front controller and boot path | `public/index.php:26-30` loads autoload, instantiates `OwnPay\Kernel`, and calls `handle()`. | Pass. Single public front controller confirmed. |
| P-002 | Route inventory | Route scan found 179 web and 35 API registrations. | Pass. Inventory captured. |
| P-003 | Middleware grouping | `config/middleware.php:18-86` defines global, web, auth, admin, API, mobile, and webhook groups. | Pass with F-001 and F-004 caveats. |
| P-004 | Tenant scoping primitive | `src/Repository/TenantScope.php:29-58` clones repositories for tenants and throws when tenant scope is missing. | Pass. |
| P-005 | Domain isolation | `src/Middleware/DomainMiddleware.php:89-103` rejects unknown/inactive/unverified domains and blocks `/admin` on custom domains. | Pass. |
| P-006 | API key authentication | `src/Middleware/BearerAuthMiddleware.php:66-84` hashes bearer token and compares with `hash_equals`. | Pass. |
| P-007 | Admin API scope | `src/Middleware/AdminBearerAuthMiddleware.php:71-113` requires timing-safe key match and admin scope. | Pass. |
| P-008 | Mobile JWT authentication | `src/Middleware/JwtAuthMiddleware.php:67-106` validates HS256 token, required claims, issuer, audience, and device status. | Pass. |
| P-009 | Idempotency uniqueness | `database/schema.sql:310-321` and `src/Service/Payment/IdempotencyService.php:43-91` provide tenant-key uniqueness and processing lock behavior. | Pass. |
| P-010 | Callback row locking | `src/Service/Payment/GatewayApiService.php:219-230` uses DB transaction and `FOR UPDATE` on transaction lookup. | Pass with F-001 caveat. |
| P-011 | Callback amount check | `src/Service/Payment/GatewayApiService.php:249-257` fails when verified amount is missing or mismatched. | Pass. |
| P-012 | Ledger balancing | `src/Service/Payment/LedgerService.php:75-108` totals debit and credit and throws when unbalanced. | Pass with F-003 caveat. |
| P-013 | Payment ledger direction | `src/Service/Payment/LedgerService.php:161-175` debits cash and credits merchant payable plus platform fee revenue. | Pass. |
| P-014 | Refund ledger direction | `src/Service/Payment/LedgerService.php:207-221` credits cash and debits merchant payable plus platform fee revenue. | Pass. |
| P-015 | Refund concurrency | `src/Service/Payment/RefundService.php:83-126` and `228-272` lock relevant rows during preparation and finalization. | Pass. |
| P-016 | Outgoing webhook SSRF and HMAC | `src/Service/Payment/WebhookService.php:107-129` checks URL safety and signs body with HMAC. | Pass. |
| P-017 | Update integrity | `src/Update/UpdateService.php:236-259` requires checksum, signature, and allowed host; `274-315` verifies checksum and RSA signature. | Pass. |
| P-018 | Update ZIP traversal guard | `src/Update/UpdateService.php:478-487` rejects names with parent traversal or absolute path before extraction. | Pass. |
| P-019 | Installer lockout | `src/Kernel.php:245-247` redirects non-install traffic before install; installer methods reject requests after `.installed`. | Pass. |
| P-020 | Cron secret | `src/Controller/Page/CronController.php:44-70` resolves route/header/bearer secret and checks with `hash_equals`. | Pass. |
| P-021 | Plugin token scan | `src/Plugin/PluginLoader.php:210-267` scans plugin PHP files for restricted constructs and dangerous references. | Pass. |
| P-022 | Plugin sandbox registration | `src/Plugin/PluginLoader.php:371-384` creates `PluginSandbox` and registers it in `PluginRegistry`. | Pass. |
| P-023 | Runtime plugin SQL guard | `src/Core/Database.php:235-247` and `src/Event/EventManager.php:316-333` validate plugin-owned SQL. | Pass. |
| P-024 | Plugin migrations | `src/Plugin/PluginMigrator.php:64-72` executes activation migrations in a transaction. | Investigation entry. Manual review required for third-party migration content. |
| P-025 | Mock/simulation registry | Scan found 27 webhook validation simulation markers and 29 refund simulation markers. | Finding for webhooks; refund simulation remains production-readiness risk. |
| P-026 | Shared hosting suitability | Composer, schema, cron, update, storage, and module inventory reviewed. | Conditional pass for low traffic; VPS recommended for high volume. |
| P-027 | Static quality | `composer audit --format=json` returned no advisories; `vendor/bin/phpstan analyse --no-progress` returned no errors. | Pass. |
| P-028 | PHPUnit decision | `phpunit.xml` points to `ownpay_test` MySQL and can mutate test data. | Not run; recommended before remediation release. |
