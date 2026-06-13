# Findings: User Flow / Kernel / Framework Audit

## Kernel.php (src/Kernel.php, 729 lines)

### Why hardcoded HTML exists (user's question)
Three heredoc HTML blocks are deliberate **last-resort fallbacks** rendered only when the
template engine itself may be broken:
1. `sendServiceUnavailable()` (line ~529): 503 page during DB outage — MUST NOT touch Twig/DB
   or the error cascades.
2. `renderInlineErrorPage()` (line ~556): production 500 page when Twig is unavailable.
3. `renderDebugErrorPage()` (line ~600): APP_DEBUG developer error page (needs to work even
   when app boot failed, before Twig exists in container).
Every normal-path error (404, 503 maintenance, 500 production) tries Twig FIRST and only
falls back to inline HTML. Pattern is sound; structural polish option: extract into a
dedicated `ErrorPageRenderer` class so Kernel keeps single responsibility.

### Candidate bugs found in Kernel
1. **XSS in maintenance fallback** (line 290): `Response::html("<h1>Maintenance</h1><p>{$reason}</p>")`
   — `$reason` comes from `storage/.maintenance` JSON, interpolated UNESCAPED. Admin-written file,
   but defense-in-depth says escape. Also check error/503.twig autoescape (Twig autoescapes by default — OK).
2. **Maintenance whitelist too loose** (line 254): `str_starts_with($path, '/login')` also matches
   `/loginfoo`. Same for '/admin' matching '/administrator'. Low impact but sloppy prefix matching.
3. Kernel doing 4 jobs: boot, dispatch, exception rendering, HTML page generation (~250 of 729
   lines are HTML strings). SRP extraction candidate.

### Boot order notes
- Plugins boot BEFORE middleware filter (AUD-G1 fix) — intentional.
- Security middleware re-added if plugin removes (AUD-21) — good.
- JWT/APP_KEY/ENCRYPTION_KEY entropy checks only when installed — good fail-fast.

## Stray root artifacts (junk candidates)
- `login.html`, `login_post.html`, `cookies.txt` in PROJECT ROOT (not public/) — look like
  leftover curl test artifacts. Verify git tracking, then delete + gitignore.

## Architecture summary (from ARCHITECTURE.md)
- Single entry public/index.php -> Kernel. PSR-11 container, repository pattern + TenantScope,
  double-entry ledger, plugin sandbox, white-label domain pipeline (DomainUrlService mandatory).
- Settlement system decommissioned. op_env eradicated -> op_system_settings.
- Rules: strict_types everywhere, no direct $_SESSION csrf, DomainUrlService for URLs.

## Open questions
- Existing audit plan 2026-06-12-adversarial-production-audit in progress — read its progress.md
  to avoid duplicating/conflicting work.

## CheckoutController (src/Controller/Checkout/CheckoutController.php)
1. **BUG (info leak)**: pay() line ~691 and expressPay() line ~1051 return
   `'error' => 'Payment gateway error: ' . $e->getMessage()` to the CUSTOMER in JSON.
   Exception messages can contain internal hostnames/paths/credentials. Violates
   CLAUDE.md security rule. Fix: generic message to client, full message to logger only.
2. **DRY violations**: HMAC key resolution duplicated 4x (show/pay/cancel/expressPay);
   payment-link re-validation block duplicated 3x (~40 lines each); loadBrand likely
   duplicated in other checkout controllers. Extract private helpers.
3. **Possible stuck state**: status() claims txn via status='callback_processing' UPDATE.
   If handleCallback returns success=false and callbackStatus not in [cancel,failure,failed],
   or throws — txn may stay 'callback_processing' forever. Need to check GatewayApiService
   and any cron recovery.
4. renderStatus shows stale $status (read before claim) — minor UX.

## CONFIRMED BUGS (Phase 3)
1. **Stuck callback_processing (CheckoutController::status)**: claims txn with
   status=callback_processing but NEVER reverts on handleCallback failure/exception.
   PaymentIntentCheckoutController::status() lines 880-898 has the CORRECT revert
   pattern (revert to processing on soft-fail and in catch). CheckoutController lacks it.
   No cron recovers stuck states (checked src/Cron/*). Fix: mirror revert logic.
2. **statusLabels missing callback_processing** label -> customer sees raw
   "Callback processing". Add friendly label.
3. **Exception message leak to customer**: CheckoutController pay()/expressPay() catch
   returns 'Payment gateway error: '.$e->getMessage() in JSON. Replace with generic msg.
   Note GatewayApiService::handleCallback also returns 'Callback processing error: '.getMessage()
   but that is consumed server-side only.
4. **Test debug leak**: tests/Integration/SmsParsingIntegrationTest.php:36 echoes DB
   credentials JSON on every phpunit run. Remove echo.

## Baseline (Phase 2 complete)
- phpunit: OK 525 tests, 1693 assertions, 1 skipped (~40s)
- phpstan: level from phpstan.neon = clean, no errors

## Framework layer review
- Router (src/Http/Router.php): clean, linear regex match O(n), param charset constrained
  (BUG-023). No route cache — acceptable at this scale, OPcache covers parse cost. No change.
- SettingsRepository: NO per-request memoization — every get()/getGroup() = 1 DB query.
  Checkout render triggers ~10+ settings queries (faqs, show_faq, timer x2, 3 status msgs,
  brand theme lookups). OPTIMIZATION: add in-instance memo cache with invalidation on writes.
- loadBrand duplicated in CheckoutController + PaymentIntentCheckoutController (drifted:
  intent version also checks theme.primary_color). BrandThemeService IS always registered
  (services.php:548 singleton) so fallbacks are dead-in-practice; consolidation safe.
- statusLabels map duplicated in both checkout controllers; neither has callback_processing.
- checkout-status.twig line 21: callback_processing falls to ELSE (expired partial) — UX bug.

## ROOT CAUSE: checkout/invoice/pay 500 errors (CRITICAL user-flow bug)
Reproduced: GET /checkout|/invoice|/pay with any token -> Apache 500 (default ErrorDocument),
"Premature end of script headers" in Apache error_log. Worked via CLI + direct php-cgi.
Prior plan 2026-06-12-why-invoice-link-error diagnosed: SecurityHeadersMiddleware
collectGatewayCspSources() globs ALL modules/gateways/*/manifest.json (123 dirs) and merges
every CSP origin -> CSP header ~16KB > mod_fcgid 8KB header line limit -> worker killed.
Fix was NEVER implemented (that plan was report-only). User hit this on real URLs Jun 12
(checkout OP-93E6F25CF9, real invoice token). Also /admin/transactions 500 (9965b branded
debug page = PHP exception, separate issue, needs auth to reproduce).
FIX: scope manifest reads to ACTIVE gateway configs (BrandContext -> GatewayConfigRepository
listActive(), global fallback when no brand ctx) + 7500-byte failsafe that drops gateway
sources and logs if header would still exceed limit. Bonus: removes 123 file reads/request.

## Final fix list (Phase 5)
1. SecurityHeadersMiddleware CSP scoping + size failsafe (CRITICAL)
2. CheckoutController::status() lease revert + callback_processing labels + twig branch
3. CheckoutController pay()/expressPay() stop leaking $e->getMessage() to client
4. Kernel: escape maintenance $reason, tighten whitelist prefixes, extract ErrorPageRenderer
5. SettingsRepository per-instance memo cache w/ write invalidation
6. Cleanup: stray root files + .gitignore, test credential echo, repro_500.php removal
7. DRY: shared CheckoutControllerHelpers trait (loadBrand + statusLabels) if it stays simple
