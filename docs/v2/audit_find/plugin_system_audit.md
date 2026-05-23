# OwnPay Universal Plugin & Theme System — Architectural Audit Report

This document presents a deep, isolated structural audit of the **Universal Plugin System** and **Theme Architecture** for the OwnPay platform. The audit evaluates whether the system satisfies a 100% dynamic, decoupled, extensibility model—similar to WordPress—where core files remain completely closed to modifications, and all dynamic behavior is driven through Action Hooks, Filter Hooks, and clean interfaces.

---

## Executive Architectural Verdict

> [!WARNING]
> **Extensibility Rating**: **Partial Decoupling (Grave Gaps)**
>
> While the foundation of the hook-and-filter engine exists (`EventManager`), the core lifecycle, router, database class, and settings architecture contain severe tight-coupling and namespace locks. A third-party developer **cannot** build an isolated API gateway, register customized middleware, route to custom controllers, or safely build custom admin settings screens without modifying core files or database tables manually.

---

## 1. True WordPress-like Extensibility

### G1: Middleware Lifecycle Bootstrapping Bug
* **File Reference**: [Kernel.php](src/Kernel.php#L95-L128)
* **Code Evidence**:
  ```php
  // Line 95: Load middleware config
  $this->middlewareConfig = require $rootDir . '/config/middleware.php';

  // Line 98: Allow plugins to modify middleware pipeline
  /** @var EventManager $events */
  $events = $this->container->get(EventManager::class);
  $this->middlewareConfig = $events->applyFilter(
      'system.middleware.pipeline',
      $this->middlewareConfig
  );
  ...
  // Line 119: Boot plugins
  if ($this->container->has(\OwnPay\Plugin\PluginLoader::class)) {
      try {
          /** @var \OwnPay\Plugin\PluginLoader $pluginLoader */
          $pluginLoader = $this->container->get(\OwnPay\Plugin\PluginLoader::class);
          $pluginLoader->boot();
  ```
* **Architectural Gap**: The `system.middleware.pipeline` filter is executed *before* active plugins are loaded and booted. At the time of execution, no plugins are registered with the DI container or have registered event listeners. 
* **Impact**: Plugins are physically unable to hooks into `system.middleware.pipeline` to dynamically inject custom middleware (e.g. custom rate-limiters, specific IP restrictions).

### G2: Router Controller Namespace Lock
* **File Reference**: [Router.php](src/Http/Router.php#L196)
* **Code Evidence**:
  ```php
  [$controllerName, $methodName] = explode('@', $handler, 2);
  $fqcn = 'OwnPay\\Controller\\' . $controllerName;

  if (!class_exists($fqcn)) {
      throw new RuntimeException("Controller class [{$fqcn}] not found.");
  }
  ```
* **Architectural Gap**: The router enforces a hardcoded base namespace prefix (`OwnPay\Controller\`) inside `Router::dispatch()`. 
* **Impact**: Plugins cannot direct route targets to their own vendor namespaces (e.g. `OwnPay\Modules\Gateways\BkashApi\Controller`). All plugin-defined routes must have controllers colocated within the core `OwnPay\Controller\` folder, which breaks the boundary of independent plug-and-play modules.

### G3: Core Database Hook Void
* **File Reference**: [Database.php](src/Core/Database.php#L76-L121)
* **Code Evidence**:
  ```php
  public function execute(string $sql, array $params = []): PDOStatement
  {
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      return $stmt;
  }
  ```
* **Architectural Gap**: The database wrapper layer contains absolutely zero hook triggers (`doAction`/`applyFilter`).
* **Impact**: Developers cannot build caching plugins, query loggers, dynamic database table routing plugins, or automated database auditors because query execution is completely sealed and invisible to the hook dispatcher.

### G4: Request/Response Interception Void
* **File Reference**: [Kernel.php](src/Kernel.php#L173-L232)
* **Code Evidence**:
  ```php
  // Matches route, runs middleware, and returns response
  $response = $this->runMiddleware($request, $middlewareGroup, static function (Request $req) use ($router, $match): Response {
      return $router->dispatch($match['handler'], $req);
  });

  return $response;
  ```
* **Architectural Gap**: There are no hook filters applied to the raw `$request` object upon entry, nor are there filters applied to the returned `$response` before sending it to the client.
* **Impact**: Plugins cannot globally filter incoming headers, intercept requests for early handling, or modify response payloads (e.g., to compress html, strip headers, or inject cookies).

---

## 2. Third-Party Gateway Isolation

### G5: Brand-Scoped Config UI & Settings Context Lock
* **File References**: [PluginController.php](src/Controller/Admin/PluginController.php#L207-L245) and [SettingsRepository.php](src/Repository/SettingsRepository.php#L6-L72)
* **Code Evidence**:
  ```php
  // PluginController.php (L210)
  $currentValues = $settingsRepo->getGroup("plugin.{$slug}");
  
  // PluginController.php (L238)
  $settingsRepo->bulkSet("plugin.{$slug}", $settings);
  ```
* **Architectural Gap**: 
  1. OwnPay uses a single-owner, multi-brand model. Different brands must configure their own independent gateway credentials (e.g. different Stripe accounts or bKash API keys). 
  2. While the database contains an `op_gateway_configs` table scoped by `merchant_id`, the core plugin system completely bypasses it. Plugin and gateway settings are written globally via `SettingsRepository` to the `op_system_settings` table (which has no `merchant_id` context) under the `plugin.{$slug}` group.
  3. No routes, views, or endpoints exist in the core admin dashboard to manage, edit, or configure brand-specific credentials inside the `op_gateway_configs` table.
* **Impact**: Setting up Stripe or bKash keys in the admin dashboard configures them **globally** for all stores/brands, leaking credentials and disabling multi-brand gateway autonomy.

### G6: Inbound Webhook Routing Lock
* **File References**: [WebhookInboundProcessor.php](src/Gateway/WebhookInboundProcessor.php#L167-L177) and [UnifiedWebhookController.php](src/Controller/Webhook/UnifiedWebhookController.php#L80-L125)
* **Code Evidence**:
  ```php
  // WebhookInboundProcessor.php (L169)
  private function routeEvent(string $eventType, array $payload, int $merchantId): void
  {
      match ($eventType) {
          'payment.completed' => $this->handlePaymentCompleted($payload, $merchantId),
          'payment.failed'    => $this->handlePaymentStatusChange($payload, $merchantId, 'failed'),
          'payment.canceled'  => $this->handlePaymentStatusChange($payload, $merchantId, 'cancelled'),
          'refund.completed'  => $this->handleRefundCompleted($payload, $merchantId),
          'dispute.created'   => $this->handleDisputeCreated($payload, $merchantId),
          default             => $this->logger->warning("Unknown event type: {$eventType}"),
      };
  }
  ```
* **Architectural Gap**: 
  1. The validation and processing of inbound webhooks is hardcoded in `WebhookInboundProcessor` to look for a specific header payload format (`X-OP-Signature`, `X-OP-Timestamp`) verified with a single core webhook secret.
  2. `routeEvent()` hardcodes standard payment event keys. 
  3. Gateway adapters (e.g., Stripe, bKash) receive callbacks but are **not** delegated the raw inbound webhook verification. If a gateway receives a direct HTTP POST from the provider (which uses different headers like `Stripe-Signature`), the signature validation and payload extraction fail.
* **Impact**: Webhooks for third-party gateways are completely broken. A custom gateway plugin cannot verify inbound payloads using its own signature algorithms.

---

## 3. Dynamic Feature Addons

### G7: Sidebar HTML Injection Vulnerability
* **File Reference**: [sidebar.twig](templates/admin/layout/sidebar.twig#L232)
* **Code Evidence**:
  ```twig
  {{ hook('admin.menu.register')|raw }}
  ```
* **Architectural Gap**: The admin sidebar supports dynamic menu injection purely by running a raw hook that returns a string.
* **Impact**: 
  1. Plugins must output raw, unescaped HTML strings, creating a severe **Cross-Site Scripting (XSS)** vulnerability if dynamic DB values or unsanitized strings are embedded.
  2. A single unclosed `</div>` or `</li>` tags returned by a plugin will instantly corrupt the entire admin dashboard layout.
  3. No menu builder object is passed, meaning plugins cannot sort, nest, group, or programmatically evaluate permissions for custom links.

### G8: Dead Sandbox Code (Zero Runtime Sandboxing)
* **File References**: [PluginLoader.php](src/Plugin/PluginLoader.php#L134-L204) and [PluginSandbox.php](src/Plugin/PluginSandbox.php)
* **Code Evidence**:
  ```php
  // PluginLoader.php (L187-200)
  require_once $entrypointFile;
  ...
  $className = $this->resolveClassName($manifest);
  ...
  $instance = new $className();
  $instance->register($this->events, $this->container);
  ```
* **Architectural Gap**: The `PluginSandbox` class defines rigorous file-path validation, SQL whitening rules, and dangerous function checkers. However, the loader class (`PluginLoader`) directly executes plugins using `require_once` and instantiates them with normal PHP privileges. It **never** instantiates or triggers `PluginSandbox` methods during execution.
* **Impact**: Plugins run with full un-sandboxed access. They can execute raw SQL, query arbitrary databases, read `.env` secrets, and modify systemic files.

---

## 4. Universal Theme Engine

### G9: Theme Resolution Settings Bypass
* **File Reference**: [TwigFactory.php](src/View/TwigFactory.php#L116)
* **Code Evidence**:
  ```php
  private static function resolveActiveTheme(Container $container): ?string
  {
      // Phase E SettingsService will provide DB lookup.
      // For now: env var or default.
      $theme = getenv('ACTIVE_THEME') ?: 'own-pay';
  ```
* **Architectural Gap**: While the admin dashboard allows the admin to dynamically switch and activate themes (which writes to the database setting `active_theme` via `ThemeController::activate()`), the Twig engine completely ignores the database setting and falls back strictly to the `ACTIVE_THEME` environment variable.
* **Impact**: The theme switcher in the admin UI is a pure illusion; changing active themes in the dashboard has absolutely zero impact on the checkout page rendering.

### G10: Admin Template Override Decoupling Void
* **File Reference**: [AdminPageTrait.php](src/Controller/Admin/AdminPageTrait.php#L16-L46)
* **Code Evidence**:
  ```php
  return Response::html($twig->render($tpl, $data));
  ```
* **Architectural Gap**: The admin view rendering trait renders the hardcoded core template paths directly. No filters are applied to the template name (`$tpl`) or to the view variables array (`$data`).
* **Impact**: Designers cannot create admin templates within custom theme modules. Plugins cannot dynamically alter or customize admin pages.

---

## Remediation Plan Blueprint

To achieve a 100% decoupled, dynamic, and production-ready universal plugin and theme system, the following code revisions must be executed in order:

### Phase 1: Core Lifecycle & Namespace Decoupling
1. **Rearrange Kernel Boot Order**:
   Move `$pluginLoader->boot()` *before* middleware configuration loading in `Kernel::boot()` so that plugins can register listeners for `system.middleware.pipeline`.
2. **Remove Namespace Lock in Router**:
   Modify `Router::dispatch()` to check if the route handler is a FQCN (Fully Qualified Class Name) and instantiate it directly via DI. Only prepend `OwnPay\Controller\` if no explicit namespace separator exists.
   ```php
   // Decoupled dispatcher
   $fqcn = str_contains($controllerName, '\\') ? $controllerName : 'OwnPay\\Controller\\' . $controllerName;
   ```

### Phase 2: Brand-Scoped Gateway Configuration UI
1. **Dynamic Form Fields Renderer**:
   Develop a generic dashboard layout route (e.g. `/admin/gateways/{slug}/settings`) that:
   - Resolves the gateway plugin instance.
   - Fetches the settings array from `$instance->fields()`.
   - Renders a secure, brand-scoped form.
2. **Encrypted Config Persistence**:
   Save form settings securely into the `op_gateway_configs` table scoped by the active `merchant_id`, encrypting critical fields using `FieldEncryptor`.

### Phase 3: Webhook Verification Delegation
1. **Dynamic Webhook Router**:
   Update `UnifiedWebhookController` and `WebhookInboundProcessor` to dynamically delegate raw body signature verification and event parsing to the matching gateway adapter.
   ```php
   // Decoupled webhook processing
   $adapter = $this->bridge->resolveAdapter($gateway);
   $verificationResult = $adapter->verify($rawBody, $headers, $credentials);
   ```

### Phase 4: Safe Sidebar Menu Registrar
1. **Introduce Sidebar Menu Registrar Class**:
   Replace the raw string hook with a structured registry object passed by filter:
   ```php
   // In layout:
   $menu = new AdminMenuRegistrar();
   $menu = $events->applyFilter('admin.menu.register', $menu);
   ```
   Plugins can now use safe APIs: `$menu->addPage('Payments', '/admin/custom', 'plugin-icon')`. The layout Twig renders this safely with automatic escaping.

### Phase 5: DB-backed Theme and Active Sandbox Integration
1. **Database Theme Resolution**:
   Update `TwigFactory::resolveActiveTheme()` to query the `SettingsRepository` dynamically for the `active_theme` setting.
2. **Enable Runtime Sandbox**:
   Integrate `PluginSandbox` checks within the plugin loader pipeline to scan the filesystem and enforce capabilities before execution.
