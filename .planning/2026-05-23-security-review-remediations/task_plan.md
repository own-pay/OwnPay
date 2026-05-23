# Task Plan: security-review-remediations

## Goal
Implement security hardening fixes for IPv6 SSRF bypass, cross-origin header leakage, and callback-based plugin sandbox bypasses, and verify them via PHPUnit tests.

## Current Phase
Phase 4: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Analyze codebase security flaws from code review
- [x] Verify existing test status
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Implementation
- [x] Modify `src/Service/System/HttpClient.php` to enforce IPv4 resolution and sanitize redirect headers
- [x] Modify `src/Plugin/PluginSandbox.php` to expand the dangerous function list with additional callback-accepting functions
- [x] Implement regression and integration tests in `tests/Security/SecurityRemediationTest.php`
- **Status:** complete

### Phase 3: Testing & Verification
- [x] Execute `php vendor/bin/phpunit tests/Security/SecurityRemediationTest.php`
- [x] Document test results in progress.md
- **Status:** complete

### Phase 4: Delivery
- [x] Review outputs and documentation
- [/] Complete task and present to user
- **Status:** in_progress

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Force `CURL_IPRESOLVE_V4` | Webhook URL validation (`isValidWebhookUrl`) is IPv4-focused; preventing IPv6 avoids hostname resolution bypasses. |
| Strip sensitive headers on redirect | Avoids leakage of `Authorization` or custom credential headers when redirects point to third-party domains. |
| Expand blocked callbacks | Prevents plugin escaping the sandbox via PHP's less common array/regex callback functions. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
