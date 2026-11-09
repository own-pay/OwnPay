---
name: testing-and-verification
description: Run and interpret OwnPay's test and lint suites. Use when verifying a change locally, working out which PHPUnit suites need MySQL, getting coverage output, fixing lint failures, or deciding whether a failing test is a regression or a pre-existing environment problem.
---

# Testing and Verification

## Command matrix

What CI runs (`.github/workflows/ci.yml`, on every PR to `main` or `dev`) and what a PR must pass before review (`.github/CONTRIBUTING.md:45-56`):

| Command | Expands to | Covers |
| --- | --- | --- |
| `composer test` | `vendor/bin/phpunit` | All nine suites in `phpunit.xml` |
| `composer analyse` | `vendor/bin/phpstan analyse` | Level 9 over `config`, `modules`, `src`, `templates/install`, `public/index.php` |
| `composer lint` | `npm run lint && composer lint:twig` | ESLint + Stylelint + Twig |
| `composer lint:twig` | `vendor/bin/twig-cs-fixer lint templates` | Twig style |
| `npm run lint` | `lint:js` then `lint:css` | ESLint `public/assets/js/**/*.js`, Stylelint `public/assets/css/**/*.css` |
| `npm test` | `vitest run` | JS unit tests (jsdom) |
| `npm run lint:json` | `eslint "**/*.json"` | JSON style — **not** wired into `npm run lint` and not run by any blocking CI job |
| `composer audit` | `composer audit` | Dependency vulnerabilities (CI job `composer-audit`) |
| `vendor/bin/parallel-lint src config modules templates/install public/index.php` | — | PHP syntax, same paths CI's `php-lint` job uses |

Run the whole gate locally before pushing:

```bash
vendor/bin/parallel-lint src config modules templates/install public/index.php
composer analyse
composer test
composer lint
npm test
composer audit
```

## MySQL: which suites actually need a database

`phpunit.xml` pins `DB_HOST=127.0.0.1`, `DB_NAME=ownpay_test`, `DB_USER=root` (`:42-45`). With no server reachable:

- 59 of the 66 files in `tests/Integration/` extend `IntegrationTestCase` and need MySQL; the other seven run DB-free (`SmokeTest`, `FlowTest`, `DomainsAddWizardTest`, `DomainsManagePanelTest`, `DomainsPageCardListTest`, `SettingsDomainsLinkTest`, `MobileBootstrapRouteTest`).
- **Five classes outside it** extend `IntegrationTestCase` and need MySQL too: `tests/Unit/MerchantRepositoryFindFirstTest.php`, `tests/Feature/PlatformMaintenanceTest.php`, and `tests/Plugin/{PluginTrashTest,TenantPluginLifecycleTest,BrandGatewayConfigSyncTest}.php`. Never conclude "`tests/Integration` is the only DB suite" from directory names — `grep -rln "extends IntegrationTestCase" tests/`.
- Some plain unit tests boot the container and hit MySQL anyway (e.g. `tests/Unit/CheckoutPaymentMethodVisibilityTest.php`, `tests/Unit/CheckoutGatewayTabsAndTestConnectionTest.php`).
- **`IntegrationTestCase` tests skip cleanly; the no-DB errors come from plain unit tests.** `IntegrationTestCase::setUp()` calls `markTestSkipped()` when no server is reachable (`tests/Integration/IntegrationTestCase.php:44`), so `vendor/bin/phpunit tests/Integration` exits 0 with every DB test skipped — a green run there is *not* evidence the DB suite ran. The errors come from container-booting unit tests that hit MySQL anyway (verified without a server: full suite `Tests: 1027, Errors: 12`, all `PDOException: SQLSTATE[HY000] [2002] Connection refused` in `tests/Unit/CheckoutGatewayTabsAndTestConnectionTest.php` and `tests/Unit/CheckoutPaymentMethodVisibilityTest.php`). Grep `static::$dbAvailable` in the failing class before believing either claim.

### Suites that run DB-free (the reliable local signal)

```bash
vendor/bin/phpunit tests/Security/SecurityRemediationTest.php \
  tests/Unit/AfricaMenaGatewayTest.php tests/Unit/GlobalGiantsGatewayTest.php \
  tests/Unit/SoutheastAsiaGatewayTest.php tests/Unit/IndiaMfsGatewayTest.php \
  tests/Unit/BangladeshMfsGatewayTest.php tests/Unit/GatewayWebhookBypassTest.php \
  tests/Unit/GatewayWebhookFailClosedTest.php tests/Unit/AlipayGatewayTest.php \
  tests/Unit/CCAvenueGatewayVerifyTest.php
```

Everything else in the webhook/verification surface either lives in `tests/Integration/` or boots the container — run it only with a database up.

## Interpreting failures

- **Diff against the base before blaming your change.** Run the same suite on `dev` (or check CI on the base) and compare: MySQL-less environments produce identical failures on both sides.
- **Pre-existing / environmental, do not chase**: any `Connection refused` or typed-property-in-`tearDown` error in a no-DB environment — `tests/Unit/CheckoutPaymentMethodVisibilityTest.php` fails exactly this way locally (verified: 7 errors, all `PDOException … Connection refused`). `tests/Unit/GatewayApiServiceCompletionEligibilityTest.php` is likewise named as a pre-existing failure to watch; it passed when re-run in isolation, so reproduce on the base branch before treating any failure in either file as your regression.
- PHPUnit 12 prints "mock without expectations" notices; they are cosmetic and do not fail the run.
- Committed `vendor/` can drift from `composer.lock`: CI installs from the lockfile, so a green local run against the committed `vendor/` does not prove CI passes. Treat lockfile-installed results as authoritative.

## Coverage

`phpunit.xml` declares the coverage **source** (`<source><include><directory>src</directory>`, `:36-40`) but configures **no report**, so a plain `vendor/bin/phpunit` writes no coverage file on this branch. To collect coverage you need a driver (xdebug or pcov) plus an explicit flag:

```bash
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text tests/Unit/<file>.php
```

CI's `phpunit` job installs `coverage: xdebug`; every other job sets `coverage: none`. Frontend coverage is separate: `npm run test:coverage` (`vitest run --coverage`).

## Environment pins worth knowing

- `phpunit.xml` sets `APP_ENV=testing` (`:52`), so production guards (`GatewayDefaults::isProductionEnv()`, …) are inert under the suite. Guard tests must set `$_ENV['APP_ENV']` themselves — see `tests/Unit/GatewaySimulationGuardTest.php`.
- `.github/workflows/eslint.yml` runs `npx eslint .` with `continue-on-error: true` and only on `main` — JSON lint problems never fail CI. For a new JSON file, run `npm run lint:json` yourself (comment-free, 2-space indent).
- `vendor/` is tracked in git; `node_modules/` is not, so any packaging or CI-faithful run needs `npm ci` first.

## Verification checklist for a PR

1. The commands in the matrix above pass, or failures match the base branch exactly.
2. PHPStan stays clean at level 9 with no new baselines or `@phpstan-ignore`.
3. New behaviour ships with tests; bug fixes ship with a regression test.
4. The PR description states what changed, why, and how to test it.
