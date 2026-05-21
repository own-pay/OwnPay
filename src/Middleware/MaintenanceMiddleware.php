<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Maintenance mode middleware — returns 503 when maintenance lock active.
 * Checks file-based lock at storage/.maintenance.
 */
final class MaintenanceMiddleware
{
    /** @phpstan-ignore property.onlyWritten */
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        // Skip for install route
        if (str_starts_with($request->path(), '/install')) {
            return $next($request);
        }

        // Skip for admin routes when authenticated (allows disabling maintenance from admin)
        // BUG-8 FIX: Start session if needed before checking auth since maintenance mode runs in global group
        if (str_starts_with($request->path(), '/admin')) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                $config = $this->container->get('config.app');
                $secure = $request->isSecure();

                ini_set('session.use_strict_mode', '1');
                ini_set('session.use_only_cookies', '1');
                ini_set('session.cookie_httponly', '1');

                session_set_cookie_params([
                    'lifetime' => (int) ($config['session']['lifetime'] ?? 7200),
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => $secure,
                    'httponly'  => true,
                    'samesite'  => 'Lax',
                ]);

                session_name('op_session');
                session_start();
            }
            if (!empty($_SESSION['auth_user_id'])) {
                return $next($request);
            }
        }

        // Skip for /admin/system-update route even without session (escape hatch)
        if ($request->path() === '/admin/system-update') {
            return $next($request);
        }

        // Check file-based lock first (faster than DB)
        $lockFile = dirname(__DIR__, 2) . '/storage/.maintenance';
        if (!file_exists($lockFile)) {
            return $next($request);
        }

        $data = @json_decode((string) file_get_contents($lockFile), true);
        $retryAfter = $data['retry_after'] ?? 600;

        return Response::maintenance()
            ->withHeader('Retry-After', (string) $retryAfter);
    }
}
