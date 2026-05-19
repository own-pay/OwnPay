<?php
declare(strict_types=1);

namespace OwnPay;

use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Http\Router;

/**
 * Application kernel - the central orchestrator.
 *
 * Boot sequence:
 *   1. Load .env (phpdotenv)
 *   2. Build DI container (config/services.php)
 *   3. Set timezone
 *   4. Load middleware pipeline (config/middleware.php)
 *   5. Boot plugins (PluginLoader::boot)
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

        // 2b. Bootstrap EnvironmentService with DB-backed persistence
        try {
            if ($this->container->has(\OwnPay\Repository\SettingsRepository::class)) {
                \OwnPay\Service\System\EnvironmentService::boot(
                    $this->container->get(\OwnPay\Repository\SettingsRepository::class)
                );
            }
        } catch (\Throwable) {
            // DB not ready (install phase) — EnvironmentService stays in-memory mode
        }

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

        // AUD-21 FIX: Assert security-critical middleware cannot be removed by plugins.
        $requiredAdmin = [
            \OwnPay\Middleware\SessionMiddleware::class,
            \OwnPay\Middleware\CsrfMiddleware::class,
            \OwnPay\Middleware\PermissionMiddleware::class,
        ];
        foreach ($requiredAdmin as $mw) {
            if (!in_array($mw, $this->middlewareConfig['admin'] ?? [], true)) {
                $this->middlewareConfig['admin'][] = $mw;
                $this->safeLog("Security middleware {$mw} was removed by plugin — re-added", 'warning');
            }
        }

        // 5. Boot plugins
        if ($this->container->has(\OwnPay\Plugin\PluginLoader::class)) {
            try {
                /** @var \OwnPay\Plugin\PluginLoader $pluginLoader */
                $pluginLoader = $this->container->get(\OwnPay\Plugin\PluginLoader::class);
                $pluginLoader->boot();
            } catch (\Throwable $e) {
                // Plugin boot failure must not crash the system
                $this->safeLog('Plugin boot error: ' . $e->getMessage(), 'error');
            }
        }

        // 6. Fire system.boot
        $events->doAction('system.boot');

        // 7. Load routes
        // Fail-fast if JWT_SECRET is missing or too short.
        // Prevents JwtAuthMiddleware from returning 500 at runtime,
        // which leaks configuration state to attackers.
        if ($this->isInstalled()) {
            $jwtSecret = getenv('JWT_SECRET') ?: ($_ENV['JWT_SECRET'] ?? '');
            if (strlen($jwtSecret) < 32) {
                throw new \RuntimeException(
                    'JWT_SECRET must be set in .env and be at least 32 characters. '
                    . 'Generate with: php -r "echo bin2hex(random_bytes(32));"'
                );
            }

            // Validate APP_KEY and ENCRYPTION_KEY minimum entropy.
            // Keys shorter than 32 bytes provide insufficient cryptographic strength.
            $appKey = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? '');
            if (strlen($appKey) < 32) {
                throw new \RuntimeException(
                    'APP_KEY must be at least 32 characters. '
                    . 'Generate with: php -r "echo base64_encode(random_bytes(32));"'
                );
            }
            $encKey = getenv('ENCRYPTION_KEY') ?: ($_ENV['ENCRYPTION_KEY'] ?? '');
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

        // Check maintenance mode - let critical routes pass through
        $maintenanceLock = dirname(__DIR__) . '/storage/.maintenance';
        // AUD-12 FIX: Whitelist admin, login, webhooks, checkout status, and cron.
        // Gateway callbacks MUST process during maintenance or payments are lost.
        $maintenanceWhitelist = ['/admin', '/login', '/webhook/', '/cron/', '/checkout/'];
        $pathAllowed = false;
        foreach ($maintenanceWhitelist as $prefix) {
            if (str_starts_with($request->path(), $prefix)) {
                $pathAllowed = true;
                break;
            }
        }
        if (file_exists($maintenanceLock) && !$pathAllowed) {
            $info = json_decode(file_get_contents($maintenanceLock) ?: '{}', true);
            $retryAfter = (int) ($info['retry_after'] ?? 300);
            $reason     = $info['reason'] ?? 'System maintenance in progress. Please try again shortly.';
            if ($request->expectsJson()) {
                return Response::maintenance($reason, $retryAfter);
            }
            http_response_code(503);
            header("Retry-After: {$retryAfter}");
            // Try to render a Twig 503 template if available
            if ($this->container->has(\Twig\Environment::class)) {
                try {
                    $twig = $this->container->get(\Twig\Environment::class);
                    $html = $twig->render('error/503.twig', ['reason' => $reason, 'retry_after' => $retryAfter]);
                    return Response::html($html, 503);
                } catch (\Throwable) { /* fall through */ }
            }
            return Response::html("<h1>Maintenance</h1><p>{$reason}</p>", 503);
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

        http_response_code(500);

        // API requests get JSON (no sensitive data)
        if (
            str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')
        ) {
            header('Content-Type: application/json; charset=UTF-8');
            $payload = ['success' => false, 'message' => 'Internal Server Error'];
            if ($debug) {
                $payload['error'] = $this->sanitizeErrorMessage($e->getMessage());
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            return;
        }

        // HTML response — try Twig template first
        header('Content-Type: text/html; charset=UTF-8');

        if (!$debug) {
            // Production: clean branded page
            if ($this->container->has(\Twig\Environment::class)) {
                try {
                    $twig = $this->container->get(\Twig\Environment::class);
                    echo $twig->render('error/500.twig');
                    return;
                } catch (\Throwable) {
                    // Twig unavailable — fall through to inline
                }
            }
            echo $this->renderInlineErrorPage();
            return;
        }

        // Debug: styled debug page (never expose raw paths to browser)
        echo $this->renderDebugErrorPage($e);
    }

    /**
     * Sanitize error message — strip file paths and credentials.
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // Strip full file paths
        $message = preg_replace('#[A-Z]:\\\\[^\s:]+#', '[path]', $message) ?? $message;
        $message = preg_replace('#/[^\s:]+\.php#', '[path]', $message) ?? $message;
        // Strip password references
        $message = preg_replace('#using password: (?:YES|NO)#i', 'using password: ***', $message) ?? $message;
        return $message;
    }

    /**
     * Inline production 500 page — used when Twig is unavailable.
     */
    private function renderInlineErrorPage(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Error — Own Pay</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Inter',sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .c{text-align:center;max-width:480px;padding:2rem}
        .icon{width:80px;height:80px;margin:0 auto 1.5rem;border-radius:50%;background:rgba(239,68,68,.15);display:flex;align-items:center;justify-content:center}
        .icon svg{width:40px;height:40px;color:#ef4444}
        .code{font-size:4rem;font-weight:800;background:linear-gradient(135deg,#ef4444,#f97316);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1;margin-bottom:.75rem}
        h1{font-size:1.25rem;font-weight:600;margin-bottom:.5rem;color:#f1f5f9}
        p{color:#94a3b8;line-height:1.6;margin-bottom:1.5rem;font-size:.9rem}
        .btn{display:inline-block;padding:.7rem 1.5rem;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;text-decoration:none;border-radius:.5rem;font-weight:500;font-size:.9rem;transition:all .2s;border:none;cursor:pointer}
        .btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(99,102,241,.4)}
        .footer{margin-top:2rem;font-size:.75rem;color:#475569}
    </style>
</head>
<body>
    <div class="c">
        <div class="icon">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
        </div>
        <div class="code">500</div>
        <h1>Something went wrong</h1>
        <p>An unexpected error occurred while processing your request. Our team has been notified and is working on it.</p>
        <a class="btn" href="/">Back to Home</a>
        <div class="footer">Own Pay &bull; Secure Payment Gateway</div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Styled debug error page — shows sanitized details for developers.
     */
    private function renderDebugErrorPage(\Throwable $e): string
    {
        $class = get_class($e);
        $message = htmlspecialchars($this->sanitizeErrorMessage($e->getMessage()), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars(str_replace(dirname(__DIR__), '.', $e->getFile()), ENT_QUOTES, 'UTF-8');
        $line = $e->getLine();

        // Sanitize trace — make paths relative, strip args
        $traceLines = '';
        foreach ($e->getTrace() as $i => $frame) {
            $fPath = isset($frame['file']) ? str_replace(dirname(__DIR__), '.', $frame['file']) : '[internal]';
            $fLine = $frame['line'] ?? '?';
            $fFunc = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
            $traceLines .= sprintf(
                '<tr><td class="n">#%d</td><td class="f">%s</td><td class="l">%s</td><td class="fn">%s()</td></tr>',
                $i,
                htmlspecialchars($fPath, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $fLine, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($fFunc, ENT_QUOTES, 'UTF-8')
            );
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug — {$class}</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'JetBrains Mono',monospace,-apple-system,sans-serif;background:#0c0e14;color:#c9d1d9;min-height:100vh}
        .header{background:linear-gradient(135deg,#1e1229,#161b22);border-bottom:1px solid #30363d;padding:1.25rem 2rem;display:flex;align-items:center;gap:1rem}
        .badge{padding:.3rem .7rem;border-radius:.375rem;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase}
        .badge-err{background:rgba(248,81,73,.15);color:#f85149;border:1px solid rgba(248,81,73,.3)}
        .badge-debug{background:rgba(136,98,234,.15);color:#8862ea;border:1px solid rgba(136,98,234,.3)}
        .title{font-size:1rem;font-weight:600;color:#e6edf3}
        .main{padding:2rem;max-width:1100px;margin:0 auto}
        .card{background:#161b22;border:1px solid #30363d;border-radius:.75rem;margin-bottom:1.5rem;overflow:hidden}
        .card-head{padding:.75rem 1.25rem;background:#1c2129;border-bottom:1px solid #30363d;font-size:.75rem;font-weight:600;color:#8b949e;text-transform:uppercase;letter-spacing:.05em}
        .card-body{padding:1.25rem}
        .msg{font-size:1.1rem;color:#f0883e;word-break:break-all;line-height:1.5}
        .loc{margin-top:.75rem;font-size:.85rem;color:#8b949e}
        .loc span{color:#58a6ff}
        table{width:100%;border-collapse:collapse;font-size:.8rem}
        tr:hover{background:rgba(56,139,253,.06)}
        td{padding:.5rem .75rem;border-bottom:1px solid #21262d;vertical-align:top}
        .n{color:#484f58;width:2rem;text-align:right}
        .f{color:#7ee787;max-width:350px;overflow:hidden;text-overflow:ellipsis}
        .l{color:#d2a8ff;width:3rem;text-align:center}
        .fn{color:#79c0ff}
        .env-bar{display:flex;gap:1rem;flex-wrap:wrap;font-size:.75rem;color:#8b949e}
        .env-bar span{padding:.25rem .5rem;background:#21262d;border-radius:.25rem}
        .warn{margin-top:1rem;padding:.75rem 1rem;background:rgba(210,153,34,.08);border:1px solid rgba(210,153,34,.3);border-radius:.5rem;font-size:.75rem;color:#d29922}
    </style>
</head>
<body>
    <div class="header">
        <span class="badge badge-err">500</span>
        <span class="badge badge-debug">DEBUG MODE</span>
        <span class="title">{$class}</span>
    </div>
    <div class="main">
        <div class="card">
            <div class="card-head">Exception</div>
            <div class="card-body">
                <div class="msg">{$message}</div>
                <div class="loc">in <span>{$file}</span> on line <span>{$line}</span></div>
            </div>
        </div>
        <div class="card">
            <div class="card-head">Stack Trace</div>
            <div class="card-body" style="padding:0">
                <table>{$traceLines}</table>
            </div>
        </div>
        <div class="card">
            <div class="card-head">Environment</div>
            <div class="card-body">
                <div class="env-bar">
                    <span>PHP {$this->safePhpVersion()}</span>
                    <span>OwnPay v0.1.0</span>
                    <span>{$this->safeEnvName()}</span>
                </div>
                <div class="warn">⚠ This debug page is visible because APP_DEBUG=true. Set APP_DEBUG=false in production to show a generic error page.</div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function safePhpVersion(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
    }

    private function safeEnvName(): string
    {
        return htmlspecialchars($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production', ENT_QUOTES, 'UTF-8');
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
