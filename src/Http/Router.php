<?php
declare(strict_types=1);

namespace OwnPay\Http;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use RuntimeException;

/**
 * HTTP router with named parameters, middleware groups, and plugin route injection.
 *
 * Route format: METHOD /path/{param} -> Controller@method
 * Supports standard HTTP verbs (GET, POST, PUT, DELETE, PATCH).
 *
 * Hooks:
 * - Action 'system.routes.register': Fired to allow plugin components to register custom routes dynamically.
 */
final class Router
{
    private Container $container;

    /**
     * @var array<string, array<int, array{
     *     pattern: string,
     *     regex: string,
     *     paramNames: string[],
     *     handler: string,
     *     middleware: string
     * }>> Registered routes dictionary grouped by HTTP request method.
     */
    private array $routes = [];

    /**
     * @var bool Flag tracking whether system/plugin routes have been initialized.
     */
    private bool $loaded = false;

    /**
     * Initialize the Router service.
     *
     * @param \OwnPay\Container $container The application's DI container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    // Route Registration Operations

    /**
     * Register a GET route.
     *
     * @param string $pattern Route URL pattern with placeholder brackets (e.g. '/checkout/{id}').
     * @param string $handler Controller handler method string reference (e.g., 'CheckoutController@show').
     * @param string $middleware Middleware stack group name to apply.
     * @return void
     */
    public function get(string $pattern, string $handler, string $middleware = 'web'): void
    {
        $this->addRoute('GET', $pattern, $handler, $middleware);
    }

    /**
     * Register a POST route.
     *
     * @param string $pattern Route URL pattern.
     * @param string $handler Controller handler.
     * @param string $middleware Middleware stack group name.
     * @return void
     */
    public function post(string $pattern, string $handler, string $middleware = 'web'): void
    {
        $this->addRoute('POST', $pattern, $handler, $middleware);
    }

    /**
     * Register a PUT route.
     *
     * @param string $pattern Route URL pattern.
     * @param string $handler Controller handler.
     * @param string $middleware Middleware stack group name.
     * @return void
     */
    public function put(string $pattern, string $handler, string $middleware = 'web'): void
    {
        $this->addRoute('PUT', $pattern, $handler, $middleware);
    }

    /**
     * Register a DELETE route.
     *
     * @param string $pattern Route URL pattern.
     * @param string $handler Controller handler.
     * @param string $middleware Middleware stack group name.
     * @return void
     */
    public function delete(string $pattern, string $handler, string $middleware = 'web'): void
    {
        $this->addRoute('DELETE', $pattern, $handler, $middleware);
    }

    /**
     * Register a PATCH route.
     *
     * @param string $pattern Route URL pattern.
     * @param string $handler Controller handler.
     * @param string $middleware Middleware stack group name.
     * @return void
     */
    public function patch(string $pattern, string $handler, string $middleware = 'web'): void
    {
        $this->addRoute('PATCH', $pattern, $handler, $middleware);
    }

    /**
     * Register a route matching any of the supported standard HTTP verbs.
     *
     * @param string $pattern Route URL pattern.
     * @param string $handler Controller handler.
     * @param string $middleware Middleware stack group name.
     * @return void
     */
    public function any(string $pattern, string $handler, string $middleware = 'web'): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $this->addRoute($method, $pattern, $handler, $middleware);
        }
    }

    /**
     * Compile and register a route.
     *
     * Converts named parameters in brackets (e.g. `{id}`) to regex groups to prevent parameter injections.
     *
     * @param string $method HTTP verb.
     * @param string $pattern Route URL pattern.
     * @param string $handler Controller handler string.
     * @param string $middleware Middleware stack group name.
     * @return void
     */
    private function addRoute(string $method, string $pattern, string $handler, string $middleware): void
    {
        $paramNames = [];
        // Convert placeholder variables into safe regular expression capture groups.
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $m) use (&$paramNames): string {
            $paramNames[] = $m[1];
            if ($m[1] === 'identifier') {
                return '([a-zA-Z0-9_\-\.\+\@\%]+)';
            }
            // BUG-023: Prevent route-based injection by constraining character set.
            return '([a-zA-Z0-9_\-\.]+)';
        }, $pattern);

        $regex = '#^' . $regex . '$#';

        $this->routes[$method][] = [
            'pattern'    => $pattern,
            'regex'      => $regex,
            'paramNames' => $paramNames,
            'handler'    => $handler,
            'middleware'  => $middleware,
        ];
    }

    // Route Loading and Registration Hook

    /**
     * Load core framework routing files and trigger plugin routing registration hook.
     *
     * @return void
     */
    public function loadRoutes(): void
    {
        if ($this->loaded) {
            return;
        }

        $configApp = $this->container->get('config.app');
        $configDir = null;
        if (is_array($configApp)) {
            $paths = $configApp['paths'] ?? null;
            if (is_array($paths) && isset($paths['config']) && is_string($paths['config'])) {
                $configDir = $paths['config'];
            }
        }
        if ($configDir === null) {
            $configDir = dirname(__DIR__, 2) . '/config';
        }

        // Load administration and public web routes.
        $webRoutes = $configDir . '/routes/web.php';
        if (is_file($webRoutes)) {
            $fn = require $webRoutes;
            if (is_callable($fn)) {
                $fn($this);
            }
        }

        // Load public and private API routes.
        $apiRoutes = $configDir . '/routes/api.php';
        if (is_file($apiRoutes)) {
            $fn = require $apiRoutes;
            if (is_callable($fn)) {
                $fn($this);
            }
        }

        // Fire hook allowing loaded plugins to register custom routing paths.
        if ($this->container->has(EventManager::class)) {
            /** @var EventManager $events */
            $events = $this->container->get(EventManager::class);
            $events->doAction('system.routes.register', $this);
        }

        $installLock = dirname(__DIR__, 2) . '/storage/.installed';
        if (file_exists($installLock) && $this->container->has(\OwnPay\Plugin\PluginRegistry::class)) {
            /** @var \OwnPay\Plugin\PluginRegistry $registry */
            $registry = $this->container->get(\OwnPay\Plugin\PluginRegistry::class);
            foreach ($registry->getLoaded() as $slug => $instance) {
                $manifest = $registry->getManifest($slug);
                if ($manifest !== null && !empty($manifest->routes)) {
                    foreach ($manifest->routes as $routeDef) {
                        if (count($routeDef) >= 3) {
                            $method = $routeDef[0] ?? null;
                            $pattern = $routeDef[1] ?? null;
                            $action = $routeDef[2] ?? null;
                            // Optional 4th element selects the middleware group, letting a plugin declare an authenticated route (e.g. 'web' for an admin page) instead of being forced public. Defaults to the public API group.
                            $middleware = (isset($routeDef[3]) && is_string($routeDef[3]) && $routeDef[3] !== '')
                                ? $routeDef[3]
                                : 'api-public';
                            if (is_string($method) && is_string($pattern) && is_string($action)) {
                                // Format handler to point directly to FQCN of the plugin class
                                $handler = $manifest->getFullyQualifiedClassName() . '@' . $action;
                                $this->addRoute(strtoupper($method), $pattern, $handler, $middleware);
                            }
                        }
                    }
                }
            }
        }

        $this->loaded = true;
    }

    // Request Matching and Dispatching

    /**
     * Match the incoming HTTP request against the registered routes.
     *
     * Normalizes the path structure and extracts path parameter variables.
     *
     * @param \OwnPay\Http\Request $request The incoming HTTP request.
     * @return array{handler: string, params: array<string, string>, middleware: string}|null Route details or null if unmatched.
     */
    public function match(Request $request): ?array
    {
        $method = $request->method();
        $path   = $request->path();

        // Normalize path by stripping trailing slashes except for the root route.
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        if (!isset($this->routes[$method])) {
            return null;
        }

        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                // Remove full match from regex array to leave only captured named parameters.
                array_shift($matches);

                $params = [];
                foreach ($route['paramNames'] as $i => $name) {
                    $params[$name] = $matches[$i] ?? '';
                }

                return [
                    'handler'    => $route['handler'],
                    'params'     => $params,
                    'middleware'  => $route['middleware'],
                ];
            }
        }

        return null;
    }

    /**
     * Dispatch the request handler by instantiating the controller and executing the targeted method.
     *
     * Resolves core controller names under 'OwnPay\Controller\' or maps fully-qualified class names
     * directly for plugin controllers.
     *
     * @param string $handler Controller handler reference string (e.g. 'Admin\DashboardController@index').
     * @param \OwnPay\Http\Request $request The incoming HTTP request.
     * @return \OwnPay\Http\Response HTTP response object to return.
     * @throws \RuntimeException If the handler is invalid, controller is missing, or method does not exist.
     */
    public function dispatch(string $handler, Request $request): Response
    {
        if (!str_contains($handler, '@')) {
            throw new RuntimeException("Invalid handler format: [{$handler}]. Expected 'Controller@method'.");
        }

        [$controllerName, $methodName] = explode('@', $handler, 2);

        // AUD-G2: Support fully-qualified class names for plugin-based controllers.
        // Determines if a class is a FQCN by checking if its root namespace is a non-core namespace.
        $coreSubNamespaces = ['Admin', 'Api', 'Checkout', 'Page', 'Webhook', 'Install'];
        $firstSegment = explode('\\', $controllerName)[0];
        $isFqcn = str_contains($controllerName, '\\')
            && !in_array($firstSegment, $coreSubNamespaces, true)
            && class_exists($controllerName);
        $fqcn = $isFqcn ? $controllerName : 'OwnPay\\Controller\\' . $controllerName;

        if (!class_exists($fqcn)) {
            throw new RuntimeException("Controller class [{$fqcn}] not found.");
        }

        $controller = $this->container->get($fqcn);
        if (!is_object($controller)) {
            throw new RuntimeException("Resolved controller is not an object.");
        }

        if (!method_exists($controller, $methodName)) {
            throw new RuntimeException("Method [{$methodName}] not found on controller [{$fqcn}].");
        }

        $result = $controller->$methodName($request);

        // Directly return response objects returned by the controller.
        if ($result instanceof Response) {
            return $result;
        }

        // Auto-wrap array payloads returned by controllers into JSON response objects.
        if (is_array($result)) {
            $resultChecked = [];
            foreach ($result as $k => $v) {
                $resultChecked[(string)$k] = $v;
            }
            return Response::json($resultChecked);
        }

        // Auto-wrap plain string values into HTML response objects.
        if (is_string($result)) {
            return Response::html($result);
        }

        throw new RuntimeException("Controller [{$fqcn}@{$methodName}] must return Response, array, or string.");
    }

    // Router Introspection and Utility Methods

    /**
     * Retrieve all registered routes for debugging and developer diagnostics.
     *
     * @return array<string, array<int, array{pattern: string, handler: string, middleware: string}>> Registry dictionary.
     */
    public function getRoutes(): array
    {
        $result = [];
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                $result[$method][] = [
                    'pattern'    => $route['pattern'],
                    'handler'    => $route['handler'],
                    'middleware'  => $route['middleware'],
                ];
            }
        }
        return $result;
    }

    /**
     * Get the total count of registered routes across all HTTP verbs.
     *
     * @return int Route count.
     */
    public function count(): int
    {
        $total = 0;
        foreach ($this->routes as $routes) {
            $total += count($routes);
        }
        return $total;
    }

    /**
     * Get the underlying Dependency Injection container.
     *
     * Typically used within route configuration files to resolve settings or plugins.
     *
     * @return \OwnPay\Container The DI container.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }
}
