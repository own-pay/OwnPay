# Progress Log

## Session: 2026-06-22

### Phase 1 - Deep discovery - COMPLETE

- Swept communication (email/SMS/Telegram), auth/2FA, password reset, disputes, webhooks
  (inbound/outbound/retry/replay), reports/export, cron jobs, settings. See findings.md.
- Conclusion: codebase is MATURE - no genuinely half-built feature. The only candidate was
  self-service password reset (intentionally descoped → single-owner "contact admin"). Plus 2
  dead-code items (WebhookRetryJob dup; password_reset.twig orphan) + a dangling auth.forgot_password hook.
- USER DECISION: build self-service password reset (overrides the single-owner design).

### Phase 2 - Build self-service password reset - IMPLEMENTED (verifying)

- DB: migration 015_create_password_resets.sql (hashed single-use token, expiry, FK→op_merchant_users
  ON DELETE CASCADE). schema.sql updated. Applied to ownpay + ownpay_test (column diff verified).
- NEW src/Repository/PasswordResetRepository.php (createToken[1h TTL]/findValidByHash[unused+unexpired]/
  markUsed/invalidateForUser; DB-clock comparisons).
- NEW src/Service/Auth/PasswordResetService.php: requestReset (active user → 256-bit token, store
  sha256 hash, email password_reset.twig via CommunicationService; SILENT on unknown email = no
  enumeration; wrapped so mail failure can't 500); resetPassword (validate token + min-8 + match →
  Authenticator::hashPassword → markUsed → invalidate others); tokenIsValid. Return type made
  array{success,error:string} (error '' on success) so the controller reads it PHPStan-cleanly.
- services.php: registered PasswordResetService (explicit factory).
- AuthController: forgotSubmit now calls requestReset (constant "if it exists, link sent" message);
  NEW resetForm(GET /reset-password?token) + resetSubmit(POST) + passwordReset() container helper.
  Removed the stale "single-owner manual reset" comment.
- Routes: GET+POST /reset-password (web-auth). RateLimiter: /reset-password added to BOTH sensitive sets.
- Template: NEW templates/page/reset.twig (token hidden + password + confirm + csrf; invalid-link state).
  password_reset.twig is now LIVE (rendered by the service) - no longer an orphan.
- NOTE: password reset email reuses the 2e CommunicationService pipeline (brand-aware From + reset URL
  via DomainUrlService::resolveBaseUrl).

### Test Results

| Test | Expected | Actual | Status |
|------|----------|--------|--------|
| PasswordResetServiceTest | new green | 6 tests, 49 assertions | PASS |
| PHPStan L9 (full) | clean | No errors | PASS |
| twig-cs-fixer (reset templates) | clean | 0 errors | PASS |
| reset.twig render (strict_variables, both states) | no 500, markers present | form+invalid OK | PASS |
| PHPUnit (full) | 570 + 6 | 576 pass, 0 fail (4 pre-existing notices) | PASS |

### NOTE: transient test flake (NOT from this work)

First full-suite run showed 1 failure in LanguageSystemTest::testAutomaticLanguageFileRecovery (line 193:
`assertFileDoesNotExist(storage/languages/en.json)`). Root cause: the test's `@unlink` is @-suppressed, so a
Windows file lock under full-suite load can leave en.json and fail the next assertion. It PASSES in isolation
and PASSED on full-suite re-run → transient pre-existing isolation issue, unrelated to password reset.
Candidate hardening (out of scope): LanguageSystemTest should assert the unlink succeeded / retry.

### Errors

| Error | Resolution |
|-------|------------|
| PHPStan nullCoalesce.offset / offsetAccess on resetPassword result | Made return type array{success,error:string} (error always present, '' on success); controller reads $result['error'] directly. |
| PasswordResetServiceTest all skipped | Test DB has no op_roles/op_merchant_users → fixture now creates a throwaway role+user (FK), cleans up in reverse. |

### Follow-up fixes (2026-06-22) - user: "fix all that's you finds" - DONE

- DEAD CODE: deleted src/Cron/WebhookRetryJob.php (dead duplicate of the registered WebhookRetryCron);
  updated docs/github/docs/FEATURES.md (2 refs WebhookRetryJob → WebhookRetryCron). PHPStan clean (no callers).
- TEST SUITE GAP: added a <testsuite name="Feature">tests/Feature</testsuite> to phpunit.xml, so the
  previously-orphaned PlatformMaintenanceTest (5 tests) now runs in the default suite. Verified green in-suite
  (its destructive op_audit_logs delete does not break other tests).
- FLAKY TEST: hardened LanguageSystemTest with a retrying removeFile() helper (clearstatcache + up to 10
  short retries) replacing the two @-suppressed unlink+assert spots → deterministic.
- VERIFIED: PHPStan L9 clean; full PHPUnit 581 pass across 3 consecutive runs (570 base + 6 password-reset
  - 5 Feature/PlatformMaintenance now counted). Both background chips dismissed (fixed inline).
