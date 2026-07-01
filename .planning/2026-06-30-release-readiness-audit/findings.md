# Findings — Release Readiness Audit

## Baseline (phase 2)
- **PHPUnit: 598 tests, exit code 0 (all pass)** on branch `fixing`. ✅
- DB: `ownpay` @ localhost:3306, root/root, prefix `op_`. Reachable (tests use it).
- `.env` is the DEV env: `APP_ENV=development`, `APP_DEBUG=true`. JWT_SECRET/APP_KEY/ENCRYPTION_KEY all ≥32 chars. (Production hardening = release checklist, not a code bug.)
- Observed during tests: `storage/.installed was missing but DB already has superadmin → marker self-healed, installer locked`. Self-heal path works; worth confirming `.installed` is present for prod.

## Architecture map (verified current)
- Front controller: `public/index.php` → `Kernel::handle()`.
- Boot: load .env → `instance(Container)` + `instance(Kernel)` → run `config/services.php` → EnvironmentService boot → Database+events/plugin wiring → timezone → plugins boot → middleware → `system.boot` → (if installed) validate JWT/APP_KEY/ENC_KEY ≥32 → `Router::loadRoutes()`.
- Container (`src/Container.php`): autowires by class type-hints; **untyped/primitive non-defaulted ctor param → `RuntimeException: Cannot resolve primitive parameter`** at resolve time. Confirms memory.
- Router (`src/Http/Router.php`): handlers are `Controller@method` strings. Core controllers map to `OwnPay\Controller\<name>`; FQCN only for plugin (non-core-namespace) controllers. `getRoutes()` exposes the full table → harness driver.
- Twig: `strict_variables=true`, autoescape html. Render errors surface as `Twig\Error\RuntimeError`.
- Middleware resolution in Kernel: `try container->get($mw) catch { new $mw($container) }` — fallback to Container-only ctor.

## Static baseline — ALL CLEAN (branch `fixing`)
- PHPUnit: **598 pass** (90 test files: 36 Integration + 25 Unit + 12 Service + Controller/Feature/Security/Middleware/Plugin/Event). Integration suites exercise write/mutation paths.
- PHPStan (level 9): **No errors**.
- php-parallel-lint: **244 files, no syntax errors** (rules out "comment-remove" churn damage).
- composer audit: **No security vulnerability advisories**.

## Runtime harnesses — ALL CLEAN
- DI harness (boots real container from services.php, drives off the 242-route table):
  - **61/61 controllers resolve**, **15/15 middleware resolve** — 0 runtime DI failures.
  - Untyped-ctor scan: 224 instantiable classes; 4 flagged (FileCache, FileQueue, WebhookPayload, PluginSandbox) — all confirmed `new`-constructed with explicit args / built inside interface factories, never container-resolved by FQCN → non-issues. **No untyped-ctor trap exists.**
- Render harness (invoke every non-API GET controller under Twig strict_variables, faithful superadmin):
  - **single-brand view: 85/85 GET pages render clean** (0 Twig errors, 0 exceptions, 0 app PHP warnings).
  - **global "All Brands" view: 85/85 render clean.**
- Scripts: scratchpad/di_harness.php, scratchpad/render_harness.php.

## Recent-churn review (delta since last clean audit)
- `2c4ade5 checkout`: large checkout CSS/JS/template rework → templates proven clean by render harness; CSS/JS need browser QA (not script-verifiable).
- `caca601 ... SmsTemplateRepository.php`: BINARY→`LOWER()=LOWER()` sender match = a **bug FIX** (case-insensitive), not a regression. Matches memory.
- `CheckoutController` (money path) reviewed: HMAC-bound payloads, double-submit guards (`status!=='pending'`), tenant scoping (`forTenant`), concurrency lock on callback capture (`callback_processing` lease). Robust.

## Bugs / findings (NONE release-blocking) — ALL RESOLVED
1. [FIXED] `public/assets/js/checkout.js` `openManualPopup()` — removed duplicate `var gwData` (kept rationale comment on the 1st decl).
2. [FIXED] checkout.js express `doQP` — replaced 2× `alert()` with `showCheckoutError()` toast for UX consistency with `executeGW`.
3. [VERIFIED — no change needed] custom-JS injection is already mitigated: `SettingsController::save` writes `custom_css`/`custom_js` only `if ($isSuperadmin)` and sanitizes `custom_css` (strips `<script>`, `javascript:`, `expression()`, `behavior:`); `BrandController` hardcodes both empty. Only superadmins (highest trust) can set custom JS → accepted white-label model, no bypass.

Verification of fixes: `node --check` exit 0, ESLint exit 0 on checkout.js, grep confirms 0 `alert(` / 1 `var gwData` / consistent showCheckoutError.

## Release checklist (human-gated; outside code-correctness)
- [ ] Production `.env`: APP_ENV=production, APP_DEBUG=false, real/rotated secrets, prod DB creds, HMAC_KEY set.
- [ ] `storage/.installed` present in prod deploy (self-heals if DB has a superadmin, but set it explicitly).
- [ ] Browser QA of reworked checkout UI (desktop + mobile) — CSS/JS not script-verifiable.
- [ ] End-to-end live payment test with at least one real gateway (sandbox bKash/Stripe).
- [ ] HTTPS/TLS enforced, security headers live, backups + monitoring configured.
- [ ] Mobile app (mobile-app/) = separate track, gated on release signing + Play listing + on-device verify (per ownpay-console-mobile-build-state memory). NOT part of this PHP release.
