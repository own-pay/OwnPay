# Task Plan - Deep Level 3 Security and Logic Audit

## Goal
Conduct a highly advanced Level 3 security and logic audit of the OwnPay platform, identify hidden vulnerabilities not visible to normal scans, and implement robust mitigations for WebhookDispatcher SSRF and UnifiedWebhookController payload exceptions.

## Current Phase
Phase 5: Final Attestation & Walkthrough - COMPLETE

## Phases

### Phase 1: Requirements & Discovery
- [x] Analyze codebase entry points and outbound request mechanisms
- [x] Discover hidden vulnerabilities (FIND-006 & FIND-007)
- [x] Document in findings.md
- **Status:** complete

### Phase 2: WebhookDispatcher SSRF Hardening
- [x] Integrate `UrlValidator::isValidWebhookUrl()` in `WebhookDispatcher::doSend()`
- [x] Add SSRF bypass guards in `WebhookDispatcher::sendWithRetry()`
- **Status:** complete

### Phase 3: UnifiedWebhookController Payload Exception Hardening
- [x] Add strict `is_scalar()` checks on extracted parameters in `resolveMerchantFromPayload()`
- **Status:** complete

### Phase 3b: Unmapped Admin Routes Default-Deny Hardening
- [x] Refactor `PermissionMiddleware::resolvePermission()` to prevent prefix matching bypass
- [x] Add robust unit tests inside `SecurityRemediationTest.php` to verify default-deny states
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run full PHPUnit test suite to ensure no regressions
- [x] Verify PHPStan Level 9 strict compliance
- [x] Run stylelint, eslint, and twig-cs-fixer validations
- **Status:** complete

### Phase 5: Final Attestation & Walkthrough
- [x] Document all resolved items in findings_report.md
- [x] Attest the task plan and finalize the session
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Validate `WebhookDispatcher` URLs | Enforces strict SSRF protection on outgoing notifications dispatched via `WebhookDispatcher`. |
| Enforce `is_scalar` validation in `UnifiedWebhookController` | Protects the query parameter binding from array injection attacks causing SQL or type exceptions. |
| Restrict `PermissionMiddleware` prefix matching | Excludes base '/admin' prefix and enforces strict path boundaries to preserve default-deny authorization checks. |

## Errors Encountered
| Error | Resolution |
|-------|------------|


