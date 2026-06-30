<?php
declare(strict_types=1);

namespace OwnPay;

use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Http\Router;
use OwnPay\View\ErrorPageRenderer;

/**
 * Application kernel - the central orchestrator.
 *
 * Boot sequence:
 *   1. Load .env (phpdotenv)
 *   2. Build DI container (config/services.php)
 *   3. Set timezone
 *   4. Boot plugins (PluginLoader::boot) - BEFORE middleware so plugins can
 *      register middleware via the 'system.middleware.pipeline' filter (AUD-G1)
 *   5. Load middleware pipeline (config/middleware.php) + apply plugin filter
 *   6. Fire 'system.boot' hook
 *   7. Load routes (config/routes/*.php + plugin routes)
 *   8. Match request - run middleware - dispatch controller
 *   9. Send response
 *  10. Fire 'system.shutdown' hook
 */
final class Kernel
{
    private Container $container;

    /** @var array<string, array<int, string>> Middleware stacks */
    private array $middlewareConfig = [];

    /**
     * Lazily-created renderer for last-resort error pages. Instantiated only in
     * failure paths and deliberately not resolved from the container, so error
     * pages still render when the container/boot itself is broken.
     */
    private ?ErrorPageRenderer $errorPages = null;

    public function __construct()
    {
        $this->container = new Container();
    }

    private function errorPages(): ErrorPageRenderer
    {
        return $this->errorPages ??= new ErrorPageRenderer();
    }

    /**
     * Boot the application and handle the incoming request.
     */
    public function handle(): void
    {
        try {
            $this->boot();

            $request = Request::capture();

            $events = $this->container->get(EventManager::class);
            if (!$events instanceof EventManager) {
                throw new \RuntimeException("EventManager not found");
            }
            $filteredRequest = $events->applyFilter('system.request', $request);
            if ($filteredRequest instanceof Request) {
                $request = $filteredRequest;
            }

            $response = $this->processRequest($request);

            $filteredResponse = $events->applyFilter('system.response', $response, $request);
            if ($filteredResponse instanceof Response) {
                $response = $filteredResponse;
            }

            $response->send();

            // Shutdown hook
            $events->doAction('system.shutdown');

        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Boot sequence: .env -> container -> timezone -> middleware -> plugins -> routes.
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

        try {
            if ($this->container->has(\OwnPay\Repository\SettingsRepository::class)) {
                $settingsRepo = $this->container->get(\OwnPay\Repository\SettingsRepository::class);
                if ($settingsRepo instanceof \OwnPay\Repository\SettingsRepository) {
                    \OwnPay\Service\System\EnvironmentService::boot($settingsRepo);
                }
            }
        } catch (\Throwable) {
        }

        try {
            if ($this->container->has(\OwnPay\Core\Database::class)) {
                $db = $this->container->get(\OwnPay\Core\Database::class);
                if ($db instanceof \OwnPay\Core\Database) {
                    if ($this->container->has(EventManager::class)) {
                        $events = $this->container->get(EventManager::class);
                        if ($events instanceof EventManager) {
                            $db->setEventManager($events);
                        }
                    }
                    if ($this->container->has(\OwnPay\Plugin\PluginRegistry::class)) {
                        $registry = $this->container->get(\OwnPay\Plugin\PluginRegistry::class);
                        if ($registry instanceof \OwnPay\Plugin\PluginRegistry) {
                            $db->setPluginRegistry($registry);
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Graceful - hooks/sandbox just won't enforce/fire
        }

        // 3. Set timezone
        $appConfig = $this->container->get('config.app');
        $tz = 'UTC';
        if (is_array($appConfig) && isset($appConfig['timezone']) && is_string($appConfig['timezone'])) {
            $tz = $appConfig['timezone'];
        }
        date_default_timezone_set($tz);

        if ($this->container->has(\OwnPay\Plugin\PluginLoader::class)) {
            try {
                /** @var \OwnPay\Plugin\PluginLoader $pluginLoader */
                $pluginLoader = $this->container->get(\OwnPay\Plugin\PluginLoader::class);
                $pluginLoader->boot();
            } catch (\Throwable $e) {
                $this->safeLog('Plugin boot error: ' . $e->getMessage(), 'error');
            }
        }

        // 5. Load middleware config
        $this->middlewareConfig = require $rootDir . '/config/middleware.php';

        $events = $this->container->get(EventManager::class);
        if (!$events instanceof EventManager) {
            throw new \RuntimeException("EventManager not found");
        }
        $filteredPipeline = $events->applyFilter(
            'system.middleware.pipeline',
            $this->middlewareConfig
        );
        if (is_array($filteredPipeline)) {
            $validatedConfig = [];
            foreach ($filteredPipeline as $group => $stack) {
                if (is_string($group) && is_array($stack)) {
                    $groupStack = [];
                    foreach ($stack as $mw) {
                        if (is_string($mw)) {
                            $groupStack[] = $mw;
                        }
                    }
                    $validatedConfig[$group] = $groupStack;
                }
            }
            $this->middlewareConfig = $validatedConfig;
        }

        $requiredAdmin = [
            \OwnPay\Middleware\SessionMiddleware::class,
            \OwnPay\Middleware\CsrfMiddleware::class,
            \OwnPay\Middleware\PermissionMiddleware::class,
        ];
        foreach ($requiredAdmin as $mw) {
            if (!in_array($mw, $this->middlewareConfig['admin'] ?? [], true)) {
                $this->middlewareConfig['admin'][] = $mw;
                $this->safeLog("Security middleware {$mw} was removed by plugin - re-added", 'warning');
            }
        }

        // 6. Fire system.boot
        $events->doAction('system.boot');

        // 7. Load routes
        if ($this->isInstalled()) {
            $jwtSecretRaw = getenv('JWT_SECRET') ?: ($_ENV['JWT_SECRET'] ?? '');
            $jwtSecret = is_string($jwtSecretRaw) ? $jwtSecretRaw : '';
            if (strlen($jwtSecret) < 32) {
                throw new \RuntimeException(
                    'JWT_SECRET must be set in .env and be at least 32 characters. '
                    . 'Generate with: php -r "echo bin2hex(random_bytes(32));"'
                );
            }

            $appKeyRaw = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? '');
            $appKey = is_string($appKeyRaw) ? $appKeyRaw : '';
            if (strlen($appKey) < 32) {
                throw new \RuntimeException(
                    'APP_KEY must be at least 32 characters. '
                    . 'Generate with: php -r "echo base64_encode(random_bytes(32));"'
                );
            }
            $encKeyRaw = getenv('ENCRYPTION_KEY') ?: ($_ENV['ENCRYPTION_KEY'] ?? '');
            $encKey = is_string($encKeyRaw) ? $encKeyRaw : '';
            if (strlen($encKey) < 32) {
                throw new \RuntimeException(
                    'ENCRYPTION_KEY must be at least 32 characters. '
                    . 'Generate with: php -r "echo base64_encode(random_bytes(32));"'
                );
            }
        }

        /** @var Router $router */
        $router = $this->container->get(Router::class);
        $router->loadRoutes();
    }

    /**
     * Process a request: match middleware dispatch.
     */
    private function processRequest(Request $request): Response
    {
        /** @var Router $router */
        $router = $this->container->get(Router::class);

        // Check install lock
        if (!$this->isInstalled() && !str_starts_with($request->path(), '/install')) {
            return Response::redirect('/install');
        }

        $maintenanceLock = dirname(__DIR__) . '/storage/.maintenance';
        $pathAllowed = \OwnPay\Middleware\MaintenanceMiddleware::isPassthroughPath($request->path());
        if (file_exists($maintenanceLock) && !$pathAllowed) {
            $info = json_decode(file_get_contents($maintenanceLock) ?: '{}', true);
            if (!is_array($info)) {
                $info = [];
            }
            $retryAfter = 300;
            if (isset($info['retry_after']) && (is_int($info['retry_after']) || is_string($info['retry_after']) || is_numeric($info['retry_after']))) {
                $retryAfter = (int) $info['retry_after'];
            }
            $reason = 'System maintenance in progress. Please try again shortly.';
            if (isset($info['reason']) && is_string($info['reason'])) {
                $reason = $info['reason'];
            }
            if ($request->expectsJson()) {
                return Response::maintenance($reason, $retryAfter);
            }
            http_response_code(503);
            header("Retry-After: {$retryAfter}");
            // Try to render a Twig 503 template if available
            if ($this->container->has(\Twig\Environment::class)) {
                try {
                    $twig = $this->container->get(\Twig\Environment::class);
                    if ($twig instanceof \Twig\Environment) {
                        $html = $twig->render('error/503.twig', ['reason' => $reason, 'retry_after' => $retryAfter]);
                        return Response::html($html, 503);
                    }
                } catch (\Throwable) { /* fall through */ }
            }
            return Response::html($this->errorPages()->maintenancePage($reason), 503);
        }

        // Match route
        $match = $router->match($request);

        if ($match === null) {
            return $this->notFoundResponse($request);
        }

        // Set route params on request
        $request->setRouteParams($match['params']);

        $events = $this->container->get(EventManager::class);
        if ($events instanceof EventManager) {
            $events->doAction('system.route.matched', $match, $request);
        }

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

        $pipeline = $core;

        foreach (array_reverse($stack) as $middlewareClass) {
            $pipeline = function (Request $req) use ($middlewareClass, $pipeline): Response {
                if (!class_exists($middlewareClass)) {
                    throw new \RuntimeException("Middleware class not found: {$middlewareClass}");
                }

                try {
                    $middleware = $this->container->get($middlewareClass);
                } catch (\Throwable) {
                    $middleware = new $middlewareClass($this->container);
                }

                if (!is_object($middleware) || !method_exists($middleware, 'handle')) {
                    return $pipeline($req);
                }

                /** @phpstan-ignore-next-line */
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
            } catch (\Throwable $e) {
                $this->safeLog('404 template render failed: ' . $e->getMessage(), 'warning');
            }
        }

        return Response::html('<h1>404 Not Found</h1>', 404);
    }

    /**
     * Handle uncaught exceptions with professional error pages.
     *
     * Production: branded 500 page, zero info leak.
     * Debug: styled debug panel with sanitized stack trace.
     */
    private function handleException(\Throwable $e): void
    {
        $debug = filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

        $this->safeLog(sprintf(
            'Fatal: %s in %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ), 'critical');

        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }

        if ($this->isDatabaseUnavailable($e)) {
            $this->sendServiceUnavailable();
            return;
        }

        http_response_code(500);

        // API requests get JSON (no sensitive data)
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $acceptStr = is_string($accept) ? $accept : '';
        $uriStr = is_string($uri) ? $uri : '';
        if (
            str_contains($acceptStr, 'application/json')
            || str_contains($uriStr, '/api/')
        ) {
            header('Content-Type: application/json; charset=UTF-8');
            $payload = ['success' => false, 'message' => 'Internal Server Error'];
            if ($debug) {
                $payload['error'] = $this->errorPages()->sanitizeErrorMessage($e->getMessage());
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            return;
        }

        // HTML response - try Twig template first
        header('Content-Type: text/html; charset=UTF-8');

        if (!$debug) {
            // Production: clean branded page
            if ($this->container->has(\Twig\Environment::class)) {
                try {
                    $twig = $this->container->get(\Twig\Environment::class);
                    if ($twig instanceof \Twig\Environment) {
                        echo $twig->render('error/500.twig');
                        return;
                    }
                } catch (\Throwable) {
                    // Twig unavailable - fall through to inline
                }
            }
            echo $this->errorPages()->internalErrorPage();
            return;
        }

        // Debug: styled debug page (never expose raw paths to browser)
        echo $this->errorPages()->debugErrorPage($e, dirname(__DIR__));
    }

    /**
     * Detects whether an exception chain represents a transient
     * database-unavailable condition (connection exhaustion / server down),
     * which should degrade to 503 rather than a generic 500.
     */
    private function isDatabaseUnavailable(\Throwable $e): bool
    {
        $needles = [
            'too many connections',
            'connection refused',
            'server has gone away',
            'lost connection',
            'database connection could not be established',
        ];
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            $message = strtolower($cur->getMessage());
            foreach ($needles as $needle) {
                if (str_contains($message, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Emit a 503 Service Unavailable response with a Retry-After hint.
     * Uses a self-contained body (no DB/Twig dependency) so it cannot cascade
     * during a database outage.
     */
    private function sendServiceUnavailable(): void
    {
        $retryAfter = 30;
        http_response_code(503);
        if (!headers_sent()) {
            header("Retry-After: {$retryAfter}");
        }

        $accept = is_string($a = $_SERVER['HTTP_ACCEPT'] ?? '') ? $a : '';
        $uri = is_string($u = $_SERVER['REQUEST_URI'] ?? '') ? $u : '';
        if (str_contains($accept, 'application/json') || str_contains($uri, '/api/')) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(
                ['success' => false, 'message' => 'Service temporarily unavailable. Please retry shortly.'],
                JSON_UNESCAPED_UNICODE
            );
            return;
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $this->errorPages()->serviceUnavailablePage();
    }

    /**
     * Log via PSR-3 Logger when available, fallback to error_log during early boot.
     */
    private function safeLog(string $message, string $level = 'error'): void
    {
        try {
            if ($this->container->has(\OwnPay\Service\System\Logger::class)) {
                $logger = $this->container->get(\OwnPay\Service\System\Logger::class);
                $logger->{$level}($message);
                return;
            }
        } catch (\Throwable) {
            // Logger not available yet
        }
        error_log("[OwnPay] {$message}");
    }

    /**
     * Get the DI container (for testing/debugging).
     */
    public function getContainer(): Container
    {
        return $this->container;
    }
}
