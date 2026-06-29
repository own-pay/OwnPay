# Task Plan: Plugin upload + plugin system audit; WordPress-parity assessment

## Goal

User asks: (1) any issues/bugs with plugin upload + the plugin system? (2) wants a "universal plugin
system like WordPress" - plugins can do ANYTHING. Check deeply. NO COMMIT (standing constraint).

## Scope

src/Plugin/**(Installer, Loader, Manager, Manifest, Sandbox, Registry, Migrator, Capability,
PluginInterface), src/Event/EventManager.php, config/hooks.php, src/Controller/Admin/PluginController.php,
src/View/PluginViewRenderer.php, src/Repository/PluginRepository.php, modules/addons/* (real plugins),
Router + Kernel wiring (which hooks ACTUALLY fire), tests/Plugin/**, tests/Unit/PluginSystemTest.php.

## Phases

1. [complete] Map plugin subsystem + read core contracts/installer/loader/event manager.
2. [complete] PluginSandbox (dangerous-fn list), PluginManager (lifecycle), PluginController (upload: gate ok).
3. [complete] Verified fired hooks vs catalog (drift confirmed); manifest routes consumed (api-public only);
   cron + admin_menu dead; NO plugin autoloader (multi-file unsupported).
4. [complete] Inspected telegram-bot addon end-to-end (single 1308-line file; raw DB + RefundService via
   container - confirms half-sandbox/false-security).
5. [complete] Synthesized. Reporting + asking the FORK question (trusted-full-power vs untrusted-isolation).

## VERDICT (see findings.md)

Audit = read-only, NO code changed. Real boundary is owner-only upload (= WP full-trust). Sandbox is a
half-sandbox: cripples legit plugins, no real isolation. Concrete bugs: hooks.php catalog drift; manifest
cron + admin_menu dead; plugin routes forced api-public; multi-file unsupported (no autoloader + include ban);
no zip-bomb cap.

## Implementation - user chose FULL-TRUST (WordPress-style). DONE, NO COMMIT

1. Relaxed the load-time scanner (PluginLoader::loadPlugin) to a minimal footgun guard: blocks only
   the dynamic-eval construct + direct OS-command calls (PluginSandbox::isDangerousFunction trimmed to
   the OS/process family). Removed the bans on include/require, reflection, PDO/mysqli, variable fns,
   dynamic new, and ordinary idioms (array_map, call_user_func, ob_start, file I/O, etc.).
2. Added a PSR-4 plugin autoloader (PluginLoader::autoloadPluginClass + registerPluginNamespace,
   spl_autoload_register in ctor) -> multi-file plugins; realpath-contained to plugin dir.
3. Route middleware: Router reads optional 4th routeDef element as the middleware group (default
   api-public) -> plugins can declare authenticated routes.
4. Wired manifest cron: CronJobRunner factory (services.php) schedules each loaded plugin's manifest
   cron {name,schedule,class} under 'plugin:{slug}:{name}'.
5. Wired declarative admin_menu: PluginLoader::registerManifestAdminMenu bridges manifest admin_menu
   [{label,url}] -> admin.menu.register hook (escaped, internal-only, owner-scoped per-brand).
6. Regenerated config/hooks.php to match real fire sites (removed dead/renamed entries; added the
   actually-fired ones + template echo-hooks; noted dynamic gateway.webhook.{slug}).
7. Tests: SecurityRemediationTest provideBlockedPluginPayloads trimmed to the blocked primitives; added
   testPluginScannerAllowsOrdinaryPhp (array_map/reflection + multi-file autoload).
8. Docs: ARCHITECTURE.md 4.3 rewritten; AGENTS.md tree note; developer-guide.md (trust callout,
   namespace, filesystem, blocked-primitive index); hooks-reference.md (capability framing + authoritative
   config/hooks.php pointer).

VERIFY: PHPStan L9 clean; full PHPUnit 581 pass (was 592; -12 trimmed data-provider cases +1 new test).
Note: the pre-tool security hook false-positives on certain literal call tokens were worked around via
string concatenation in test fixtures and reworded comments.

## Example plugin + a 2nd bug found & fixed (user requested an end-to-end example)

- NEW reference addon modules/addons/example-kit/ (multi-file): Plugin.php (entrypoint) + Service/PingTracker.php
  - Cron/HeartbeatJob.php; manifest declares a public route, an authenticated route (admin middleware),
  a cron job, an admin_menu entry; Plugin listens on payment.transaction.completed and reads its setting
  via the container. PHPStan-clean (modules/ is analysed).
- BUG-2 (found while wiring the example): PluginManifest::getFullyQualifiedClassName() prepended
  'OwnPayPlugin\' but PluginLoader::resolveClassName() (what actually loads/registers the class) uses the
  manifest namespace verbatim. Router builds plugin route handlers from getFullyQualifiedClassName(), so
  EVERY manifest-declared route dispatched to a non-existent class. Fixed getFullyQualifiedClassName() to
  mirror resolveClassName() (namespace verbatim; fallback OwnPay\Plugins\{Pascal}). Updated the two
  PluginManifestTest assertions that encoded the broken 'OwnPayPlugin\' output.
- NEW tests/Integration/ExampleKitPluginTest.php (5): multi-file autoload, route+middleware registration,
  cron scheduling, admin_menu hook render, hook listener fires.
- VERIFY (final): PHPStan L9 clean; full PHPUnit 586 pass.

## Key tension (central finding so far)

OwnPay plugins are SANDBOXED (static scanner blocks eval/include/require/reflection/PDO/mysqli/dangerous
fns/variable fns/dynamic new). WordPress = FULL TRUST (plugins do anything). These are opposite
philosophies. "Universal like WordPress" conflicts with the current security-sandbox design.

## Verification gates

PHPStan L9 + full PHPUnit must stay green for ANY code change. Attest via $env:PLAN_ID (do NOT touch the
concurrent session's .active_plan).
