<?php

declare(strict_types=1);

namespace OwnPay\Http;

/**
 * Lightweight regex-based HTTP router.
 *
 * Supports route parameters, HTTP method matching, and middleware.
 * No external dependencies.
 *
 * Usage:
 *   $router = new Router();
 *   $router->get('/v1/transactions/{id}', [TransactionController::class, 'show']);
 *   $router->post('/v1/payments', [PaymentController::class, 'create']);
 *   $router->dispatch();
 */
final class Router
{
    /** @var array<array{method: string, pattern: string, handler: callable|array, middleware: array}> */
    private array $routes = [];

    /** @var array<callable> */
    private array $globalMiddleware = [];

    /**
     * Add global middleware (runs on every route).
     */
    public function use(callable $middleware): self
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    // ─── Plugin Route Registration ─────────────────────────────────────

    /**
     * Register a route owned by a plugin.
     *
     * Plugin routes are prefixed with /plugins/{slug}/ automatically.
     * They are dispatched after core routes.
     *
     * @param string $method   HTTP method (GET, POST, PUT, DELETE)
     * @param string $slug     Plugin slug (used as route namespace)
     * @param string $path     Route path (e.g. "/webhook", "/api/status")
     * @param callable|array $handler  Route handler
     * @param array  $middleware       Optional middleware callables
     */
    public function registerPluginRoute(
        string $method,
        string $slug,
        string $path,
        callable|array $handler,
        array $middleware = [],
    ): self {
        $fullPath = '/plugins/' . $slug . '/' . ltrim($path, '/');
        $this->routes[] = [
            'method'     => strtoupper($method),
            'pattern'    => $fullPath,
            'handler'    => $handler,
            'middleware'  => $middleware,
            'plugin'     => $slug,
        ];
        return $this;
    }

    /**
     * Get all routes registered by plugins.
     *
     * @return array<array{method: string, pattern: string, plugin: string}>
     */
    public function getPluginRoutes(): array
    {
        return array_values(array_filter(
            $this->routes,
            fn(array $r): bool => isset($r['plugin']),
        ));
    }

    // ─── Route Registration ──────────────────────────────────────────

    public function get(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    private function addRoute(string $method, string $path, callable|array $handler, array $middleware): self
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $path,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
        return $this;
    }

    // ─── Dispatch ────────────────────────────────────────────────────

    /**
     * Match the current request against registered routes and dispatch.
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $this->getRequestUri();

        // Handle CORS preflight
        if ($method === 'OPTIONS') {
            JsonResponse::cors();
            http_response_code(204);
            return;
        }

        $matchedMethods = [];

        foreach ($this->routes as $route) {
            $params = $this->matchRoute($route['pattern'], $uri);

            if ($params !== null) {
                $matchedMethods[] = $route['method'];

                if ($route['method'] === $method) {
                    // Run global middleware
                    foreach ($this->globalMiddleware as $mw) {
                        $result = $mw();
                        if ($result === false) {
                            return; // Middleware halted the request
                        }
                    }

                    // Run route-specific middleware
                    foreach ($route['middleware'] as $mw) {
                        $result = $mw();
                        if ($result === false) {
                            return;
                        }
                    }

                    // Invoke handler
                    $this->invokeHandler($route['handler'], $params);
                    return;
                }
            }
        }

        // Route matched but wrong method
        if (!empty($matchedMethods)) {
            JsonResponse::error(
                'METHOD_NOT_ALLOWED',
                "Method {$method} is not allowed. Allowed: " . implode(', ', array_unique($matchedMethods)),
                405
            );
            return;
        }

        // No route matched at all
        JsonResponse::error(
            'NOT_FOUND',
            "No endpoint matches: {$method} {$uri}",
            404
        );
    }

    // ─── Internals ───────────────────────────────────────────────────

    /**
     * Match a route pattern against a URI.
     * Returns parameter array on match, null on no match.
     *
     * Pattern: /v1/transactions/{id}
     * URI:     /v1/transactions/abc-123
     * Result:  ['id' => 'abc-123']
     */
    private function matchRoute(string $pattern, string $uri): ?array
    {
        // Convert route params {name} to named regex groups
        $regex = preg_replace(
            '/\{([a-zA-Z_]+)\}/',
            '(?P<$1>[a-zA-Z0-9_-]+)',
            $pattern
        );

        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            // Filter to named groups only
            return array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);
        }

        return null;
    }

    /**
     * Invoke a route handler.
     *
     * @param callable|array $handler [ClassName, methodName] or callable
     * @param array $params Route parameters
     */
    private function invokeHandler(callable|array $handler, array $params): void
    {
        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;

            if (is_string($class)) {
                $instance = new $class();
                $instance->$method($params);
            } else {
                $class->$method($params);
            }
        } else {
            ($handler)($params);
        }
    }

    /**
     * Get the clean request URI path (without query string).
     */
    private function getRequestUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = parse_url($uri, PHP_URL_PATH) ?: '/';
        return '/' . trim($uri, '/');
    }
}
