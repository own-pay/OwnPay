# Progress Log

## Session: 2026-06-13

### Current Status
- **Phase:** 1 - Requirements & Discovery
- **Started:** 2026-06-13

### Actions Taken
-

### Test Results
| Test | Expected | Actual | Status |
|------|----------|--------|--------|

### Errors
| Error | Resolution |
|-------|------------|

## Session 2026-06-13 (implementation)
- Fix 1 SecurityHeadersMiddleware: CSP now built from ACTIVE gateway configs only
  (BrandContext-scoped, global-active fallback) + 7500-byte failsafe w/ logging.
  VERIFIED over Apache: /checkout|/invoice|/pay invalid tokens 500 -> 200 branded pages;
  CSP header 16275 -> 379 bytes. Real URLs from Jun 12 failures also verified working.
- Fix 2 CheckoutController::status(): guarded lease release (status-only UPDATE WHERE
  status=callback_processing) on soft-fail/exception/no-gateway. Same guarded pattern
  applied to PaymentIntentCheckoutController (it had unconditional revert = clobber risk,
  and no release when gateway_slug empty). callback_processing added to STATUS_LABELS and
  checkout-status.twig pending branch.
- Fix 3: client-facing 'Payment gateway error: '.$e->getMessage() leaks replaced with
  generic message (2 sites). Logged details remain server-side.
- Fix 4 Kernel: maintenance whitelist segment-aware matching; maintenance fallback now
  escaped via ErrorPageRenderer::maintenancePage(); extracted ALL inline HTML error pages
  + sanitizeErrorMessage into new src/View/ErrorPageRenderer.php (zero-dependency, lazily
  instantiated). Kernel 729 -> ~520 lines, single responsibility restored.
- Fix 5 SettingsRepository: per-request group-level memoization with invalidation on all
  writes/deletes; get()/getScoped() now route through cached group maps.
- Fix 6: removed login.html, login_post.html, cookies.txt (live session cookie!), gitignored;
  removed DB-credential echo from SmsParsingIntegrationTest; removed repro_500.php harness.
- Fix 7: new CheckoutPresentationTrait consolidates loadBrand() + status labels shared by
  CheckoutController and PaymentIntentCheckoutController.
- Note: Edit replace_all accidentally recursed resolveHmacKey body; caught and fixed
  immediately (lesson: replace_all with code that also exists in new helper body).
- Verification in progress: phpunit, phpstan, twig lint.

## Final verification (session close)
- Fix 8 discovered during maintenance smoke test: MaintenanceMiddleware blocked /login &
  /checkout despite Kernel whitelist. Consolidated whitelist into
  MaintenanceMiddleware::PASSTHROUGH_PREFIXES + isPassthroughPath(); Kernel now delegates.
  Removed dead container property (phpstan caught it).
- EnvironmentServiceTest failures were a real contract break (clearCache must force DB
  re-read) -> SettingsRepository::flushCache() added, wired into EnvironmentService.
  SecurityRemediationTest failure was mock-shape coupling -> kept original fetchOne query
  shape in get()/getScoped() with per-key memoization instead of group-routing.
- FINAL: phpunit 525/1693 OK (1 skipped, same as baseline), phpstan clean, twig lint clean.
- FINAL Apache smoke: / 200, /login 200, /checkout/INVALID 200 expired page,
  /checkout/OP-93E6F25CF9 200 checkout, /invoice/INVALID 200, /pay/INVALID 200,
  /nonexistent 404, /admin/transactions 302 login redirect.
- Maintenance mode verified live: / and /pay blocked w/ branded 503 + Retry-After 120 +
  escaped reason; /login and /checkout reachable; lock removed cleanly.
- ARCHITECTURE.md sections added: 2 (ErrorPageRenderer, maintenance whitelist),
  5.4 (CSP sourcing), 5.5 (callback lease), 6.2 (settings memoization).
