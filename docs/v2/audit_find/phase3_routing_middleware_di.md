# Phase 3 Audit: Routing, Middleware Pipeline, & DI Container

This report presents findings from a forensic architectural audit of the routing, middleware, and dependency injection systems in OwnPay.

---

## 1. Routing System & Dispatching (`Router.php`, `config/routes/`)

### 1.1 Dynamic Admin Login Route Performance Tax
- **Location**: [web.php:L21-L32](file:///c:/laragon/www/ownpay/config/routes/web.php#L21-L32)
- **Finding**: The admin login URL slug is dynamic, resolved from database settings:
  ```php
  $slug = $settingsRepo->get('landing', 'admin_login_slug', 'login');
  ```
  Since `loadRoutes()` is called on every request boot sequence (`Kernel::boot()`), this triggers a database query on **every single request** (even public landing page or webhook calls) just to check the admin login slug.
- **Risk**: Unnecessary DB read overhead on critical public/webhook hot paths.
- **Remediation**: Cache the admin login slug using `CacheInterface` or hardcode `/login` with an IP allowlist/2FA instead of dynamic path obfuscation.

### 1.2 Route Param Regex Group Capture Limit
- **Location**: [Router.php:L84-L87](file:///c:/laragon/www/ownpay/src/Http/Router.php#L84-L87)
- **Finding**: Route parameter regex matching uses a generic character set:
  ```php
  return '([a-zA-Z0-9_\-\.@\+]+)';
  ```
  This matches standard characters but blocks international characters, spaces, or specific URL-encoded characters in route parameters (e.g. customer identifiers or filenames).
- **Risk**: Unintentional routing failures (404) for parameters containing special characters.
- **Remediation**: Standardize matching regex per parameter type or widen to support URL-safe characters.

---

## 2. Middleware Pipeline (`Kernel.php`, `config/middleware.php`)

### 2.1 Middleware Autowiring Bypass
- **Location**: [Kernel.php:L262](file:///c:/laragon/www/ownpay/src/Kernel.php#L262)
- **Finding**: The middleware pipeline runner instantiates middleware classes directly using:
  ```php
  $middleware = new $middlewareClass($this->container);
  ```
- **Risk**: Bypasses the DI container's autowiring mechanism. Middlewares are forced to accept the `Container` and manually resolve dependencies via `$this->container->get()`. This violates constructor dependency injection patterns and makes testing harder.
- **Remediation**: Resolve middleware instances through the container:
  ```php
  $middleware = $this->container->get($middlewareClass);
  ```

### 2.2 Silent Security Skip in Production
- **Location**: [Kernel.php:L258-L261](file:///c:/laragon/www/ownpay/src/Kernel.php#L258-L261)
- **Finding**: If a middleware class is missing/renamed, the runner skips it silently:
  ```php
  if (!class_exists($middlewareClass)) {
      return $pipeline($req);
  }
  ```
- **Risk**: Critical security middleware (e.g. `CsrfMiddleware`, `JwtAuthMiddleware`) could be bypassed without raising errors if files are deleted or misspelled.
- **Remediation**: Allow skipping only in debug/development mode. In production, throw a fatal exception if a configured middleware class is missing.

---

## 3. DI Container & Autowiring (`Container.php`, `config/services.php`)

### 3.1 Repository Autowiring Shortcut Assumption
- **Location**: [Container.php:L123-L128](file:///c:/laragon/www/ownpay/src/Container.php#L123-L128)
- **Finding**: The container hardcodes repository instantiation if the class name ends in "Repository":
  ```php
  if (class_exists($abstract) && str_ends_with($abstract, 'Repository') && is_subclass_of($abstract, \OwnPay\Repository\BaseRepository::class)) {
      $db = $this->get(\OwnPay\Core\Database::class);
      $instance = new $abstract($db);
      $this->instances[$abstract] = $instance;
      return $instance;
  }
  ```
- **Risk**: Assumes all repository classes accept exactly one constructor parameter (`Database`). If a repository is refactored to require additional services (e.g., Cache, EventManager), the shortcut bypasses the reflection-based `autowire()` method and crashes with a parameter mismatch.
- **Remediation**: Remove the hardcoded repository check and let the generic `autowire()` reflection method handle it.
