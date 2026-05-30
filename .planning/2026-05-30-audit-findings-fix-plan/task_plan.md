# Task Plan - OwnPay Pre-Release Audit Remediation

## Goal
Implement secure coding fixes for high, medium, and low security/correctness findings reported in `docs/v2/audit_findings/codex_audit.md` (skipping OP-AUD-007 and OP-AUD-013).

## Current Phase
Phase 2

## Phases

### Phase 1: Requirements & Discovery
- [x] Research all 16 reported audit findings in the codebase.
- [x] Check security configurations, routing groups, matching jobs, repositories, and JS assets.
- [x] Formulate a comprehensive implementation plan.
- **Status:** complete

### Phase 2: Core Authentication, Middleware & Webhooks
- [x] Implement `AdminBearerAuthMiddleware` for `/api/admin/v1/*` routes.
- [x] Move `/api/admin/v1/*` routes to `'admin-api'` middleware group.
- [x] Update `ApiKeyService::generate()` to support custom scopes.
- [x] Remove `RequestSignatureMiddleware` from `'webhook'` group.
- [x] Modify `GatewayDefaults::verifyWebhook()` to return false by default.
- [x] Update Stripe and Razorpay webhook verifiers to fail closed when secrets are empty.
- **Status:** complete

### Phase 3: Data Scoping, Security Hardening & Safe Backups
- [x] Remove `forAllTenants()` cross-tenant lookup fallbacks from `SmsVerificationJob`.
- [x] Validate `AUDIT_HMAC_SECRET` length (>= 32 chars) in `AuditLogRepository`.
- [x] Implement path traversal validation for ZIP restore in `BackupService`.
- **Status:** complete

### Phase 4: Stored XSS, Autoloading, Telemetry & Environment Ignores
- [x] Escape variables in `sms-center.js` and `sms-template-edit.js` using a custom `escapeHtml` helper.
- [x] Update `PluginManifest` and `Router` to load manifest routes.
- [x] Bind booted plugins into the DI Container in `PluginLoader`.
- [x] Change `/csp-report-api` to `'api-public'` group.
- [x] Add `'cron'` middleware group and support header secrets in `CronController`.
- [x] Ignore dev directories in `eslint.config.js` and fix mock stub notices in `FinancialLeakageAuditTest`.
- **Status:** complete

### Phase 5: Verification & Quality Check
- [x] Run all static analysis checks (`vendor/bin/phpstan analyse`).
- [x] Run PHPUnit tests and JSON/CSS/Twig lints to verify all checks pass.
- [x] Create walkthrough documenting changes and results.
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Skip OP-AUD-007 and OP-AUD-013 | Explicitly requested by the user. |
| Use `createStub` instead of `createMock` | Resolves PHPUnit stub notices without expecting call counts. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
