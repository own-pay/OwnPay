<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware handling Maintenance Mode checks.
 *
 * Intercepts requests when a maintenance lock file exists at `storage/.maintenance`.
 * Bypasses installation flows, system updates, and active administrative sessions.
 */
final class MaintenanceMiddleware
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $container;

    /**
     * Constructs a new MaintenanceMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Handles Maintenance Mode logic for incoming HTTP requests.
     *
     * Checks for the presence of the file-based maintenance lock and returns a 503 response
     * unless the request path matches an exempted route.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        // Skip for install route
        if (str_starts_with($request->path(), '/install')) {
            return $next($request);
        }

        // Skip for admin routes when authenticated (allows disabling maintenance from admin)
        // Start session if needed before checking auth since maintenance mode runs in global group
        if (str_starts_with($request->path(), '/admin')) {
            // SESSION-DEDUP FIX: Delegate to SessionMiddleware's shared helper
            // to ensure idle timeout, ID regeneration, and cookie params stay in sync.
            SessionMiddleware::ensureStarted($this->container, $request);
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
