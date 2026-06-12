# OwnPay Final Pre-Release Audit

- Date: 2026-05-30
- Auditor: Codex
- Scope source: `docs/audit_task.txt`
- Report path: `docs/v2/audit_findings/codex_audit.md`
- Release recommendation: HOLD

## Executive Summary

OwnPay is not ready for a public pre-release without remediation. The codebase has many strong controls: strict PHP types, PDO prepared statements, a central kernel and container, tenant-aware repositories, hardened session cookies, admin RBAC middleware, domain isolation for white-label hosts, PHPStan level 9 passing, and a passing PHPUnit suite. However, the current release gate fails on several security and correctness issues that affect core payment trust boundaries.

The highest-risk findings are:

1. Admin API endpoints are protected by merchant bearer API keys, not admin session/RBAC/2FA.
2. The unified webhook pipeline both blocks native provider signatures before gateway adapters and lets many adapters fail open.
3. SMS transaction matching still crosses tenant boundaries and rewrites parsed SMS ownership.
4. Audit log HMAC signing uses a hardcoded fallback secret.
5. Backup restore extracts ZIP archives into the application root without entry validation.
6. A standalone API tester is shipped in the public web root and bypasses the front controller.

Release recommendation: HOLD until all HIGH findings are fixed and the full validation suite, including JSON lint, is clean.

## Methodology

Inputs used:

- `docs/audit_task.txt`
- `ARCHITECTURE.md`
- `AGENTS.md`
- `.agents/rules/*`, especially architecture, database, ledger, white-label, code standards, security audit, cryptography, and planning rules
- `graphify-out/graph.json` as navigation aid only
- Direct source reads across `src/`, `config/`, `database/`, `modules/`, `public/`, `templates/`, `tests/`, and `docs/`
- Automated validation commands required by the plan
- Targeted `rg` scans for TODO/FIXME/HACK, dangerous PHP functions, raw Twig output, SQL construction, hardcoded secrets, direct session/CSRF access, `forAllTenants()`, unscoped repository use, direct domain usage, and frontend injection sinks

Every finding below was verified from current code. Prior audit notes were used only as leads.

## Architecture Understanding

### Kernel and Request Lifecycle

`public/index.php` instantiates `OwnPay\Kernel` after Composer autoload. The kernel initializes environment/configuration, sets database/plugin context, boots plugins, loads middleware, applies plugin middleware filters, enforces required admin middleware, loads routes, matches requests, executes global plus route-specific middleware, and dispatches controllers through the router.

The main route files are `config/routes/web.php` and `config/routes/api.php`. Web routes cover public pages, auth, checkout, admin panels, cron, install, and unified webhooks. API routes cover merchant REST API, mobile JWT API, admin API, and CSP reporting.

### Middleware and Trust Boundaries

Global middleware includes security headers, maintenance mode, and domain resolution. Web/admin paths add session, CSRF, rate limiting, 2FA, and permission middleware. Merchant API paths use CORS, rate limiting, bearer API keys, and idempotency. Mobile API paths use JWT. Webhooks use IP allowlisting and request HMAC signature checks.

### White-Label Domain Model

`DomainMiddleware` maps custom hosts to `op_domains`, injects `merchant_id`, rejects unknown/inactive/unverified domains, and blocks `/admin` access on custom domains. This matches the single-owner, multi-brand white-label model.

### Brand and Tenant Scoping

Repository classes commonly use `TenantScope` and explicit `merchant_id` filters. `BrandContext` resolves tenant context from request attributes, session, or a fallback first merchant. The fallback is convenient but unsafe for ambiguous mutating flows.

### Data Layer and Ledger

The schema uses `op_` table prefixes, `merchant_id` scoping on tenant data, foreign keys for many core relationships, and ledger tables separated from payment transactions. `TransactionRepository`, `LedgerService`, `RefundService`, and cron jobs handle lifecycle and reconciliation.

### Plugins and Gateways

Plugins are discovered under `modules/gateways`, `modules/themes`, and `modules/addons`. `PluginLoader` validates manifests, scans plugin PHP for restricted constructs/functions, loads entrypoints, registers event owners, creates `PluginSandbox`, and auto-registers gateway adapters with `GatewayBridge`. The database wrapper applies plugin SQL sandbox checks when a plugin owns the active event context.

The webhook path is currently the most important plugin/gateway weakness: adapter verification is called, but the route-level HMAC middleware can reject provider-native signatures before adapters run, while adapter defaults and many gateway implementations still return true without a configured secret.

### Twig and Frontend

Twig is configured centrally with application globals, CSRF helpers, asset helpers, and `hook()`. Hook output is marked HTML-safe, but `TwigExtensions::hook()` strips high-risk tags and inline event handlers before returning plugin output. The frontend is plain JS/CSS under `public/assets`, with admin page modules and checkout scripts.

### Auth, Session, RBAC

Admin web routes use session, CSRF, rate limiting, two-factor, and permission middleware. `PermissionMiddleware` loads active users and role permission slugs, and bypasses only superadmins. Merchant API keys use bearer tokens with hashed storage and read/write scopes. Mobile APIs use JWT and device pairing.

### Update System

The update system has package extraction validation in `UpdateService::extractPackage()`, but rollback/backup restore uses a separate path in `BackupService::restore()` that extracts `code.zip` directly into the application root without validating entries.

## Severity Summary

| Severity | Count |
| --- | ---: |
| HIGH | 6 |
| MEDIUM | 7 |
| LOW | 3 |

## Findings

### OP-AUD-001 - HIGH - Admin API Authorization Uses Merchant API Keys

Dimension: Security, API design, architecture

Evidence:

- `config/routes/api.php:57-64` registers `/api/admin/v1/*` endpoints with middleware group `api`.
- `config/middleware.php:47-53` defines `api` as CORS, rate limiter, bearer auth, and idempotency only.
- `src/Middleware/BearerAuthMiddleware.php:106-130` checks read/write API key scopes and sets `merchant_id`; it does not enforce admin session, 2FA, superadmin, or RBAC permission checks.
- `src/Service/Customer/ApiKeyService.php:44-49` creates API keys with `["read","write"]`.
- Example mutating controllers trust only request `merchant_id`: `src/Controller/Api/Admin/SmsTemplateController.php:54-76`, `src/Controller/Api/Admin/DomainController.php:49-63`, `src/Controller/Api/Admin/DeviceController.php:62-74`.

Impact:

A merchant API key with write scope can call admin API actions for its merchant, including SMS template update, device revocation, SMS queue retry, and domain verification, without an admin login session, 2FA, or role permission.

Release risk:

Release blocker. The route comment says administrative API should be superadmin-authorized, but current middleware does not implement that boundary.

Recommendation:

Move admin API routes to an admin-specific API middleware stack or add explicit admin bearer credentials with RBAC scopes distinct from merchant payment API keys. Require permission mapping, 2FA/session or admin service token, audit logging, and tests proving merchant API keys are rejected from `/api/admin/v1/*`.

### OP-AUD-002 - HIGH - Webhook Middleware Blocks Native Provider Signatures Before Adapter Verification

Dimension: Security, plugins, business logic

Evidence:

- `config/routes/web.php:280` sends `/webhook/{gateway}` through middleware group `webhook`.
- `config/middleware.php:68-72` applies `IpAllowlistMiddleware` and `RequestSignatureMiddleware` before the controller.
- `src/Middleware/RequestSignatureMiddleware.php:45-60` accepts only `X-Signature`, `X-Hub-Signature-256`, or query `signature`.
- `src/Middleware/RequestSignatureMiddleware.php:73-93` verifies a generic OwnPay HMAC over the raw body before the gateway adapter runs.
- `src/Controller/Webhook/UnifiedWebhookController.php:85-93` then delegates to `GatewayBridge` for gateway-native verification.

Impact:

Provider-native webhooks such as Stripe, Razorpay, PayPal, Cashfree, Paystack, and many others send provider-specific headers or backchannel signatures. They can be rejected by generic middleware before adapter-specific validation executes. This can make legitimate payment completion webhooks fail in production.

Release risk:

Release blocker. Webhook reliability is payment-critical.

Recommendation:

Remove generic `RequestSignatureMiddleware` from provider inbound webhook routes or make it gateway-aware. Let adapters verify native provider signatures first. Keep OwnPay HMAC only for OwnPay-owned outbound/inbound webhook channels that actually use `X-Signature` and `X-Timestamp`.

### OP-AUD-003 - HIGH - Gateway Webhook Verification Fails Open

Dimension: Security, plugins, payment integrity

Evidence:

- `src/Gateway/GatewayDefaults.php:48-59` implements default webhook verification as always true.
- `modules/gateways/stripe/StripeGateway.php:274-277` returns true when `webhook_secret` is empty.
- `modules/gateways/razorpay/RazorpayGateway.php:125-126` returns true when `webhook_secret` is empty.
- Targeted scans show many gateway adapters return true directly or accept missing signatures/secrets.

Impact:

If the route-level generic HMAC is removed or misconfigured, multiple gateway webhooks can authenticate by default. If it stays enabled, many real provider webhooks break as described in OP-AUD-002. The current design is caught between fail-closed incompatibility and fail-open adapter behavior.

Release risk:

Release blocker. Payment status changes must not depend on missing-secret "backward compatibility."

Recommendation:

Make `GatewayAdapterInterface::verifyWebhook()` fail closed by default. Require each gateway to declare one of: native signature verification, verified backchannel lookup, explicit no-webhook support, or disabled webhook processing. Treat empty required webhook secrets as configuration errors for gateways that rely on them.

### OP-AUD-004 - HIGH - SMS Verification Crosses Tenant Boundaries and Rewrites Ownership

Dimension: Database/data layer, business logic, tenant isolation

Evidence:

- `src/Cron/SmsVerificationJob.php:117-120` first searches tenant-scoped transaction ID, then falls back to `forAllTenants()->findByTrxId()`.
- `src/Cron/SmsVerificationJob.php:126-128` rewrites `op_sms_parsed.merchant_id` to the matched transaction's merchant.
- `src/Cron/SmsVerificationJob.php:140-148` performs a global amount/gateway match and rewrites ownership again.

Impact:

An SMS parsed under one brand can be matched against another brand's pending transaction. The code then changes parsed SMS tenant ownership, effectively crossing the `merchant_id` boundary during automated payment completion. Amount-based matching increases collision risk.

Release risk:

Release blocker. This violates the project rule that tenant data must not be retrieved without active `merchant_id` context.

Recommendation:

Remove global fallback matching. Match only within the device/merchant context that produced the SMS. If reassignment is required for operational recovery, make it an audited superadmin manual workflow with explicit source and destination merchant IDs.

### OP-AUD-005 - HIGH - Audit Log HMAC Uses a Hardcoded Fallback Secret

Dimension: Security, cryptography, audit integrity

Evidence:

- `src/Repository/AuditLogRepository.php:93-96` uses `AUDIT_HMAC_SECRET`, but falls back to `default_audit_hmac_secret_key_2026`.

Impact:

Audit signatures are predictable whenever the environment secret is missing. Anyone with database write access can forge valid audit log signatures under the known fallback key, undermining tamper evidence.

Release risk:

Release blocker for fintech audit readiness.

Recommendation:

Fail closed if `AUDIT_HMAC_SECRET` is missing or too short, similar to the JWT secret check in the kernel. Add installer generation and a health check. Add tests proving audit logging fails safely without a configured secret.

### OP-AUD-006 - HIGH - Backup Restore Extracts ZIP Without Path Validation

Dimension: Update system, supply-chain security, operations

Evidence:

- `src/Update/BackupService.php:97-106` opens `code.zip` and calls `extractTo(dirname(__DIR__, 2))` directly.
- `src/Update/UpdateService.php:478-487` shows the safer pattern: enumerate entries, reject `..` and absolute paths, then extract.
- `src/Update/UpdateService.php:351-355` calls backup restore during rollback after update failure.

Impact:

A malicious or corrupted backup archive can write unexpected paths during rollback. Depending on ZIP entry behavior and platform handling, this can overwrite application files or place files outside intended directories.

Release risk:

Release blocker. Rollback is a privileged operation in a payment system.

Recommendation:

Apply the same entry validation used by `UpdateService::extractPackage()` to `BackupService::restore()`. Reject traversal, absolute paths, symlinks if present, empty names, oversized entries, and writes outside the application root after realpath normalization.

### OP-AUD-007 - MEDIUM - Standalone Public API Tester Ships in Web Root

Dimension: Open-source readiness, security, frontend

Evidence:

- `public/api-tester.php:1-3` is a standalone PHP page.
- `public/api-tester.php:122-126` renders host and token inputs for bearer API keys/JWTs.
- `public/.htaccess:9-14` serves existing files directly instead of sending them through `index.php`.
- `public/index.php:5-9` still states it is the only PHP file in `public/`, which is false.

Impact:

The API tester bypasses application middleware, CSP/security headers from the kernel, route auth, and normal deployment assumptions. It also asks users to paste bearer tokens into a browser page that loads CDN resources.

Release risk:

Release blocker for public packaging unless intentionally documented and gated.

Recommendation:

Move the tester out of `public/`, put it behind admin auth, or ship it only under a dev-only build flag. Keep the public web root front-controller-only.

### OP-AUD-008 - MEDIUM - Admin SMS Regex Tester Injects User-Controlled Values With `innerHTML`

Dimension: Frontend, security, UX

Evidence:

- `src/Controller/Admin/SmsTemplateAdminController.php:293-320` returns `field`, `match`, and `full` values derived from admin-submitted SMS body and regex.
- `public/assets/js/pages/sms-center.js:80-82` writes those values into `box.innerHTML`.
- `public/assets/js/pages/sms-center.js:141-148` builds HTML rows with unescaped extracted values.
- `public/assets/js/pages/sms-template-edit.js:38-45` appends regex and match data into HTML.
- `public/assets/js/op-fetch.js:94-99` and `public/assets/js/admin.js:306-307` also expose broad fragment injection helpers.

Impact:

An admin or compromised staff account can inject HTML/script-like payloads into the admin interface through test SMS content or regex output. Even if limited to authenticated admin flows, this can become stored or reflected admin XSS if templates/sample data are reused.

Release risk:

Conditional blocker. It should be fixed before public release because this area processes user-provided SMS text and regexes.

Recommendation:

Use `textContent`, DOM node construction, or a vetted sanitizer for dynamic values. Reserve `innerHTML` only for trusted server-rendered fragments with explicit endpoint allowlists.

### OP-AUD-009 - MEDIUM - Plugin Manifest Routes Are Not Automatically Registered

Dimension: Plugins, incomplete functionality

Evidence:

- `src/Http/Router.php:206-210` only fires `system.routes.register` for plugins to register routes manually.
- `modules/addons/telegram-bot/manifest.json` declares route `POST /plugins/telegram-bot/webhook`.
- `modules/addons/telegram-bot/Plugin.php:40-44` registers transaction events only, not `system.routes.register`.
- Targeted scan found no module subscribing to `system.routes.register`.

Impact:

Manifest-declared plugin routes are inert unless each plugin also registers the route manually. The bundled Telegram Bot webhook handler is not reachable through its declared manifest route, making the addon incomplete.

Release risk:

Conditional blocker for plugin ecosystem claims.

Recommendation:

Either implement manifest route registration in `PluginLoader`/`Router`, or remove route declarations from manifests and require explicit `system.routes.register` hooks. Add an integration test that the Telegram Bot webhook route resolves when the plugin is active.

### OP-AUD-010 - MEDIUM - CSP Report Endpoint Is Behind Bearer API Authentication

Dimension: Security monitoring, API design, incomplete functionality

Evidence:

- `config/routes/api.php:36-37` registers `/csp-report-api` with middleware group `api`.
- `config/middleware.php:47-53` makes `api` require `BearerAuthMiddleware`.
- Browser CSP reports do not include OwnPay merchant bearer tokens.

Impact:

Real browser CSP violation reports will be rejected as unauthenticated. Security telemetry appears present but will not work for normal browsers.

Release risk:

Conditional blocker for security monitoring readiness.

Recommendation:

Move CSP reporting to a public, rate-limited, body-size-limited middleware group. Do not require merchant bearer auth. Keep strict JSON parsing, log sanitization, and abuse rate limiting.

### OP-AUD-011 - MEDIUM - BrandContext Falls Back to the First Merchant

Dimension: Tenant isolation, cross-cutting concerns

Evidence:

- `src/Service/Brand/BrandContext.php:57-87` resolves from request, session, then silently selects `SELECT id FROM op_merchants ORDER BY id ASC LIMIT 1`.
- Many admin controllers call `resolveFromRequest()` and then use `getActiveBrandId()` for mutating actions.

Impact:

If an authenticated admin session lacks `active_brand_id` or `auth_merchant_id`, controllers can operate on the first merchant rather than failing closed. This is especially risky for staff accounts, recovered sessions, and edge-case admin actions.

Release risk:

Conditional blocker for tenant isolation hardening.

Recommendation:

Remove the implicit first-merchant fallback from mutating/admin flows. Require explicit brand context or global superadmin mode. Keep a separate read-only system default helper if needed for public landing/theme defaults.

### OP-AUD-012 - MEDIUM - Cron Trigger Uses URL Secret Without Route Rate Limiting

Dimension: Security, operations

Evidence:

- `config/routes/web.php:276-277` exposes `GET /cron/{secret}` using route group `global`.
- `src/Kernel.php` merges global middleware with route group middleware; group `global` adds only the global stack, not rate limiting.
- `src/Controller/Page/CronController.php:42-62` compares a URL path secret against env/db/config.

Impact:

Secrets in URLs commonly leak through logs, browser history, reverse proxies, and referrers. The route is also not under the rate limiter, so brute-force attempts are limited only by network/application capacity.

Release risk:

Conditional blocker for production operations.

Recommendation:

Use POST with an Authorization header or signed timestamped request, place the route under rate limiting, and avoid placing long-lived secrets in the path. Consider CLI/system scheduler execution instead of public HTTP.

### OP-AUD-013 - MEDIUM - Release Package Contains Development and Runtime Artifacts

Dimension: Open-source readiness, supply-chain hygiene

Evidence:

- `git ls-files` shows tracked `scratch/*`, `docs/temp/*`, `storage/phpstan7.txt`, `storage/phpstan8.txt`, and uploaded favicon assets under `public/assets/uploads/`.
- `update_private_key.pem` exists in the repository root, even though `.gitignore:53` ignores it and it is not tracked.
- `README.md:51-52` documents live demo default credentials.
- `AGENTS.md:8-10` contains local developer admin credentials.

Impact:

The public repository is noisy and includes maintenance scripts, audit scratch files, generated reports, uploaded runtime assets, and local/developer operational material. Even if the private key is untracked, its presence in the working tree raises release hygiene risk.

Release risk:

Conditional blocker for open-source release.

Recommendation:

Create a release manifest and remove or archive scratch/temp/generated/runtime files. Keep `update_private_key.pem` outside the repo root. Ensure docs distinguish live demo credentials from local development credentials and never ship operational secrets.

### OP-AUD-014 - LOW - JSON Lint Fails on Local Tool Artifacts

Dimension: Code quality, release validation

Evidence:

- `package.json:8-11` runs `eslint "**/*.json" --ext .json` for JSON lint.
- `.antigravitycli/df5facbb-5d32-4966-9612-544bd9cb39c2.json` exists as a zero-length link-like file in the workspace.
- `npm run lint:json` exits 1 with ENOENT opening that file.

Impact:

The required validation suite is not clean. Even if `.antigravitycli/` is ignored by git, the lint command scans the workspace rather than the release file set.

Release risk:

Not a direct runtime blocker, but a release-gate blocker because the requested validation command fails.

Recommendation:

Scope JSON lint to tracked/release JSON files or configure ESLint ignores for local tool directories. Ensure `npm run lint:json` passes from a clean checkout and a normal developer workspace.

### OP-AUD-015 - LOW - PHPUnit Suite Passes With Notices

Dimension: Code quality, tests

Evidence:

- `vendor/bin/phpunit` passes 454 tests and 1459 assertions but emits 3 PHPUnit notices.
- Notices are in `tests/Integration/FinancialLeakageAuditTest.php` at lines 147, 177, and 199 for mock objects configured without expectations.

Impact:

The test suite is green, but release output is not clean. Notices can hide future test-quality regressions.

Release risk:

Not a runtime blocker.

Recommendation:

Fix the mocks or test configuration so PHPUnit runs cleanly with notices enabled.

### OP-AUD-016 - LOW - i18n Is a Placeholder

Dimension: UX flows, cross-cutting concerns

Evidence:

- `config/services.php:224-226` adds Twig global `lang` as an empty array with comment `i18n placeholder`.

Impact:

The UI does not appear release-ready for localization despite a global placeholder. This may be acceptable for a first English-only release, but it should not be represented as completed i18n support.

Release risk:

Not a blocker if i18n is explicitly out of scope.

Recommendation:

Document i18n as future work or implement a real translation catalog/locale resolver.

## Incomplete Functionality Register

| Area | Evidence | Status |
| --- | --- | --- |
| Admin API authorization | `/api/admin/v1/*` uses `api` middleware only | Incomplete security boundary |
| Provider webhooks | Generic HMAC middleware conflicts with native gateway signatures | Incomplete production webhook design |
| Gateway adapter verification | Default and empty-secret paths return true | Incomplete adapter security contract |
| SMS companion matching | Global fallback matching rewrites tenant ownership | Incomplete tenant isolation |
| Plugin route manifests | Telegram route declared in manifest but no auto-registration | Incomplete plugin routing feature |
| CSP reporting | CSP report route requires bearer API auth | Incomplete browser telemetry |
| Public API tester | Standalone dev tool in public web root | Release packaging gap |
| JSON lint | Required lint command fails on local tool artifact | Release validation gap |
| i18n | Empty Twig `lang` placeholder | Incomplete localization |

## Automated Validation Summary

| Command | Result | Notes |
| --- | --- | --- |
| `composer audit --format=json` | PASS | `advisories: []`, `abandoned: []` |
| `vendor/bin/phpstan analyse` | PASS | PHPStan level 9, 361 files, no errors |
| `vendor/bin/phpunit --colors=never --display-phpunit-notices --display-notices --display-warnings --display-deprecations` | PASS_WITH_NOTICES | 454 tests, 1459 assertions, 3 PHPUnit notices |
| `composer lint:twig` | PASS | 78 Twig files, 0 notices/warnings/errors |
| `npm run lint:js` | PASS | ESLint exited 0 |
| `npm run lint:css` | PASS | Stylelint exited 0 |
| `npm run lint:json` | FAIL | ESLint ENOENT on `.antigravitycli/df5facbb-5d32-4966-9612-544bd9cb39c2.json` |

## PHPStan Output Summary

PHPStan completed successfully at level 9 with `phpstan.neon`, analyzing `cli`, `config`, `modules`, and `src`. No PHPStan errors were reported.

Residual risk: PHPStan does not prove authorization, tenant isolation, webhook authenticity, or business-flow correctness. The HIGH findings above are logic and architecture issues that static typing does not catch.

## Targeted Scan Summary

| Scan | Result |
| --- | --- |
| TODO/FIXME/HACK/placeholders | Found mostly docs/temp and expected placeholders; notable `lang` i18n placeholder and update sample manifest placeholder hashes |
| Dangerous PHP functions | Core update/backup uses `exec` and ZIP extraction; installer uses validated dynamic DDL; cache uses `unserialize(..., allowed_classes=false)` |
| Raw Twig output | Hook output and plugin settings HTML use raw rendering; current `TwigExtensions::hook()` sanitizes high-risk tags/events before return |
| SQL concatenation | Broad scan found mostly parameterized SQL; dynamic table/column SQL exists in repository helpers and installer paths with validation |
| Hardcoded secrets | Audit HMAC fallback secret found; README/AGENTS demo/dev credentials found; untracked private update key exists in repo root |
| Direct CSRF session access | CSRF token session access appears centralized in Twig/services/middleware |
| `forAllTenants()` | High-risk unresolved use in `SmsVerificationJob` |
| Direct domain/APP_URL usage | Main custom domain handling is centralized; public API tester hardcodes `https://ownpay.test` |
| Frontend injection sinks | Multiple `innerHTML` and `insertAdjacentHTML` sinks; SMS admin tester has user-controlled values |

## Positive Controls Verified

- Composer dependency audit is clean.
- PHPStan level 9 is clean.
- PHPUnit passes.
- Twig, JS, and CSS lint pass.
- Admin web routes include session, CSRF, rate limiting, 2FA, and RBAC.
- `DomainMiddleware` blocks `/admin` on custom domains.
- `GatewayBridge` now returns false when no adapter exists, though its docblock remains stale.
- Checkout merchant/domain mismatch and callback-processing race leads were rechecked and not included as current findings.
- Plugin loader token scanning and plugin-owned SQL sandboxing are present.
- Twig hook output has defensive tag/event sanitization before being returned as safe HTML.

## Release Gate Recommendation

HOLD.

Minimum exit criteria for a conditional release:

1. Fix OP-AUD-001 through OP-AUD-006.
2. Remove or gate `public/api-tester.php`.
3. Make JSON lint pass in a normal workspace.
4. Clean release packaging of scratch/temp/runtime artifacts.
5. Add regression tests for admin API rejection, gateway webhook verification, SMS tenant isolation, audit secret fail-closed behavior, and safe backup restore extraction.

