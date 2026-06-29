# Findings - Plugin system audit

## Architecture (src/Plugin/)

- PluginInterface: metadata() static, capabilities(), register(EventManager,Container), boot(Container),
  deactivate(), uninstall(), fields(). => plugins get FULL DI container access in register/boot.
- Capability enum: GATEWAY, THEME, ADDON, COMMUNICATION, ANALYTICS, WEBHOOK, NOTIFICATION, EXPORT,
  AUTHENTICATION, STORAGE, CRON, DASHBOARD, DB_READ/WRITE, FILE_READ/WRITE, HTTP_OUTBOUND, HOOKS,
  CHECKOUT_UI. (rich, WP-like surface)

## PluginInstaller (upload path) - installFromZip()

- Pipeline: openZip → scanZipSecurity → extractToTemp → loadAndValidateManifest → deployPlugin → cleanup.
- scanZipSecurity: per-entry rejects '..', leading '/', ':' (zip-slip pre-scan, good). BLOCKED_EXTENSIONS =
  phar,sh,bat,exe,dll. NOTE: .php NOT blocked (by design - plugins ARE php). No size/ratio cap (zip-bomb;
  admin-gated => low).
- deployPlugin uses $manifest->slug in path WITHOUT sanitizeSlug - BUT validate() (called before) enforces
  slug regex ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$, so NOT exploitable. Defense-in-depth: should still sanitize.
- uninstall() DOES sanitizeSlug (inconsistent, but installer validates instead).

## PluginManifest::validate()

- slug regex (safe), type in [plugin,gateway,theme,addon], entrypoint plain filename (no ../ / \),
  migrations no '..'. Good. Manifest also parses: hooks{actions,filters}, adminMenu, cron, routes,
  dependencies, capabilities, namespace, requires{php,core}, icon, color, category.

## EventManager (WP-like, GOOD)

- addAction/doAction, addFilter/applyFilter(+applyFilters alias), priorities, owner per listener.
- isOwnerActive(owner): resolves BrandContext active brand + PluginRegistry->isPluginActive(owner,brandId)
  => per-brand plugin activation (multi-tenant). 'core' always active. Fails-open to true if container/err.
- Error isolation: each listener try/caught + logged; app continues.
- Special: filter 'db.query.before' from non-core owner → validates SQL via plugin sandbox; blocks on fail.
- removeByOwner/removeAllByOwner (cleanup on deactivate).

## PluginLoader (CRITICAL)

- discover(): scans modules/{gateways,themes,addons}/*/manifest.json.
- loadActive(): registry->getActive() (DB) → loadPlugin() each → boot() each → auto-register
  GatewayAdapterInterface instances into GatewayBridge. Fires plugins.before_load/after_load,
  plugin.load_error/boot_error.
- loadPlugin(): validate manifest+version; **STATIC TOKEN SCANNER** over ALL *.php in plugin:
  BLOCKS T_EVAL/T_INCLUDE*/T_REQUIRE*; `use` of reflection*/pdo/mysqli/dangerous fns; bare refs to
  reflection*/pdo/mysqli; dangerous-fn CALLS (PluginSandbox::isDangerousFunction + '('); variable fns
  $f(); wrapped ($f)(); dynamic `new $class`. Then require_once ENTRYPOINT ONLY; instantiate; register().
- CONSEQUENCE 1 (WP-opposite): plugins CANNOT eval/include/require, use reflection/PDO/mysqli, or call
  banned fns. This is a SANDBOX, the opposite of WP full-trust.
- CONSEQUENCE 2 (likely BUG/limitation): multi-file plugins - include/require banned AND loader only
  require_once's the entrypoint. Need to confirm whether any PSR-4 autoloader is registered for plugin
  classes (else multi-class plugins can't load their own files). TO VERIFY.
- CONSEQUENCE 3 (likely gap): loadPlugin does NOT read $manifest->routes / adminMenu / cron - only
  register()/boot(). So declarative routes/menu/cron in manifest may be dead unless consumed elsewhere.
  TO VERIFY (Router/Kernel/PluginManager).
- Possible sandbox BYPASS: string-callback indirection not caught by token scan, e.g.
  array_map('shell_exec', [...]) - 'shell_exec' is a STRING literal (T_CONSTANT_ENCAPSED_STRING), array_map
  itself not dangerous. TO ASSESS vs threat model (who can upload).

## RESOLVED / CONCLUSIONS

### Hook system IS extensively wired (good)

~80+ real fire points via $events->doAction/applyFilter across Kernel, Router, Database, CheckoutController,
Payment/Transaction/Fee/Ledger, Auth, Communication, Webhook dispatcher, Cron, Update, Domain, Dispute,
PluginManager/Loader. Templates fire hooks via {{ hook('name')|raw }} (TwigExtensions): checkout.head/footer,
landing.head, admin.head/footer, admin.menu.register (sidebar.twig:263), admin.dashboard.before/bottom,
admin.settings.* tabs, report.data. NOTE: hook() outputs |raw → plugins inject arbitrary HTML/JS into admin
AND checkout pages (XSS/skimming by design - fine for trusted, dangerous for untrusted).

### config/hooks.php catalog has DRIFTED (real DX bug)

Confirmed NEVER fired (in src/ or templates/): gateway.list, checkout.before_render, checkout.page.data,
checkout.after_render, admin.dashboard.widgets, invoice.total, invoice.paid, payment.transaction.refunded,
payment_link.used, gateway.webhook.received. Real hooks use different names (checkout.before, checkout.render,
checkout.template, admin.dashboard.before/bottom, refund.created). developer/index.twig docs also inaccurate.
A dev coding against the catalog would bind dead hooks.

### Router DOES consume manifest routes - but all forced to 'api-public' (Router.php:217-237)

Plugin routes registered from registry->getLoaded() as FQCN@action with middleware HARDCODED 'api-public'.
=> plugins cannot declare authenticated/admin routes or attach middleware. All plugin routes are public.

### Manifest cron + admin_menu are DEAD (parsed/validated, never consumed)

- cron: CronJobRunner never reads manifest->cron (jobs only via register()). Declared cron => nothing.
- admin_menu: only referenced in PluginManifest. Menu injection works ONLY via the admin.menu.register HOOK
  (HTML). Declarative admin_menu field is misleading/dead.

### Multi-file plugins effectively UNSUPPORTED

No spl_autoload_register / PSR-4 for plugins (only class_exists). include/require BANNED by scanner.
=> all plugin code must live in the single entrypoint file. telegram-bot = 1 file, 1308 lines (evidence).

### The sandbox is a HALF-sandbox = false security (CENTRAL finding)

Static scanner (PluginLoader::loadPlugin) blocks low-level primitives: eval/include/require, reflection,
PDO/mysqli, exec/shell_exec/system, file_put_contents/unlink/rename, AND ordinary idioms array_map/
array_filter/array_reduce/array_walk/usort/call_user_func/preg_replace_callback/ob_start.
BUT the DI container is fully open: a plugin does $container->get(Database::class)->execute($anySql) and
$container->get(RefundService::class)->create(...) - telegram-bot reads op_transactions/op_customers raw and
ISSUES REFUNDS. validateSql only guards the optional db.query.before filter (unused by plugins). Capabilities
(DB_WRITE/FILE_WRITE/HTTP_OUTBOUND) are NOT enforced at runtime. So:

- Untrusted plugin is NOT contained (raw DB incl. DROP TABLE, money movement, PII, JS into checkout).
- Trusted plugin is CRIPPLED (no array_map, no multi-file, no file writes) for no real security gain.
Only real boundary = upload is platform-owner-only (requireGlobalView) + admin auth (+ admin-group CSRF).
That is exactly WordPress's model (admin install = full trust).

### Upload path security (GOOD)

- upload()/installForm()/uninstall() gated by requireGlobalView (platform owner only). Brand admins cannot
  drop PHP. .zip ext check. confirm_update temp_zip path realpath-validated within storage/temp_uploads.
- resolveIconPath rejects non-image icon ext before copying into public webroot (anti shell.php-RCE).
- modules/ is outside public webroot; plugin php runs via controlled loader (require), not direct URL.
- zip-slip pre-scan (.. / : leading-slash) before extractTo. Minor gaps: no zip-bomb size cap;
  deployPlugin uses unsanitized slug (safe only because validate() ran first - defense-in-depth).

## VERDICT

"Plugins can do anything like WordPress" is ALREADY TRUE in power (container access). The problem is the
half-sandbox: it blocks normal coding (array_map, multi-file, file IO) without real isolation. To be truly
WP-like => commit to full-trust (owner-only upload, already the gate): relax scanner, add plugin autoloader,
wire cron + admin_menu (or document hook path), fix hooks.php catalog, allow route middleware. If instead
untrusted/marketplace plugins are wanted => the sandbox needs a real redesign (separate process / locked
container / capability enforcement), a much larger effort. FORK = ask the user.

## Open questions to resolve next

1. PluginSandbox::isDangerousFunction list - how restrictive? cripples legit plugins?
2. Plugin class autoloading for multi-file plugins (PSR-4 register?).
3. Are manifest routes/adminMenu/cron consumed? Where?
4. Which config/hooks.php hooks are ACTUALLY fired (doAction/applyFilters in core)?
5. PluginController upload: admin gate + CSRF + size limit + overwrite/migration flow.
6. Sandbox bypass severity vs upload trust model.
