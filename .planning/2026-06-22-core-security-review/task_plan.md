# Task Plan: In-depth core security review (gateway plugins excluded)

## Scope

Deep security review of the CORE (everything except modules/gateways/**). Focus: RCE, auth bypass,
privilege escalation, injection (SQLi/cmd/path), SSRF (host/proto), XSS, crypto, data exposure.
Find concrete exploitable bugs and FIX them. Status: COMPLETE.

## Boundaries reviewed - VERIFIED HARDENED (no findings)

- Auth: Authenticator (Argon2id m=65536/t=4, lockout+backoff, constant-time fallback, 2FA gate).
- API keys: AdminBearerAuthMiddleware - hash_equals on full-token SHA-256, status/expiry/scope/merchant-active.
- JWT (mobile): JwtService uses Firebase JWT with new Key($secret,'HS256') → alg pinned (no none/confusion).
- CSRF: CsrfMiddleware - hash_equals + token pool + rotation; HMAC stateless token hash_equals.
- Cron (public /cron/{secret}): hash_equals vs env/db/config secret; fail-closed if unset.
- Webhook→payment integrity: GatewayBridge::verifyWebhookSignature FAIL-CLOSED (no adapter → false→403);
  GatewayApiService::handleCallback STRIPS caller _op_webhook_verified, re-adds only when ingress proved
  the signature, then bridge->verify(). Forged/unsigned webhook cannot confirm a payment.
- SSRF: UrlValidator - scheme allowlist, blocks userinfo/loopback/private/169.254 (metadata);
  resolveSafeWebhookIp pins resolved public IP (DNS-rebinding/TOCTOU defense).
- File upload: FilesystemService::storeUpload - ext allowlist + finfo MIME match + SVG sanitize +
  random filename (no original-name use → no traversal/double-ext).
- Update system: UpdateService - sha256 + openssl_verify signature; extractPackage rejects ../ , leading
  '/', and '\\' (zip-slip closed). Admin-triggered + signed.
- Password reset (this session): hashed single-use tokens, 1h expiry, no enumeration in response,
  CSRF + rate-limited; reset-link host from env/DB (DomainUrlService) - NOT Host header (no poisoning).
- public/api-tester.php: fail-safe - 404 unless APP_DEBUG / APP_ENV is explicitly dev.

## FINDING (fixed) - Path Traversal in TranslationService (CWE-22)

- Issue: language `code` was interpolated directly into file paths (storage/languages/{code}.json) for
  read/write/delete with NO validation (createLanguage/uploadLanguage/saveTranslations/deleteLanguage/
  loadTranslations + setLocale). A code containing ../ would read/write/delete .json files outside the
  languages dir.
- Severity: LOW (admin-gated). Language-management routes require admin (/admin/settings/language/*); the
  user-facing profile-language path is gated by exists() so ordinary staff can only pick existing codes.
  No non-admin path to traversal. Still a real path-traversal primitive worth closing.
- Fix: added TranslationService::isValidCode() (`^[A-Za-z0-9_-]{1,35}$`); setLocale ignores unsafe codes;
  createLanguage/uploadLanguage/saveTranslations/deleteLanguage throw InvalidArgumentException; loadTranslations
  falls back to the en master for an unsafe code (defense in depth). Valid codes (en, en-US, testlocale) unaffected.
- Tests: tests/Integration/TranslationCodeSecurityTest.php (5) - traversal rejected on create/delete/save,
  setLocale ignores traversal, valid custom code still accepted.

## Verification

PHPStan L9 clean · full PHPUnit 590 pass (585 + 5 new) · existing LanguageSystemTest still green.

## Sweep 2 (2026-06-22): SMS, MFS parsing, mobile device pairing

VERIFIED HARDENED (no findings):

- SMS ingestion (SmsController): merchant_id + device_id from $req->getAttribute (server-side, set by
  JWT auth from the device's pairing record) - NOT request body → no cross-tenant SMS injection. Batch/
  field caps. (`MfsService` no longer exists; MFS logic lives in SmsParserService + SmsVerificationJob.)
- SMS→payment matching (SmsVerificationJob): forTenant-scoped, pending-only, atomic (DB transaction).
- SMS/MFS parsing (SmsParserService::processOne): AES-256-GCM AUTHENTICATED decryption; plaintext used as
  a STRING for regex extraction - NO unserialize (no object-injection RCE); dedup (replay protection);
  no exec/eval/system/file_put_contents.
- Device pairing (DevicePairingService): CSPRNG OTP (random_int), SHA-256 hashed, single-use via
  SELECT...FOR UPDATE txn, 5-min expiry, prior-OTP invalidation, endpoint rate-limited; pairDevice
  FAILS CLOSED if the OTP creator is unresolved (no superadmin fallback); fingerprint binding; JTI
  blacklist on refresh.

FINDING (fixed) - Privilege-escalation fail-open in DevicePairingService::refreshAccessToken

- Issue: when a refresh token's subject did not resolve to a real user id (userId === 0 - e.g. an opaque
  non-JWT token with no `sub`, or a non-numeric `sub` like 'device:uuid' from JwtService::encode), the
  code defaulted `$userId = 1` and issued a NEW access token AS user 1 (the superadmin). Fail-OPEN to the
  highest privilege.
- Severity: LOW–MEDIUM, LATENT (not currently reachable: all real refresh tokens are JWTs issued with
  sub>0 via pairDevice (fail-closed on userId<=0), and JwtService::encode() has ZERO callers). But a
  genuine privilege-escalation fail-open.
- Fix: fail closed - `if ($userId <= 0) return ['success'=>false,'error'=>'INVALID_REFRESH_TOKEN']`.
  Mirrors pairDevice's fail-closed philosophy. Legit tokens (sub>0) unaffected.
- Tests: NEW tests/Integration/DeviceRefreshPrivilegeTest.php (sub<=0 rejected; legit sub>0 refresh
  succeeds). Updated tests/Service/DevicePairingServiceTest.php::testRefreshTokenSuccess (it encoded the
  old superadmin fallback via an opaque token) → now testRefreshWithUnresolvableSubjectIsRejected.
- Verified: PHPStan L9 clean; full PHPUnit 592 pass.

## Notes

- Excluded per request: modules/gateways/** (~135 adapters).
- Minor non-finding: UrlValidator::isSafeOutbound() does not DNS-resolve hostnames (only literal IPs), so
  a hostname → private IP could pass it; but the user-influenced webhook path uses isValidWebhookUrl +
  resolveSafeWebhookIp (which resolve+pin). isSafeOutbound callers are trusted/admin. Left as-is (hardening).
