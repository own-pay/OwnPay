# AGENTS.md

Guidance for coding agents (opencode, Copilot, Claude, and other `AGENTS.md` readers) working in `own-pay/OwnPay`. Every claim below is verified against this tree; when you change a cited file, update the citation.

## Repo map

| Path | Contents |
| --- | --- |
| `config/` | `app.php`, `database.php`, `hooks.php`, `services.php`, `middleware.php`, `languages/`, `routes/` (`web.php` for admin/storefront pages, `api.php` for REST) |
| `src/` | Application core: `Controller/` (`Admin/`, `Api/`, `Checkout/`, `Install/`, `Page/`, `Webhook/`), `Service/`, `Repository/`, `Gateway/`, `Security/`, `Middleware/`, `Model/`, `Plugin/`, `View/`, `Core/`, `Http/`, `Event/`, `Queue/`, `Cron/`, `Update/`, plus `Kernel.php` and `Container.php` |
| `modules/` | Modular plugins: `gateways/` (one folder per gateway with `manifest.json`, adapter class, icon), `addons/`, `themes/` |
| `templates/` | Twig views: `admin/`, `checkout/`, `install/`, `email/`, `error/`, `page/`, `layout/` (under `admin/`) |
| `tests/` | PHPUnit suites: `Unit/`, `Integration/`, `Feature/`, `Service/`, `Middleware/`, `Controller/`, `Event/`, `Plugin/`, `Security/` (see `phpunit.xml`) |
| `database/` | `schema.sql`, `seeds/`, `migrations/` |
| `docs/` | `ARCHITECTURE.md`, `FEATURES.md`, `LOCAL_SETUP.md`, `TRANSLATIONS.md`, and the rest |
| `public/` | Web root: `index.php`, `assets/` (js, css, uploads) |

The mobile companion app lives in a separate repository (`own-pay/OwnPay-Mobile-App`) and is out of scope here. REST APIs for it are served from `config/routes/api.php`.

## Hard invariants

Source: `.github/CONTRIBUTING.md:64-73`. Do not merge a change that violates these:

- **Strict types.** Every PHP file starts with `declare(strict_types=1);` as its first statement (no BOM).
- **PHPStan level 9 stays green.** `phpstan.neon` pins `level: 9` over `config`, `modules`, `src`, `templates/install`, `public/index.php`. Do not add baselines or `@phpstan-ignore` suppressions for new errors — fix the root cause (`.github/CONTRIBUTING.md:65`).
- **Money is bcmath strings, never floats.** Financial correctness is non-negotiable.
- **Tenant scoping is mandatory.** Repositories that `use TenantScope` (`src/Repository/TenantScope.php:14`) must run scoped queries through `forTenant()` / `*Scoped()`; `forAllTenants()` (`:41`) only for deliberate owner-level views. `TenantScope` is opt-in, so a repository without the trait is a cross-tenant hole, and `forTenant(0)` filters `merchant_id = 0` — it is not a global read.
- **URLs come from `DomainUrlService`** (`src/Service/Domain/DomainUrlService.php:21`), specifically `buildCheckoutUrl()` (`:89`) and `buildCallbackUrl()` (`:102`). Never hardcode a host: white-label custom domains depend on it.
- **CSRF tokens only via `SecurityHelpers::csrfToken()`** (`src/Security/SecurityHelpers.php:22`). Never read `$_SESSION` for the token directly.
- **Prepared statements only.** No string-interpolated SQL, ever.
- **Escape output.** Twig autoescaping stays on; never `|raw` untrusted data.
- **Keep it lean.** No new heavyweight dependencies without discussion; prefer the existing first-party utilities (`composer.json` requires only PHP 8.3, a few extensions, and a handful of small libraries).
- **Comments explain *why*, not *what*.**

## Verification gates

This is what CI runs (`.github/workflows/ci.yml`, on every PR to `main` or `dev`) and what a PR must pass before review (`.github/CONTRIBUTING.md:45-56`):

```bash
composer test       # vendor/bin/phpunit — all tests must pass
composer analyse    # vendor/bin/phpstan analyse — must stay clean at level 9
composer lint       # npm run lint && composer lint:twig
```

Raw equivalents used directly by CI:

```bash
vendor/bin/parallel-lint src config modules templates/install public/index.php
vendor/bin/phpstan analyse
vendor/bin/phpunit
vendor/bin/twig-cs-fixer lint templates
npm run lint        # eslint (public/assets/js/**) + stylelint (public/assets/css/**)
npm test            # vitest run
composer audit      # dependency vulnerabilities (security-check script)
```

Caveats:

- **A run without MySQL is not a full run: `IntegrationTestCase` tests skip, a few container-booting unit tests error.** `phpunit.xml` points at `DB_HOST=127.0.0.1` / `ownpay_test`. Tests extending `IntegrationTestCase` — 59 of the 66 test files in `tests/Integration/` plus five classes elsewhere (grep `extends IntegrationTestCase` before assuming a suite is DB-free) — mark themselves skipped when no server is reachable, so a green no-DB run is not evidence the DB suite actually ran; the errors come from plain unit tests that boot the container and hit MySQL anyway (without a DB: 12 such `PDOException … Connection refused` errors, all in `tests/Unit/CheckoutGatewayTabsAndTestConnectionTest.php` and `tests/Unit/CheckoutPaymentMethodVisibilityTest.php`). Compare failures against the base branch before blaming your diff.
- **Gateway webhook suites run DB-free**: `tests/Security/SecurityRemediationTest.php` and the `tests/Unit/` gateway suites (`AfricaMena`, `GlobalGiants`, `SoutheastAsia`, `IndiaMfs`, `BangladeshMfs`, `GatewayWebhookBypass`, `GatewayWebhookFailClosed`, `Alipay`, `CCAvenueGatewayVerify`).
- **PHPStan must stay at level 9.** A local green run against the committed `vendor/` does not prove CI passes; CI installs from `composer.lock`.
- **JSON linting is not in CI.** `npm run lint:json` exists but is not wired into `npm run lint` and no workflow blocks on JSON problems; run it yourself when adding JSON (comment-free, 2-space indented, per `eslint.config.js`).

## Branch and PR conventions

- **Branch from `dev`**, the integration branch that PRs target (`.github/CONTRIBUTING.md:30`); `main` is the release/default branch.
- One logical change per PR; split large work into reviewable pieces.
- **DCO sign-off**: every commit needs `git commit -s` (`Signed-off-by:` line); Conventional Commits style (`feat:`, `fix:`, `docs:`, …) is encouraged (`.github/CONTRIBUTING.md:101-113`).
- Fill out the PR template: what changed, why, how to test; link the issue (`Closes #123`); all CI checks green (`.github/CONTRIBUTING.md:117-124`).

## Skills

Skills live in `.github/skills/<name>/SKILL.md` and are registered for opencode in `opencode.json`:

- `code-review` — multi-axis review (correctness, readability, architecture, security, performance); use before merging any change.
- `gateway-development` — build or modify a payment gateway adapter under `modules/gateways/`: manifest, interface, sandbox rules, webhook verification, test quirks.
- `admin-ui` — admin panel routes, controllers, `templates/admin/` views, the `op-*` component library, tenant scoping and accessibility conventions.
- `testing-and-verification` — the full local command matrix, which suites need MySQL, coverage/lint gotchas, and known pre-existing failures.

## Agent tooling

- `opencode.json` (repo root) registers the skill path and denies edits to `vendor/`, `node_modules/`, `.env`, and `storage/` runtime paths. It is schema-validated against `https://opencode.ai/config.json`.
- `.opencode/plugin/ownpay-guard.ts` is a `tool.execute.before` hook that throws when `edit`/`write`/`patch` target `vendor/`, `node_modules/`, `.env`, or `storage/` — the enforcement backstop behind the permission rules. It is written as zero-annotation TypeScript so `node --check` can syntax-check it without a TS toolchain.
- `CLAUDE.md` stays gitignored and local-only (`.gitignore`); do not commit it. `AGENTS.md` itself is tracked — keep it accurate.
- No application code, tests, or CI workflows are changed by the agent-setup files; if a guard or permission rule blocks something legitimate, fix the pattern rather than working around it in shell.
