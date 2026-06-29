# Task Plan: Find & Complete Incomplete Features

## Goal

Deep-dive the whole codebase to find genuinely half-built/incomplete features and complete them -
WITHOUT "completing" things the code deliberately descoped.

## Current Phase

COMPLETE - Self-service password reset built & verified. ALL follow-up findings fixed too (user: "fix
all that's you finds"): dead WebhookRetryJob removed, tests/Feature suite wired in, flaky LanguageSystemTest
hardened. Final: PHPStan L9 clean, twig clean, 581 PHPUnit pass (3 consecutive green runs). ARCHITECTURE.md §5.6.

## DESIGN (self-service password reset)

- Token store: NEW table op_password_resets (user_id, token_hash CHAR(64)=sha256(token), expires_at,
  used_at, created_at, FK→op_merchant_users ON DELETE CASCADE). Migration 015 + schema.sql + apply to
  ownpay + ownpay_test. NEW PasswordResetRepository (createToken/findValidByHash/markUsed/invalidateForUser).
- NEW PasswordResetService(MerchantUserRepository, PasswordResetRepository, CommunicationService,
  FragmentRenderer, DomainUrlService, Logger):
  - requestReset(email): find ACTIVE user (findActiveByEmail); if found → random_bytes(32)→hex token,
    store sha256 hash + 1h expiry (invalidate prior), email password_reset.twig (reset_url) via
    sendEmail. ALWAYS silent (no user enumeration). Wrapped so email failure can't 500.
  - resetPassword(token,newPw): sha256→findValidByHash (unused + not expired); set password via
    Authenticator::hashPassword; markUsed; invalidate other tokens for user. Returns {success,error?}.
- AuthController: rewire forgotSubmit→requestReset; NEW resetForm(GET /reset-password?token) +
  resetSubmit(POST). Keep constant "if it exists, we sent a link" message.
- Routes (web-auth): GET+POST /reset-password. RateLimiter: add /reset-password to the sensitive set.
- Template: NEW page/reset.twig (token hidden + password + confirm + csrf). forgot.twig kept.
- Security: hashed single-use token, 1h expiry, no enumeration, rate-limited, CSRF, Argon2id, min-length
  policy (mirror staff create), invalidate token on use. TDD: service tests first.

## Phases

### Phase 1: Deep discovery - COMPLETE

- [x] Swept communication (email/SMS/Telegram), auth/2FA, password reset, disputes, webhooks
      (inbound/outbound/retry/replay), reports/export, cron jobs, settings.
- [x] Distinguished "incomplete" from "intentional design". Documented in findings.md.
- **Finding:** codebase is MATURE - no genuinely incomplete feature found. Everything checked is
  fully implemented. See findings.md "CONCLUSION".
- **Status:** complete

### Phase 2: Resolve the one real candidate + cleanup - PENDING USER DECISION

- [ ] Password reset: code DELIBERATELY uses single-owner "contact admin" (AuthController L271/297).
      DECISION: build real self-service token email reset (overrides that design), OR keep manual.
- [ ] Dead code (standing "clean dead code" approval): delete src/Cron/WebhookRetryJob.php (dead
      duplicate of registered WebhookRetryCron) + fix stale docs/github/docs/FEATURES.md refs;
      delete templates/email/password_reset.twig IF keeping manual reset.
- [ ] (optional) remove dangling auth.forgot_password doAction or leave as extension hook.
- **Status:** pending

### Phase 3: Implement chosen path + verify (PHPStan L9, twig, PHPUnit) - pending

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| Don't auto-build self-service password reset | Code explicitly descoped it (single-owner). Building it contradicts a documented design → confirm with user first. |
| Report "no incomplete features" honestly | Deep-dive found a mature codebase; manufacturing work or overriding design decisions would be wrong. |

## Errors Encountered

| Error | Resolution |
|-------|------------|
