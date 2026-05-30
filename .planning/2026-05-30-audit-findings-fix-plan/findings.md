# Findings & Decisions

## Requirements
- Review and cross-check the audit findings from `docs/v2/audit_findings/codex_audit.md`.
- Formulate a concrete, actionable implementation plan (`implementation_plan.md`) to resolve them.
- Skip implementing fixes for `OP-AUD-007` and `OP-AUD-013` (report only).
- Do not use the `ask_question` tool for questions during planning, but put open questions in the implementation plan.

## Research Findings

### OP-AUD-001 - Admin API Authorization Uses Merchant API Keys
- `/api/admin/v1/*` endpoints are under the `'api'` group which uses `BearerAuthMiddleware`.
- `BearerAuthMiddleware` does not verify if the key is associated with an administrator or check admin roles.
- Fix: Introduce a new middleware `AdminBearerAuthMiddleware` or update routes to require admin roles. We will define an admin bearer auth verification flow, checking if the API key belongs to an admin or a user with role `superadmin` / admin capabilities.

### OP-AUD-002 - Webhook Middleware Blocks Native Provider Signatures
- `RequestSignatureMiddleware` intercepts `/webhook/{gateway}` requests and verifies a generic OwnPay signature.
- This blocks external payment gateway webhooks (e.g. Stripe, Razorpay) which send their own native signatures.
- Fix: Remove `RequestSignatureMiddleware` from the general `webhook` middleware stack, and delegate signature verification entirely to `UnifiedWebhookController` which calls `verifyWebhookSignature()` on the gateway adapter.

### OP-AUD-003 - Gateway Webhook Verification Fails Open
- `GatewayDefaults::verifyWebhook` returns `true` by default.
- `StripeGateway` and `RazorpayGateway` also return `true` if `webhook_secret` is not configured.
- Fix: Change `GatewayDefaults::verifyWebhook` to return `false` by default. Update all gateway adapters (including Stripe and Razorpay) to fail closed (return `false`) when `webhook_secret` is empty or missing.

### OP-AUD-004 - SMS Verification Crosses Tenant Boundaries and Rewrites Ownership
- `SmsVerificationJob.php` uses `forAllTenants()->findByTrxId()` and `forAllTenants()->findPendingMatchGlobal()` as fallback.
- It updates the `merchant_id` of parsed SMS entries to the matched transaction's merchant ID.
- Fix: Remove the `forAllTenants()` fallback logic and matching block entirely. SMS matching must be strictly scoped within the merchant/device context that received the SMS.

### OP-AUD-005 - Audit Log HMAC Uses a Hardcoded Fallback Secret
- `AuditLogRepository::calculateSignature` falls back to `'default_audit_hmac_secret_key_2026'` if `AUDIT_HMAC_SECRET` is empty.
- Fix: Throw a `RuntimeException` if `AUDIT_HMAC_SECRET` is not set or is insecure (e.g. empty or shorter than 32 characters), similar to `JwtService`.

### OP-AUD-006 - Backup Restore Extracts ZIP Without Path Validation
- `BackupService::restore` calls `$zip->extractTo(dirname(__DIR__, 2))` directly.
- Fix: Add entry path validation matching `UpdateService::extractPackage` to check all files in `code.zip` for directory traversal (`..`) or absolute paths before extracting.

### OP-AUD-007 - Standalone Public API Tester Ships in Web Root (SKIP/REPORT ONLY)
- Standalone `public/api-tester.php` bypasses front controller.
- Propose: To be deleted or moved out of the public folder, but will skip execution per user request.

### OP-AUD-008 - Admin SMS Regex Tester Injects User-Controlled Values With `innerHTML`
- `sms-center.js` and `sms-template-edit.js` use `innerHTML` to display match results from `/admin/sms-center/test-regex`.
- Fix: Escape dynamic values returned by the API before appending them to HTML strings, or construct elements safely using `textContent`.

### OP-AUD-009 - Plugin Manifest Routes Are Not Automatically Registered
- Manifest routes are ignored; `Router` only registers routes from route files and `system.routes.register` hook.
- Fix: Update `PluginManifest` to parse and expose `routes` metadata. Update `Router::loadRoutes` to read loaded plugins from `PluginRegistry` and register routes automatically. Register booted plugin singletons in the Container.

### OP-AUD-010 - CSP Report Endpoint Is Behind Bearer API Authentication
- `/csp-report-api` is under `'api'` middleware group (requires bearer auth).
- Fix: Move `/csp-report-api` to `'api-public'` group in `config/routes/api.php` so browser reports can be received without authentication.

### OP-AUD-011 - BrandContext Falls Back to the First Merchant
- `BrandContext::resolveFromRequest` falls back to the first merchant in database if unresolved.
- Fix: Remove this system fallback from `resolveFromRequest()` to ensure it returns `null` if unresolved. Add a distinct helper `getSystemDefaultBrandId()` for safe public landing defaults.

### OP-AUD-012 - Cron Trigger Uses URL Secret Without Route Rate Limiting
- `/cron/{secret}` is in the `global` middleware group (no rate limiting).
- Fix: Define a `'cron'` middleware group in `config/middleware.php` containing `RateLimiterMiddleware`. Move `/cron/{secret}` to the `'cron'` group. Add support for secure header-based secret checking (e.g., `X-Cron-Secret` or `Authorization: Bearer`).

### OP-AUD-013 - Release Package Contains Development and Runtime Artifacts (SKIP/REPORT ONLY)
- Untracked artifacts and developer keys are present in the workspace.
- Propose: Add a release checklist / build script to ignore these, but will skip execution per user request.

### OP-AUD-014 - JSON Lint Fails on Local Tool Artifacts
- JSON lint script `eslint "**/*.json" --ext .json` scans all JSON files, crashing on untracked temporary files.
- Fix: Update `eslint.config.js` to add `.antigravitycli/**/*`, `.planning/**/*`, and `.phpunit.cache/**/*` to ignores list.

### OP-AUD-015 - PHPUnit Suite Passes With Notices
- Mocks stubbed in `FinancialLeakageAuditTest.php` setup emit notices.
- Fix: Use `createStub()` instead of `createMock()` for stubs that do not verify behavioral expectations.

### OP-AUD-016 - i18n Is a Placeholder
- Twig global `lang` is set to an empty array `[]` in `config/services.php`.
- Fix: Document that i18n is currently a placeholder for future translation extensions, or load localized messages if a translation file is present.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [codex_audit.md](file:///c:/laragon/www/ownpay/docs/v2/audit_findings/codex_audit.md)
