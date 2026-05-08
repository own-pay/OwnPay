<?php
declare(strict_types=1);

namespace OwnPay\Http;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use RuntimeException;

/**
 * HTTP router with named parameters, middleware groups, and plugin route injection.
 *
 * Route format: METHOD /path/{param} â†’ Controller@method
 * Supports: GET, POST, PUT, DELETE, PATCH
 * Fires 'system.routes.register' hook to allow plugins to register routes.
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
     * }>>
     */
    private array $routes = [];

    /** @var bool Whether routes have been loaded */
    private bool $loaded = false;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    // â”€â”€â”€ Route Registration â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function get(string $pattern, string $handler, string $middleware = 'web'): void
    {
        $this->addRoute('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, string $handler, string $middleware = 'web'): void
    {
        $this->addRoute('POST', $pattern, $handler, $middleware);
    }

    public function put(string $pattern, string $handler, string $middleware = 'web'): void
    {
        $this->addRoute('PUT', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, string $handler, string $middleware = 'web'): void
    {
        $this->addRoute('DELETE', $pattern, $handler, $middleware);
    }

    public function patch(string $pattern, string $handler, string $middleware = 'web'): void
    {
        $this->addRoute('PATCH', $pattern, $handler, $middleware);
    }

    /**
     * Register a route for any HTTP method.
     */
    public function any(string $pattern, string $handler, string $middleware = 'web'): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $this->addRoute($method, $pattern, $handler, $middleware);
        }
    }

    /**
     * Internal route registration.
     */
    private function addRoute(string $method, string $pattern, string $handler, string $middleware): void
    {
        $paramNames = [];
        // Convert {param} to regex capture groups
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $m) use (&$paramNames): string {
            $paramNames[] = $m[1];
            return '([a-zA-Z0-9_\-]+)';
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

    // â”€â”€â”€ Route Loading â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Load route files and fire plugin hook.
     */
    public function loadRoutes(): void
    {
        if ($this->loaded) {
            return;
        }

        $configDir = $this->container->get('config.app')['paths']['config'] ?? dirname(__DIR__, 2) . '/config';

        // Load web routes
        $webRoutes = $configDir . '/routes/web.php';
        if (is_file($webRoutes)) {
            $fn = require $webRoutes;
            if (is_callable($fn)) {
                $fn($this);
            }
        }

        // Load API routes
        $apiRoutes = $configDir . '/routes/api.php';
        if (is_file($apiRoutes)) {
            $fn = require $apiRoutes;
            if (is_callable($fn)) {
                $fn($this);
            }
        }

        // Allow plugins to register routes
        if ($this->container->has(EventManager::class)) {
            /** @var EventManager $events */
            $events = $this->container->get(EventManager::class);
            $events->doAction('system.routes.register', $this);
        }

        $this->loaded = true;
    }

    // â”€â”€â”€ Dispatching â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Match and dispatch a request.
     *
     * @return array{handler: string, params: array<string, string>, middleware: string}|null
     */
    public function match(Request $request): ?array
    {
        $method = $request->method();
        $path   = $request->path();

        // Normalize: strip trailing slash (except root)
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        if (!isset($this->routes[$method])) {
            return null;
        }

        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches); // Remove full match

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
     * Dispatch a matched route â€” instantiate controller and call method.
     *
     * @param string $handler Format: 'Namespace\\Controller@method'
     * @param Request $request
     * @return Response
     */
    public function dispatch(string $handler, Request $request): Response
    {
        if (!str_contains($handler, '@')) {
            throw new RuntimeException("Invalid handler format: [{$handler}]. Expected 'Controller@method'.");
        }

        [$controllerName, $methodName] = explode('@', $handler, 2);
        $fqcn = 'OwnPay\\Controller\\' . $controllerName;

        if (!class_exists($fqcn)) {
            throw new RuntimeException("Controller class [{$fqcn}] not found.");
        }

        $controller = $this->container->get($fqcn);

        if (!method_exists($controller, $methodName)) {
            throw new RuntimeException("Method [{$methodName}] not found on controller [{$fqcn}].");
        }

        $result = $controller->$methodName($request);

        // If controller returns a Response, use it directly
        if ($result instanceof Response) {
            return $result;
        }

        // If array returned, wrap as JSON
        if (is_array($result)) {
            return Response::json($result);
        }

        // If string returned, wrap as HTML
        if (is_string($result)) {
            return Response::html($result);
        }

        throw new RuntimeException("Controller [{$fqcn}@{$methodName}] must return Response, array, or string.");
    }

    // â”€â”€â”€ Introspection â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Get all registered routes (for debugging / documentation).
     *
     * @return array<string, array<int, array{pattern: string, handler: string, middleware: string}>>
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
     * Count total registered routes.
     */
    public function count(): int
    {
        $total = 0;
        foreach ($this->routes as $routes) {
            $total += count($routes);
        }
        return $total;
    }
}
