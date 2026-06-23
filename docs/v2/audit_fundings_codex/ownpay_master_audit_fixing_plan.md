# OwnPay Master Audit Fixing Plan

Generated: 2026-06-12

Source report: `docs/v2/audit_fundings_codex/ownpay_master_audit_report.md`

Purpose: give the next developer a concrete remediation path for every confirmed audit finding and a release discipline that drives OwnPay to zero known critical, high, and medium defects before production deployment.

Important framing: no plan can prove that a complex payment platform has no bugs at all. For this plan, "zero bugs" means no known release-blocking defects, no open CRITICAL/HIGH/MEDIUM findings from the audit, all listed tests passing, clean static/security checks, and no remaining simulation paths in production payment flows.

## 1. Scope

In scope:

- Fix F-001, F-002, F-003, and F-004 from the audit report.
- Add migrations, source changes, tests, and documentation needed for those fixes.
- Add stability gates that prevent production release while known payment, ledger, webhook, SMS, or auth-sensitive bugs remain.
- Add regression coverage around webhooks, SMS verification, ledger multi-currency posting, refunds, rate limiting, plugin activation, update safety, and tenant isolation.

Out of scope:

- Rebranding, UI redesign, or non-remediation feature work.
- Changing public API contracts unless a finding cannot be fixed safely without an explicit versioned change.
- Removing gateway modules solely because they are unfinished. Gate them, fail closed, or mark them sandbox-only until fully implemented.

## 2. Release Principle

Use this order:

1. Freeze feature work on a remediation branch.
2. Add tests that reproduce the current failures.
3. Implement the smallest safe fix for each finding.
4. Run migrations and regression tests on a disposable copy of production-like data.
5. Block release until every gate in Section 10 is green.
6. Deploy behind maintenance windows for schema and payment-flow changes.
7. Monitor webhook completion, SMS matching, ledger posting, refund failure rate, rate-limit rejects, and error logs for at least one full business cycle.

Recommended branch:

```powershell
git checkout -b codex/audit-finding-remediation
```

Do not mix unrelated cleanup with these fixes. Payment and ledger remediation should be easy to review.

## 3. Finding Summary And Owner Matrix

| ID | Severity | Owner | Primary files | Release blocker |
| --- | --- | --- | --- | --- |
| F-001 | CRITICAL | Payments/gateway developer plus security reviewer | `src/Controller/Webhook/UnifiedWebhookController.php`, `src/Gateway/GatewayBridge.php`, `src/Gateway/GatewayDefaults.php`, `modules/gateways/*/*Gateway.php`, `tests/` | Yes |
| F-002 | HIGH | SMS/mobile developer plus payments reviewer | `src/Service/Sms/SmsParserService.php`, `src/Cron/SmsVerificationJob.php`, `src/Repository/SmsParsedRepository.php`, `src/Repository/SmsDataRepository.php`, `database/migrations/`, `tests/` | Yes |
| F-003 | HIGH | Ledger/database developer | `database/schema.sql`, `database/migrations/`, `src/Repository/LedgerRepository.php`, `src/Service/Payment/LedgerService.php`, refund/payment tests | Yes |
| F-004 | MEDIUM | Platform/security developer | `src/Middleware/RateLimiterMiddleware.php`, `src/Repository/RateLimitRepository.php`, `database/schema.sql`, `tests/` | Yes |
| Stability gates | HIGH | Release owner | `composer.json`, CI workflow, `tests/`, docs | Yes |

## 4. Phase 0 - Safety Setup

Before code changes:

1. Create a remediation branch.
2. Confirm current worktree changes and separate unrelated work.
3. Snapshot the database in the local test environment.
4. Create a disposable MySQL database for integration tests.
5. Record current simulation markers:

```powershell
rg -n "Webhook timing-safe validation check simulation|Dynamic refund simulation" modules\gateways -g "*.php"
```

6. Record current static check baseline:

```powershell
composer audit --format=json
vendor\bin\phpstan analyse --no-progress
```

7. Confirm all tests target disposable data before running:

```powershell
Get-Content phpunit.xml
```

8. If `ownpay_test` does not exist or is shared, create a fresh disposable database and point `phpunit.xml` or environment variables at it.

Done when:

- A remediation branch exists.
- A disposable test database exists.
- Baseline scans are saved in the developer notes or pull request.
- No unrelated source edits are staged with remediation work.

## 5. Fix F-001 - Simulated Gateway Webhook Validation

### Goal

No gateway webhook may be treated as provider-verified unless the raw body and headers pass provider-specific cryptographic verification using merchant-specific stored credentials. If a gateway does not have a real verifier, it must fail closed in production.

### Current failure chain

`POST /webhook/{gateway}` -> `UnifiedWebhookController::handle()` -> `GatewayBridge::verifyWebhookSignature()` -> gateway adapter `verifyWebhook()` -> `GatewayApiService::handleCallback($merchantId, $gateway, $callbackData, true)` -> transaction completion -> ledger posting.

The audit found 27 adapters with a simulation marker that can return true after only detecting a non-empty signature header.

### Implementation steps

1. Inventory affected adapters.

Run:

```powershell
rg -l "Webhook timing-safe validation check simulation" modules\gateways -g "*.php"
```

Create a working checklist from the output. Every listed adapter needs one of these final states:

- Real cryptographic verification implemented and tested.
- Explicitly disabled for production webhooks with a clear error.
- Marked sandbox-only and prevented from activation in production.

2. Add a common verification helper.

Create a small helper such as:

- `src/Security/WebhookSignatureVerifier.php`

Responsibilities:

- Normalize header names case-insensitively.
- Read the exact raw body without mutation.
- Verify HMAC SHA-256, HMAC SHA-512, RSA, Ed25519, or provider-specific signature formats.
- Use `hash_equals()` for HMAC comparisons.
- Reject missing signatures, missing secrets, malformed timestamps, timestamp skew outside the configured tolerance, and replayed event IDs where provider event IDs exist.
- Return structured results: `valid`, `reason`, `provider_event_id`, `timestamp`.

Do not put provider secrets in logs. Log only reason codes and payload hashes.

3. Tighten the adapter contract.

Review:

- `src/Gateway/GatewayAdapterInterface.php`
- `src/Gateway/GatewayDefaults.php`
- `src/Gateway/GatewayBridge.php`

Recommended contract:

- `verifyWebhook(string $rawBody, array $headers, array $credentials): bool` remains fail-closed.
- Default implementation in `GatewayDefaults` continues returning false.
- Adapter implementations must call the helper or implement a provider-specific verifier.
- Adapters with no provider webhook spec return false in production.

4. Fix affected adapters.

For each affected gateway:

- Identify provider docs for webhook signature algorithm.
- Map credential keys from `op_gateway_configs.credentials`.
- Extract provider signature headers exactly.
- Verify against the raw request body.
- Enforce timestamp tolerance where the provider includes timestamp headers.
- Reject if required credential is missing.
- Remove the simulation marker.
- Remove any bare `return true` from `verifyWebhook()`.

If provider docs are not available:

- Make `verifyWebhook()` return false.
- Add admin-facing text that the gateway requires manual confirmation or sandbox-only mode until webhook verification is implemented.
- Add a test asserting production webhook calls fail closed.

5. Add replay protection.

Use existing webhook delivery/event tables if appropriate, or add a minimal table/migration for inbound provider event IDs:

- `merchant_id`
- `gateway_slug`
- `provider_event_id`
- `payload_hash`
- `received_at`
- unique key on `(merchant_id, gateway_slug, provider_event_id)`

If provider event ID is unavailable, rely on timestamp tolerance plus payload hash only as a secondary control. Do not treat payload hash alone as full replay protection for providers without event IDs.

6. Align middleware comments and enforcement.

Review `config/middleware.php:84-86`. The current comment says webhook signature verification, but the group only includes IP allowlisting. Either:

- Change the comment to say IP allowlisting only and keep cryptographic verification in adapters, or
- Add a common `WebhookIngressMiddleware` that enforces request size, IP allowlist, and gateway slug normalization before controller dispatch.

Do not move provider-specific signature logic into generic middleware unless it can safely resolve merchant and gateway credentials before body parsing.

7. Gate plugin activation and production readiness.

Update plugin/gateway activation logic so production cannot activate a gateway adapter that still contains known simulation markers or declares incomplete webhook verification.

Likely files:

- `src/Plugin/PluginLoader.php`
- `src/Plugin/PluginManager.php`
- `src/Plugin/PluginManifest.php`
- gateway `manifest.json` files

Recommended manifest field:

```json
{
  "webhook_verification": "implemented"
}
```

Allowed values:

- `implemented`
- `not_supported`
- `sandbox_only`

Production activation should reject `sandbox_only` and `not_supported` when the gateway is configured for automated webhook completion.

### Tests to add

Add tests before or alongside implementation:

- `tests/Security/WebhookSignatureVerifierTest.php`
- `tests/Integration/GatewayWebhookVerificationTest.php`
- Provider-specific tests for at least each affected adapter family.

Minimum test matrix:

| Case | Expected |
| --- | --- |
| Missing signature header | 403 or adapter false |
| Empty signature header | 403 or adapter false |
| Wrong signature | 403 or adapter false |
| Correct signature with tampered body | 403 or adapter false |
| Correct signature with correct body | Accepted only if callback verification and amount match |
| Expired timestamp | 403 or adapter false |
| Replayed provider event ID | Idempotent rejection or no duplicate completion |
| Missing credential secret | 403 or adapter false |
| Gateway without implemented verifier in production | Activation blocked or webhook false |

Payment-flow regression:

- Seed a pending transaction.
- Send a forged webhook with correct amount but invalid signature.
- Assert transaction remains pending.
- Assert no ledger transaction exists.
- Send a valid signed webhook.
- Assert transaction completes once.
- Assert exactly one balanced ledger posting.

### Acceptance criteria

- `rg -n "Webhook timing-safe validation check simulation|return true;" modules\gateways -g "*.php"` returns no unsafe webhook verifier hits.
- Every production-capable gateway webhook verifier has tests.
- Forged webhook cannot complete a transaction.
- Valid webhook still completes a transaction once.
- Duplicate valid webhook is idempotent.
- Composer audit, PHPStan, and PHPUnit pass.

### Rollback plan

If production webhooks fail after deploy:

- Disable automated webhook completion for the affected gateway.
- Keep manual verification available.
- Revert only the affected gateway adapter verifier, not ledger or SMS fixes.
- Keep fail-closed behavior in place until the provider algorithm is corrected.

## 6. Fix F-002 - SMS Parsed Status And Verification Flow

### Goal

A valid parsed MFS/SMS payment confirmation that should auto-match must enter the queue consumed by `SmsVerificationJob`. API response status may remain `accepted`, but database match status must be aligned with verification semantics.

### Current failure chain

`POST /api/mobile/v1/sms` -> `SmsController::receive()` -> `SmsParserService::processBatch()` -> `SmsParserService::buildRecord()` writes `match_status = 'accepted'` -> `SmsVerificationJob::run()` selects only `pending` -> valid SMS confirmation is not matched automatically.

### Implementation decision

Use explicit status semantics:

| Status | Meaning |
| --- | --- |
| `pending` | Parsed payment candidate waiting for auto-match. |
| `matched` | Linked to a transaction and ledger posted. |
| `unmatched` | Auto-match attempted and no eligible transaction found. |
| `admin_review` | Parsed result missing critical fields or low confidence. |
| `ignored` | Non-payment or irrelevant SMS. |
| `parse_error` | Decryption or parsing failed. |
| `accepted` | Keep only for legacy rows or dashboard display if needed; do not write for new auto-match candidates. |

### Implementation steps

1. Add central constants.

Create one source of truth for SMS match statuses:

- `src/Enum/SmsMatchStatus.php` or `src/Service/Sms/SmsMatchStatus.php`

Use constants in parser, repositories, cron, controllers, and tests. Avoid scattered string literals.

2. Update parser write status.

File:

- `src/Service/Sms/SmsParserService.php`

Change `buildRecord()` logic:

- If decryption failed, keep `parse_error`.
- If parsed result is null, use `admin_review`.
- If parsed type is credit/payment and amount or provider transaction ID is available, use `pending`.
- If parsed type is debit, balance-only, promotional, or unsupported, use `ignored` or `admin_review` based on confidence.
- Keep API response `accepted` from `makeResult()` because it means the phone upload was accepted by the server, not that DB match status is accepted.

3. Update repository queue methods.

File:

- `src/Repository/SmsParsedRepository.php`

Preferred approach:

- Keep `findUnmatched()` as the queue method but make its name less misleading in a follow-up refactor.
- Query only statuses intended for auto-match, preferably `pending`.
- If backward compatibility is needed during migration, temporarily include legacy `accepted` rows with payment-like data:

```sql
match_status IN ('pending', 'accepted')
AND (amount IS NOT NULL OR trx_id IS NOT NULL)
```

After migration, return to `pending` only.

4. Update cron matching state transitions.

File:

- `src/Cron/SmsVerificationJob.php`

Add explicit outcomes:

- Matched transaction: `matched`, set `transaction_id`, complete transaction, post ledger once.
- No eligible transaction after lookup: `unmatched` if confidence is high and fields are complete.
- Ambiguous amount-only match: `admin_review`, not automatic completion.
- Missing amount and transaction reference: `admin_review`.

Do not complete a transaction unless:

- Merchant ID matches.
- Transaction is still pending.
- Amount matches exactly in transaction currency.
- Gateway slug or provider transaction ID matches when available.
- SMS row is locked or update condition prevents double matching.

5. Add row locking or conditional update.

In the cron transaction, prevent two workers from matching the same SMS:

- Lock the SMS row with `FOR UPDATE`, or
- Update with condition `WHERE id = :id AND merchant_id = :mid AND match_status = 'pending'`.

If the affected row count is zero, another worker already handled it. Skip safely.

6. Migrate historical rows.

Add migration:

- `database/migrations/009_sms_match_status_alignment.sql`

Migration intent:

- Convert legacy unlinked `accepted` payment candidates to `pending`.
- Leave already linked or manually reviewed rows untouched.

Suggested migration logic:

```sql
UPDATE op_sms_parsed
SET match_status = 'pending'
WHERE match_status = 'accepted'
  AND transaction_id IS NULL
  AND (amount IS NOT NULL OR trx_id IS NOT NULL);
```

If the product wants `accepted` retained for dashboards, document that it is legacy-only.

7. Update admin and dashboard views.

Likely files:

- `templates/admin/sms-data.twig`
- `templates/admin/sms-center/index.twig`
- `src/Controller/Admin/SmsDataController.php`
- `src/Controller/Admin/SmsTemplateAdminController.php`
- `src/Repository/SmsDataRepository.php`

Ensure filters and counts show:

- pending
- matched
- unmatched
- admin_review
- ignored
- parse_error

8. Update tests.

Likely tests:

- `tests/Service/SmsParserServiceTest.php`
- `tests/Integration/SmsParsingIntegrationTest.php`
- `tests/Integration/SovereignArchitectureTest.php`
- `tests/Integration/AdminApiSecurityTest.php`

Add a new integration test:

- Mobile SMS upload creates an `op_sms_parsed` row with `pending`.
- Cron matches it to the correct merchant transaction.
- Transaction becomes completed.
- Ledger has one balanced payment posting.
- SMS row becomes matched.

Add negative tests:

- SMS for Brand A cannot complete Brand B transaction.
- Amount mismatch goes to `admin_review` or `unmatched`.
- Duplicate SMS local ID is not processed twice.
- Two cron workers cannot double-complete the same transaction.

### Acceptance criteria

- New payment candidate SMS rows use `pending`, not `accepted`.
- `SmsVerificationJob` processes new rows without manual status edits.
- Valid MFS confirmation completes exactly one pending transaction and posts exactly one ledger entry.
- Cross-merchant and amount-mismatch tests pass.
- Dashboard filters show the updated statuses.

### Rollback plan

If SMS auto-matching behaves unexpectedly:

- Disable `SmsVerificationJob` from cron temporarily.
- Keep SMS ingestion active for manual review.
- Revert parser status write to non-auto-match behavior only if manual review can handle volume.
- Do not roll back ledger or webhook fixes.

## 7. Fix F-003 - Ledger Multi-Currency Account Uniqueness

### Goal

Ledger account identity must match service behavior. If code resolves accounts by merchant, account name, and currency, the database unique key must include all three columns.

### Current failure chain

Payment or refund ledger posting -> `LedgerService::postEntries()` -> `LedgerRepository::findOrCreateAccount(name, type, currency, merchantId)` -> schema unique key `(merchant_id, name)` blocks same merchant/account name in a second currency.

### Implementation steps

1. Add a migration.

Create:

- `database/migrations/010_ledger_account_currency_unique.sql`

Migration:

```sql
ALTER TABLE op_ledger_accounts
  DROP INDEX uk_merchant_name,
  ADD UNIQUE KEY uk_merchant_name_currency (`merchant_id`, `name`, `currency`);
```

Before running it on any existing database, execute a duplicate check:

```sql
SELECT merchant_id, name, currency, COUNT(*) AS c
FROM op_ledger_accounts
GROUP BY merchant_id, name, currency
HAVING c > 1;
```

If duplicates exist in an environment due to prior manual edits, stop and reconcile before applying the unique key.

2. Update base schema.

File:

- `database/schema.sql`

Change:

```sql
UNIQUE KEY `uk_merchant_name` (`merchant_id`, `name`)
```

To:

```sql
UNIQUE KEY `uk_merchant_name_currency` (`merchant_id`, `name`, `currency`)
```

3. Harden account creation against races.

File:

- `src/Repository/LedgerRepository.php`

Current `findOrCreateAccount()` selects then inserts. Keep the select, but catch duplicate-key exceptions on insert:

- Attempt insert.
- If duplicate-key exception occurs, re-select by merchant, name, currency.
- If still missing, rethrow.

This prevents first-use concurrent payments in the same merchant/account/currency from failing unnecessarily.

4. Consider account-type consistency.

Before returning an existing account, check that existing `type` matches expected account type:

- `CASH` should be asset.
- `MERCHANT_PAYABLE` should be liability.
- `PLATFORM_FEE_REVENUE` should be revenue.

If mismatch is detected, throw and log a ledger integrity error. Do not silently reuse a wrong-type account.

5. Update balance and reconciliation tests.

Add tests:

- Merchant can receive BDT payment and USD payment.
- Both create separate `MERCHANT_PAYABLE` rows by currency.
- `merchantBalance($merchantId, 'BDT')` returns only BDT balance.
- `merchantBalance($merchantId, 'USD')` returns only USD balance.
- Refund in USD debits USD merchant payable only.
- Duplicate ledger posting for same transaction remains idempotent.

Likely test files:

- `tests/Integration/FinancialLeakageAuditTest.php`
- new `tests/Integration/LedgerMultiCurrencyTest.php`
- `tests/Unit/LedgerRepositoryTest.php` if repository unit patterns exist.

6. Update installer and update package flows.

Ensure the migration is included in:

- `database/migrations/`
- release build manifest generated by `cli/build-update.php`
- installer schema import via `database/schema.sql`

### Acceptance criteria

- Fresh install has unique key `(merchant_id, name, currency)`.
- Upgraded install has the same unique key.
- Multi-currency payment and refund tests pass.
- No ledger account has a wrong account type for known system accounts.
- Reconciliation remains currency-specific.

### Rollback plan

If migration fails:

- Stop deployment before app traffic resumes.
- Restore DB snapshot.
- Investigate duplicate rows or unsupported MySQL version.
- Do not deploy code that assumes currency-aware uniqueness until migration succeeds.

## 8. Fix F-004 - Atomic Rate Limiting

### Goal

The rate limiter must make the allow/deny decision using the incremented count, not a stale pre-increment count.

### Current failure chain

Protected route group -> `RateLimiterMiddleware::handle()` -> `getHits()` -> compare with limit -> `increment()` -> downstream handler. Concurrent requests can all read below-limit count before any increment is visible.

### Implementation steps

1. Replace split read/increment with a consume operation.

File:

- `src/Middleware/RateLimiterMiddleware.php`

Replace:

```php
$hits = $this->getHits($key, $now, $window);
if ($hits >= $limit) {
    return 429;
}
$this->increment($key, $now, $window);
```

With:

```php
$hits = $this->consumeHit($key, $now, $window);
if ($hits > $limit) {
    return 429;
}
```

Use incremented count for headers:

- `X-RateLimit-Limit`
- `X-RateLimit-Remaining`
- `Retry-After`

2. Implement Redis atomic path.

In Redis:

- `INCR op:{key}`
- If result is 1, set expiry to window.
- If result is greater than limit, reject request.
- If Redis command fails on login or mobile bootstrap routes, keep existing fail-closed behavior.

3. Implement DB atomic path.

Preferred MySQL pattern:

```sql
INSERT INTO op_rate_limits (key_name, hits, window_start, expires_at)
VALUES (:k, 1, :ws, :exp)
ON DUPLICATE KEY UPDATE
  hits = IF(expires_at > :now, LAST_INSERT_ID(hits + 1), LAST_INSERT_ID(1)),
  window_start = IF(expires_at > :now2, window_start, :ws2),
  expires_at = IF(expires_at > :now3, expires_at, :exp2);
```

Then:

```sql
SELECT LAST_INSERT_ID();
```

Alternative:

- Start transaction.
- Select row `FOR UPDATE`.
- Insert or update.
- Return new count.
- Commit.

Use the simpler atomic upsert only if tests prove it behaves correctly on the supported MySQL/MariaDB versions.

4. Move shared DB logic into repository.

File:

- `src/Repository/RateLimitRepository.php`

Add:

- `consume(string $key, int $windowSec): int`

Then use it from `RateLimiterMiddleware` when Redis is unavailable. This keeps SQL out of middleware and makes unit testing easier.

5. Fix cleanup without affecting active counts.

Keep expired cleanup:

```sql
DELETE FROM op_rate_limits WHERE expires_at <= :now
```

Run cleanup probabilistically or in cron, not on every request if traffic is high.

6. Add concurrency tests.

Add tests:

- `tests/Middleware/RateLimiterMiddlewareTest.php`
- `tests/Integration/RateLimiterConcurrencyTest.php`

Test cases:

- Limit 5, send 10 sequential requests, exactly 5 allowed and 5 rejected.
- Limit 5, send 10 concurrent requests, no more than 5 allowed.
- Login route fails closed if limiter backend throws.
- Non-sensitive route degrades according to current policy if backend fails.
- Headers report remaining count based on incremented value.

If PHPUnit cannot run real concurrency reliably, add a repository-level test using two DB connections and manual transaction timing.

### Acceptance criteria

- Rate-limit decision is based on incremented hit count.
- Concurrent burst cannot exceed configured limit.
- Redis and DB fallback paths both pass tests.
- Login and mobile bootstrap fail closed on limiter backend errors.

### Rollback plan

If legitimate traffic is blocked after deployment:

- Temporarily increase configured limits.
- Keep atomic logic.
- Do not return to stale pre-increment enforcement.
- Use logs to tune route-specific limits.

## 9. Production Stability And Zero Known Bug Program

The four audit findings should be fixed first, but OwnPay stability requires broader gates because payments, webhooks, ledgers, plugins, updates, and mobile SMS flows are tightly coupled.

### 9.1 Add a release-blocker checklist

Create a release checklist document, for example:

- `docs/v2/audit_fundings_codex/remediation_release_checklist.md`

Required gates:

- No CRITICAL findings open.
- No HIGH findings open.
- No MEDIUM findings open without explicit owner sign-off.
- No production gateway verifier contains simulation markers.
- No production refund path contains simulation markers unless the gateway is clearly sandbox-only.
- All migrations tested on fresh install and upgraded install.
- All static checks pass.
- All PHPUnit suites pass on disposable MySQL DB.
- Manual webhook verification tests pass for every active production gateway.
- SMS ingestion through cron completion works for each active MFS provider.
- Ledger reconciliation passes after payment and refund scenarios.

### 9.2 Add CI checks

Recommended CI commands:

```powershell
composer validate --strict
composer audit --format=json
vendor\bin\phpstan analyse --no-progress
vendor\bin\phpunit
rg -n "Webhook timing-safe validation check simulation|Dynamic refund simulation" modules\gateways -g "*.php"
```

The final `rg` command should fail CI unless matches are explicitly allowlisted as sandbox-only.

### 9.3 Add payment-flow integration tests

Add end-to-end integration tests for:

- API payment creation.
- Checkout payment initiation.
- Gateway redirect return.
- Valid webhook completion.
- Invalid webhook rejection.
- Duplicate webhook idempotency.
- Ledger balance after payment.
- Full refund.
- Partial refund.
- Duplicate refund prevention.
- Multi-currency payment and refund.
- Merchant isolation across all above cases.

### 9.4 Add SMS-flow integration tests

Add tests for:

- Device pairing.
- Mobile JWT auth.
- SMS upload.
- Decryption failure.
- Parse failure.
- Payment candidate matching.
- Amount mismatch.
- Cross-merchant isolation.
- Duplicate local SMS ID.
- Cron idempotency.
- Admin review queue.

### 9.5 Add plugin and gateway quality gates

For every gateway module:

- Manifest declares production readiness.
- Webhook verifier status is explicit.
- Refund capability status is explicit.
- Gateway has tests for capture, callback verification, refund if supported, and credential validation.
- Gateway cannot activate in production if required verification is incomplete.

Recommended manifest additions:

```json
{
  "production_ready": false,
  "webhook_verification": "sandbox_only",
  "refund_support": "sandbox_only"
}
```

Production-ready modules must set:

```json
{
  "production_ready": true,
  "webhook_verification": "implemented",
  "refund_support": "implemented"
}
```

### 9.6 Add observability

Add structured logs and dashboard counters for:

- Webhook rejected by reason.
- Webhook accepted by gateway.
- Webhook duplicate replay.
- Transaction completed by source.
- Ledger posting failures.
- SMS parsed by status.
- SMS matched/unmatched/admin review counts.
- Rate-limit rejects by route group.
- Plugin activation blocked by readiness gate.
- Migration execution status.

Use payload hashes, gateway slugs, merchant IDs, and reason codes. Do not log secrets, raw SMS bodies, card data, API keys, JWTs, or full webhook payloads.

### 9.7 Add data integrity audits

Add scheduled or admin-triggered checks:

- Ledger transactions have equal debit and credit totals.
- Every completed transaction has expected ledger entries.
- Every refund has expected ledger entries.
- No transaction is completed by an unverified webhook.
- No `accepted` SMS payment candidate remains unprocessed after migration.
- No duplicate active gateway config exists for a merchant/gateway pair.
- No custom domain maps to inactive merchant.
- No active API key belongs to inactive merchant.

## 10. Validation Commands

Run after implementation:

```powershell
composer validate --strict
composer audit --format=json
vendor\bin\phpstan analyse --no-progress
vendor\bin\phpunit
```

Run targeted scans:

```powershell
rg -n "Webhook timing-safe validation check simulation" modules\gateways -g "*.php"
rg -n "Dynamic refund simulation" modules\gateways -g "*.php"
rg -n "return true;" modules\gateways -g "*.php"
rg -n "match_status.*accepted|accepted'.*match_status" src tests database -g "*.php" -g "*.sql"
rg -n "UNIQUE KEY `uk_merchant_name`" database\schema.sql database\migrations
```

Expected final state:

- No unsafe webhook simulation markers remain in production-capable gateways.
- Refund simulation markers remain only in sandbox-only gateways, or no markers remain.
- SMS parser no longer writes auto-match candidates as `accepted`.
- Ledger unique key includes `currency`.
- Rate limiter tests prove concurrent burst enforcement.

Run manual verification on a disposable environment:

1. Fresh install from `database/schema.sql`.
2. Upgrade from previous schema through all migrations.
3. Seed two merchants.
4. Configure two currencies.
5. Configure one production-ready gateway with real webhook secret.
6. Create one payment per merchant and currency.
7. Complete one with valid webhook.
8. Attempt forged webhook on the other.
9. Upload valid SMS confirmation for an MFS/manual flow.
10. Run SMS verification cron.
11. Run full and partial refunds.
12. Compare transaction statuses, ledger entries, and balances.

## 11. Deployment Plan

Recommended deployment order:

1. Deploy schema migrations for ledger and SMS in maintenance mode.
2. Deploy source changes for SMS status alignment and ledger account handling.
3. Deploy rate limiter atomic enforcement.
4. Deploy webhook verifier helper and fail-closed gateway adapters.
5. Enable production-ready gateway verifiers one gateway at a time.
6. Keep sandbox-only gateways disabled for automated webhook completion.
7. Monitor logs and metrics.

For F-001, do not enable an affected gateway in production until that gateway has:

- Real verifier implemented.
- Valid webhook test passing.
- Invalid webhook test passing.
- Replay test passing.
- Provider credential mapping verified.

For F-003, do not deploy code before the ledger unique-key migration succeeds.

## 12. Definition Of Done

The remediation is done only when all of the following are true:

- F-001 is closed with real signature verification or production fail-closed behavior for all affected gateways.
- F-002 is closed with SMS auto-match candidates entering the verification queue and completing valid transactions exactly once.
- F-003 is closed with schema and repository behavior aligned on merchant, account name, and currency.
- F-004 is closed with atomic rate-limit consumption for Redis and DB fallback.
- All new and existing tests pass on a disposable MySQL database.
- Composer audit reports no advisories.
- PHPStan reports no errors.
- Static scans show no unsafe production simulation markers.
- Fresh install and upgrade migration paths both pass.
- Release checklist has owner sign-off from payments, security, database, and QA.

## 13. Suggested Work Breakdown

Sprint 1:

- Add failing tests for all four findings.
- Implement F-003 ledger migration and repository hardening.
- Implement F-004 atomic rate limiter.

Sprint 2:

- Implement F-002 SMS status alignment, migration, cron locking, and dashboard updates.
- Add full SMS integration tests.

Sprint 3:

- Implement F-001 shared verifier helper.
- Convert the highest-priority active gateways first.
- Add activation gates for incomplete gateways.

Sprint 4:

- Convert remaining affected gateway adapters or mark them sandbox-only.
- Add release checklist, CI scans, observability counters, and documentation.
- Run fresh-install, upgrade, and full regression tests.

## 14. Developer Handoff Notes

- Treat F-001 as the first production blocker. Do not ship automated webhook completion while any active production gateway has simulated verification.
- Treat F-003 as a migration blocker. The ledger schema must be fixed before multi-currency production use.
- Keep API response status and database SMS match status separate. The phone upload can be accepted while the database row remains pending for matching.
- Preserve fail-closed behavior. A missing secret, missing signature, unknown gateway, unsupported verifier, malformed payload, or amount mismatch must not complete a payment.
- Keep every tenant lookup scoped by `merchant_id`.
- Do not log secrets or raw payment/SMS payloads.
- Do not mark the platform stable until the Definition Of Done is satisfied.
