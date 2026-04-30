<?php
declare(strict_types=1);

namespace OwnPay;

use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Http\Router;

/**
 * Application kernel — the central orchestrator.
 *
 * Boot sequence:
 *   1. Load .env (phpdotenv)
 *   2. Build DI container (config/services.php)
 *   3. Set timezone
 *   4. Load middleware pipeline (config/middleware.php)
 *   5. Boot plugins (PluginLoader::boot)
 *   6. Fire 'system.boot' hook
 *   7. Load routes (config/routes/*.php + plugin routes)
 *   8. Match request → run middleware → dispatch controller
 *   9. Send response
 *  10. Fire 'system.shutdown' hook
 */
final class Kernel
{
    private Container $container;

    /** @var array<string, array<int, string>> Middleware stacks */
    private array $middlewareConfig = [];

    public function __construct()
    {
        $this->container = new Container();
    }

    /**
     * Boot the application and handle the incoming request.
     */
    public function handle(): void
    {
        try {
            $this->boot();

            $request = Request::capture();
            $response = $this->processRequest($request);
            $response->send();

            // Shutdown hook
            /** @var EventManager $events */
            $events = $this->container->get(EventManager::class);
            $events->doAction('system.shutdown');

        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Boot sequence: .env → container → timezone → middleware → plugins → routes.
     */
    private function boot(): void
    {
        $rootDir = dirname(__DIR__);

        // 1. Load .env
        if (class_exists(\Dotenv\Dotenv::class)) {
            $dotenv = \Dotenv\Dotenv::createImmutable($rootDir);
            $dotenv->safeLoad();
        }

        // 2. Register DI bindings
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(self::class, $this);

        $registerServices = require $rootDir . '/config/services.php';
        $registerServices($this->container);

        // 3. Set timezone
        $appConfig = $this->container->get('config.app');
        date_default_timezone_set($appConfig['timezone']);

        // 4. Load middleware config
        $this->middlewareConfig = require $rootDir . '/config/middleware.php';

        // Allow plugins to modify middleware pipeline
        /** @var EventManager $events */
        $events = $this->container->get(EventManager::class);
        $this->middlewareConfig = $events->applyFilter(
            'system.middleware.pipeline',
            $this->middlewareConfig
        );

        // 5. Boot plugins
        if ($this->container->has(\OwnPay\Plugin\PluginLoader::class)) {
            try {
                /** @var \OwnPay\Plugin\PluginLoader $pluginLoader */
                $pluginLoader = $this->container->get(\OwnPay\Plugin\PluginLoader::class);
                $pluginLoader->boot();
            } catch (\Throwable $e) {
                // Plugin boot failure must not crash the system
                error_log('[OwnPay] Plugin boot error: ' . $e->getMessage());
            }
        }

        // 6. Fire system.boot
        $events->doAction('system.boot');

        // 7. Load routes
        /** @var Router $router */
        $router = $this->container->get(Router::class);
        $router->loadRoutes();
    }

    /**
     * Process a request: match → middleware → dispatch.
     */
    private function processRequest(Request $request): Response
    {
        /** @var Router $router */
        $router = $this->container->get(Router::class);

        // Check install lock
        if (!$this->isInstalled() && !str_starts_with($request->path(), '/install')) {
            return Response::redirect('/install');
        }

        // Match route
        $match = $router->match($request);

        if ($match === null) {
            return $this->notFoundResponse($request);
        }

        // Set route params on request
        $request->setRouteParams($match['params']);

        // Run middleware pipeline
        $middlewareGroup = $match['middleware'];
        $response = $this->runMiddleware($request, $middlewareGroup, static function (Request $req) use ($router, $match): Response {
            return $router->dispatch($match['handler'], $req);
        });

        return $response;
    }

    /**
     * Execute middleware pipeline.
     *
     * @param Request  $request
     * @param string   $group     Middleware group name
     * @param callable $core      The core handler (controller dispatch)
     * @return Response
     */
    private function runMiddleware(Request $request, string $group, callable $core): Response
    {
        // Collect middleware: global + group-specific
        $stack = array_merge(
            $this->middlewareConfig['global'] ?? [],
            $this->middlewareConfig[$group] ?? []
        );

        // Remove duplicates while preserving order
        $stack = array_values(array_unique($stack));

        // Build pipeline: wrap core handler inside middleware layers (inside-out)
        $pipeline = $core;

        foreach (array_reverse($stack) as $middlewareClass) {
            $pipeline = function (Request $req) use ($middlewareClass, $pipeline): Response {
                if (!class_exists($middlewareClass)) {
                    // Skip missing middleware gracefully during development
                    return $pipeline($req);
                }
                $middleware = new $middlewareClass($this->container);

                if (!method_exists($middleware, 'handle')) {
                    return $pipeline($req);
                }

                return $middleware->handle($req, $pipeline);
            };
        }

        return $pipeline($request);
    }

    /**
     * Check if application is installed.
     */
    private function isInstalled(): bool
    {
        $installLock = dirname(__DIR__) . '/storage/.installed';
        return file_exists($installLock);
    }

    /**
     * Generate 404 response.
     */
    private function notFoundResponse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return Response::json([
                'success' => false,
                'message' => 'Not Found',
            ], 404);
        }

        // Try Twig 404 template
        if ($this->container->has(\Twig\Environment::class)) {
            try {
                /** @var \Twig\Environment $twig */
                $twig = $this->container->get(\Twig\Environment::class);
                $html = $twig->render('error/404.twig', [
                    'path' => $request->path(),
                ]);
                return Response::html($html, 404);
            } catch (\Throwable) {
                // Template not found, fall through
            }
        }

        return Response::html('<h1>404 Not Found</h1>', 404);
    }

    /**
     * Handle uncaught exceptions.
     */
    private function handleException(\Throwable $e): void
    {
        $debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

        error_log(sprintf(
            '[OwnPay] Fatal: %s in %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');

        if ($debug) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Internal Server Error',
            ]);
        }
    }

    /**
     * Get the DI container (for testing/debugging).
     */
    public function getContainer(): Container
    {
        return $this->container;
    }
}
