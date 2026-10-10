# OwnPay threat model

This document tells the OSS Scanner what OwnPay is, where untrusted input enters, and how we rate severity. It is read alongside the source, not instead of it.

## What OwnPay is

OwnPay is a self-hosted, open-source payment gateway. It creates payment intents, drives 123+ third-party gateway adapters in `modules/gateways/`, ingests provider webhooks, keeps a ledger, handles refunds and disputes, and exposes a merchant API plus an admin panel.

It handles real money and real cardholder data, so the things that matter most are:
moving money without authorization, forging a payment or refund webhook, reading or writing
another merchant's data, and leaking PII into logs, backups or exports.

## Entry points for untrusted input

In rough order of how exposed they are:

- **Provider webhooks.** `POST /webhook/{gateway}` (`config/routes/web.php:337`) →
  `src/Controller/Webhook/UnifiedWebhookController.php`, which calls
  `GatewayBridge::verifyWebhookSignature()` (`src/Gateway/GatewayBridge.php:155`) on every
  delivery and `GatewayBridge::verify()` (`:117`) on the callback path; both delegate to the adapter's `verifyWebhook()` / `verify()` in `modules/gateways/*`. These are unauthenticated HTTP endpoints that can credit an account. Signature verification that can be bypassed, or that falls through to a success result when a check is inconclusive, is the single most severe class of bug in this codebase. (`src/Gateway/WebhookInboundProcessor.php` is
  container-wired at `config/services.php:726` and covered by `tests/Integration/WebhookIdempotencyTest.php`, but nothing resolves it on a request, so it is not the live path.)
- **The install wizard.** `/install`, `/install/test-db`, `/install/import-schema`,
  `/install/create-admin`, `/install/finalize` (`config/routes/web.php:347-351`), registered unconditionally on the `install` stack, which is only `SecurityHeadersMiddleware` plus a
  `RateLimiterMiddleware` that fails open when its backend is unreachable (`config/middleware.php:101-108`). It is **not** left open after installation: every action calls `InstallerController::isInstalled()` (`:726`), which returns true when
  `storage/.installed` exists and, if that marker is missing, probes the configured database for an existing superadmin and self-heals the marker. Reaching `finalize()` on an installed instance would write `ENCRYPTION_KEY`, `AUDIT_HMAC_SECRET`, `JWT_SECRET` and the database credentials, so treat any bypass of that guard - including the `INSTALL_FORCE_KEY` escape hatch - as critical.
- **The merchant API.** `config/routes/api.php`, guarded by the middleware chain in `config/middleware.php` (`JwtAuthMiddleware`, `BearerAuthMiddleware`,
  `AdminBearerAuthMiddleware`, `ApiKeyRepository`, `PermissionMiddleware`,
  `TenantScope`). JSON bodies arrive through `php://input` and land in `src/Http/Request.php`.
- **The admin panel.** `config/routes/web.php`, session and CSRF guarded.
- **Installed code.** Plugins (`src/Plugin/`), themes and addons under `modules/`, and the self-update ZIP path in `src/Update/ZipUpdateService.php` all run administrator-supplied code inside the process. Anything that lets a lower-privileged actor write into `modules/` or reach the update path is effectively remote code execution.
- **Anything a payment gateway sends back**, including SMS parsing content (`tests/Integration/SmsParsing*`), is attacker-influenceable and must be treated as input.

## Where the security-relevant logic lives

- `src/Gateway/` and `modules/gateways/` - webhook verification, signature checks, gateway API calls, `GatewayDefaults.php`.
- `src/Security/` - `Authenticator`, `FieldEncryptor`, `PiiMasker`, `LogSanitizer`,
  `RequestValidator`, `UrlValidator`.
- `src/Middleware/` - authorization. Note `PermissionMiddleware` resolves an exact path first, then the first declared prefix match, default-denying unmapped `/admin/*` paths as
  `system.unmapped`. It escalates `.view` to `.manage` **only for POST** - `PUT`, `PATCH` and `DELETE` are not escalated, and slugs without a `.view` token (`system.update`,
  `system.audit`, `system.balance`, `system.reports`, `admin.access`) are returned as declared.
- `src/Repository/` - every SQL statement, and `TenantScope.php`, which is what keeps one merchant from reading another merchant's rows.
- `src/Plugin/PluginSandbox.php`, `PluginInstaller.php`, `PluginManager.php` - the boundary around third-party code.
- `src/Update/` - ZIP validation, signature verification, backup and restore.
- `src/Core/Database.php` - the PDO connection every repository shares.

## Severity guidance

Please rate against our impact, not against how impressive the exploit looks.

- **Critical.** Reaching money or another merchant's data without authorization: forging a payment, refund or dispute webhook; bypassing `verifyWebhook()`/`verify()` on any adapter; bypassing authentication or the permission middleware; a SQL injection that reads or writes another tenant's rows, or the `users`, `transactions`, `ledger` or `api_keys` tables;
  unauthenticated remote code execution via the plugin or self-update path; disclosure of
  cardholder data, or of `ENCRYPTION_KEY` (the field-encryption key, with `APP_KEY` as
  fallback), `AUDIT_HMAC_SECRET` (keys the audit-trail HMAC, see
  `src/Repository/AuditLogRepository.php:93`), `JWT_SECRET` or `APP_KEY`.
- **High.** The same bug class but only reachable by an authenticated merchant or a
  lower-privileged admin; SQL injection confined to the caller's own rows; stored XSS in the
  admin panel that can reach an administrator's session; CSRF or signature weakness on an
  endpoint that changes money-related state; PII leaking into logs or audit trails.
- **Medium.** Reflected XSS; CSRF without a meaningful impact; open redirect on a
  trusted-domain link; missing rate limits on a non-money path; a permission gap that grants
  read-only access to data the actor may not see.
- **Low.** Anything requiring an attacker to already be a trusted administrator, or that
  leaks no data and changes no state.

Two conventions we would ask you to apply:

- **Post-authentication SQL injection is not automatically lower severity.** For a payment
  gateway, SQL injection reachable by any merchant is high at minimum, and critical when it
  crosses `TenantScope`. Reachability, not the presence of a login, drives the rating.
- **Stored XSS in the admin panel is at least medium**, because an administrator session in
  OwnPay can install plugins and apply updates.

## Running the code in this image

The checkout is at `/src`. The static checks are green and are worth re-running:

```
vendor/bin/parallel-lint --no-progress src config modules templates/install public/index.php tests
vendor/bin/phpstan analyse --no-progress
vendor/bin/twig-cs-fixer lint templates
npm run lint
```

The PHPUnit suite is worth running and is where most of the reachable behaviour lives:

```
vendor/bin/phpunit --no-coverage
```

`tests/Service`, `tests/Controller`, `tests/Middleware`, `tests/Security` and `tests/Event`
need no database. `tests/Unit`, `tests/Plugin` and `tests/Feature` mostly do not either, but
five classes outside `tests/Integration` extend `IntegrationTestCase` and so need a live
database: `tests/Unit/MerchantRepositoryFindFirstTest.php:11`,
`tests/Feature/PlatformMaintenanceTest.php:14`, `tests/Plugin/PluginTrashTest.php:13`,
`tests/Plugin/TenantPluginLifecycleTest.php:18` and
`tests/Plugin/BrandGatewayConfigSyncTest.php:20`. `PluginTrashTest` and
`TenantPluginLifecycleTest` are the DB-backed lifecycle checks behind the "installed code"
entry point above, so do not skip the `Plugin` suite for want of a database. `tests/Security`
is specifically about authentication, log sanitization and PII masking and is the fastest
place to confirm you understand our intended invariants.

The front-end suite is Vitest and runs offline:

```
npm test
```

### The database

`tests/Integration` is where almost all of the database dependency lives; the five classes
listed above are the rest of it. It connects with the credentials in `phpunit.xml`
(`ownpay_test`, user `root`, password `root`). `IntegrationTestCase` calls
`markTestSkipped()` when it cannot reach a server, but five of its subclasses tear down state
that `setUp()` never assigned — the skip throws before the assignment, and PHPUnit still runs
`tearDown()` afterwards, so an exception there turns the skip into an error. Those classes
report **errors**, not skips. See the list below.

To bring one up by hand:

```
mysqld --datadir=/var/lib/mysql --user=mysql &
until mysqladmin ping --silent; do sleep 1; done
mysql -u root -e "CREATE DATABASE ownpay_test; ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'root'; CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED WITH mysql_native_password BY 'root'; GRANT ALL ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;"
mysql -u root -proot ownpay_test < /src/database/schema.sql
for seed in /src/database/seeds/*.sql; do mysql -u root -proot ownpay_test < "$seed"; done
```

Two things about this recipe. The `until mysqladmin ping` loop matters: `mysqld &`
returns immediately, so the next `mysql` call would otherwise fail on a socket that is
not listening yet. And the `root`@`127.0.0.1` account has to be created explicitly,
because `mariadb-install-db` with `--auth-root-authentication-method=socket`, which is
what Debian's postinst uses, does not create it.

Do not initialise the data directory yourself. Debian's `mariadb-server` postinst already
runs `mysql_install_db`, so `/var/lib/mysql` is populated during
`apt-get install default-mysql-server`; running `mariadb-install-db` again against an
initialised directory errors out. If you ever do need to initialise a directory yourself,
use `mariadb-install-db`, **not** `mysqld --initialize-insecure` — `--initialize-insecure`
is a MySQL option MariaDB does not implement, and it exits with
`unknown option '--initialize-insecure'`.

Then run the suite with `DB_HOST=127.0.0.1`. Note the image ships MariaDB, while the
`phpunit` CI job runs MySQL 8.0; schema behaviour on the two is not identical.

## Known pre-existing failures

These exist on `main` and are **not** caused by your work. Do not report them, and do not let
them mislead you about whether a change you made is sound.

- **With no database**, PHPUnit reports 19 errors, all in `tests/Integration`, all of the form
  `Typed property ... must not be accessed before initialization`. They come from five classes
  whose `tearDown()` runs after `markTestSkipped()` threw: `TelegramBotAddonTest::$db` ,
  `LanguageSystemTest::$db` , `SmsGatewayAddonTest::$db` ,
  `OnboardingRouteRedirectTest::$settingsRepo`  and `AdminPageRendererTest::$logDir` .
- **With a database and seeds loaded**, the same suite reports 72 errors and 9 failures. The
  integration tests want seeded data and a schema that are only partly in sync.
- **`Tests\Plugin\PluginInstallerTest::testInstallFromZipWithWindowsSeparators`** fails with
  `No manifest.json found in ZIP` in every configuration.
- Roughly 245 tests skip without a database.

## Out of scope

- Vulnerabilities in the third-party gateway providers or their SDKs. Report those to the
  provider. The adapters in `modules/gateways/` themselves are in scope.
- Misconfiguration of a deployed instance: an exposed `.env`, a missing HTTPS, debug mode left
  on, weak database credentials.
- Dependencies that already have a public CVE and a fixed version. Please just tell us and we
  will update.
- Denial of service through raw traffic volume, and social engineering.
