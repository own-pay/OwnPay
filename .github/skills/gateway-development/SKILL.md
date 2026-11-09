---
name: gateway-development
description: Build or modify a OwnPay payment gateway adapter under modules/gateways/. Use when creating a new gateway, editing an existing adapter class or manifest.json, implementing GatewayAdapterInterface methods (initiate, verify, verifyWebhook, refund), adding webhook signature checks, or writing gateway unit tests.
---

# Gateway Development

## When to Use

- Adding a new payment gateway under `modules/gateways/<slug>/`
- Changing an existing adapter's `initiate` / `verify` / `verifyWebhook` / `refund` behaviour
- Adding capabilities, currencies, or CSP origins to a `manifest.json`
- Writing or fixing tests that touch gateway adapters

## Anatomy of a gateway

Each gateway is one folder under `modules/gateways/<slug>/`, mirroring the common sibling shape:

```
modules/gateways/<slug>/
├── manifest.json      # metadata, capabilities, CSP origins, icon reference
├── <Slug>Gateway.php  # the adapter class
└── icon.svg
```

That three-entry shape is the dominant one, but two known variants exist across the 123 gateways: 12 folders ship the icon as `assets/icon.png` / `assets/logo.jpg` (or a root `logo.png` in `adyen`, `affirm`) instead of `icon.svg`, and `stripe`, `bkash-api`, `sslcommerz` add an `assets/` directory beside the three entries. Scaffold the shape above; only copy a sibling's icon layout if you are deliberately mirroring its variant.

`manifest.json` fields (verified against `modules/gateways/2checkout/manifest.json`): `name`, `slug`, `version`, `description`, `author`, `type: "gateway"`, `entrypoint` (the PHP file name), `namespace`, `capabilities` (e.g. `gateway`, `http_outbound`, `hooks`), `requires`, `category`, `color`, and a `csp` block (`script_src` / `style_src` / `frame_src` origins). Keep JSON comment-free and 2-space indented — the repo's eslint jsonc rules expect that even though CI never blocks on it.

## The adapter contract

Implement `OwnPay\Gateway\GatewayAdapterInterface` (`src/Gateway/GatewayAdapterInterface.php:12`) and `use GatewayDefaults` (`src/Gateway/GatewayDefaults.php:13`) for the no-op fallbacks. The interface methods:

| Method | Contract |
| --- | --- |
| `slug(): string` | Unique gateway slug, matches the folder name |
| `initiate(array $params, array $credentials): array` | Returns `redirect_url` or `form_html`; `$params['amount']` is a **bcmath string** |
| `verify(array $callbackData, array $credentials): array` | Returns `success` plus `gateway_trx_id`, `amount`, `status` |
| `verifyWebhook(string $rawBody, array $headers, array $credentials): bool` | Signature authenticity only |
| `refund(string $gatewayTrxId, string $amount, array $credentials): array` | Amount is a bcmath string |
| `supportsRefundStatus()` / `getRefundStatus()` | Leave the `GatewayDefaults` stubs unless the provider has a refund-status API |
| `supports(string $feature): bool` | Capability probe |
| `supportedCurrencies(): array` | Declare accurately; `[]` means currency-agnostic (interface docblock, `:110-116`) |

Test the full flow locally: initiate → callback → verify → refund (`.github/CONTRIBUTING.md:87`).

## Sandbox rules

Plugins run under `PluginSandbox` (`.github/CONTRIBUTING.md:86`: no `exec`, `shell_exec`, `eval`, raw PDO, etc.):

- `PluginSandbox::isDangerousFunction()` (`src/Plugin/PluginSandbox.php:137-148`) blocks `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`, `proc_nice`, `pcntl_exec`, `dl`, `assert`, `create_function`.
- SQL validation blocks DDL/DML like `drop`, `truncate`, `alter`, `grant`, `revoke`, `into outfile`, and any `op_*` table other than `op_plugin_migrations` (`src/Plugin/PluginSandbox.php:109-123`).
- Outbound HTTP follows the sibling-adapter pattern: plain `curl_*` calls as every other adapter does (119 of 123 use `curl_init` directly). Never open a PDO connection or interpolate SQL from plugin code — persistence is the core's job through its repositories and services.

## Webhook verification is fail-closed

- `GatewayDefaults::verifyWebhook()` returns `false` for everything (`src/Gateway/GatewayDefaults.php:57-59`). **Never** ship an override that returns a truthy value for an unverified payload — no "no signature header present ⇒ accept" branch, no "empty secret ⇒ skip" branch.
- `tests/Unit/GatewayWebhookFailClosedTest.php` enumerates adapters and asserts they reject both a missing signature header and a caller-chosen forged header (`:171-185`); `tests/Unit/GatewayWebhookBypassTest.php` covers bypass variants. Your adapter joins those suites, so a fail-open stub fails CI.
- When a real signature scheme is added, prove it still verifies a configured secret (the shape is `testCheckoutComStillVerifiesConfiguredSecret`, `:193`).

## Simulation branches must be production-gated

Adapters that accept a simulated payment while in sandbox mode must gate the branch:

```php
if ($mode === 'sandbox' && !$this->isProductionEnv()) { /* simulated accept */ }
```

- `GatewayDefaults::isProductionEnv()` (`src/Gateway/GatewayDefaults.php:189`) returns `true` when `APP_ENV` is unset — it fails closed.
- `phpunit.xml` pins `APP_ENV=testing`, so the guard is inert under the test suite. A guard test must set `$_ENV['APP_ENV'] = 'production'` itself and restore the prior value in `tearDown`; copy `tests/Unit/GatewaySimulationGuardTest.php`.

## Testing quirks

- **Adapters are not autoloaded.** `composer.json` maps `OwnPay\` to `src/` only (`:20-27`), so the test must load the file itself:

  ```php
  require_once dirname(__DIR__, 2) . '/modules/gateways/<slug>/<Slug>Gateway.php';
  ```

  then reference the FQCN from the manifest's `namespace`.
- Adapters declare no constructor, so `new $adapterClass()` needs no container and fires no side effects.
- **Data providers use PHPUnit 12 attributes**: `#[DataProvider('provideX')]` from `PHPUnit\Framework\Attributes\DataProvider`, provider method `public static`. Annotation-style `@dataProvider` is not used anywhere in this repo.
- Gateway webhook suites run DB-free and are the fastest signal:

  ```bash
  vendor/bin/phpunit tests/Unit/GatewayWebhookFailClosedTest.php tests/Unit/GatewayWebhookBypassTest.php tests/Security/SecurityRemediationTest.php
  ```

  The region-specific suites (`AfricaMena`, `GlobalGiants`, `SoutheastAsia`, `IndiaMfs`, `BangladeshMfs`, `Alipay`, `CCAvenueGatewayVerify`) are likewise DB-free — see the `testing-and-verification` skill for the full matrix.

## Verification

```bash
vendor/bin/parallel-lint modules/gateways/<slug>
vendor/bin/phpstan analyse
vendor/bin/phpunit tests/Unit/<your-gateway-test>.php
composer lint
```

Money stays a bcmath string end to end, `verifyWebhook()` fails closed, every simulation branch is production-gated, and PHPStan level 9 stays green.
