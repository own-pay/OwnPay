<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware handling Maintenance Mode checks.
 *
 * Intercepts requests when a maintenance lock file exists at `storage/.maintenance`.
 * Shares its passthrough whitelist with the Kernel's maintenance gate so the two
 * layers can never disagree about which routes stay reachable.
 */
final class MaintenanceMiddleware
{
    /**
     * Path prefixes that stay reachable during maintenance.
     *
     * Single source of truth shared with the Kernel's maintenance gate (AUD-12):
     * gateway callbacks/webhooks/cron MUST process during maintenance or payments
     * are lost, and /login + /admin must work so the operator can disable
     * maintenance from the panel. Matching is segment-aware ('/login' and
     * '/login/...', never '/loginfoo').
     *
     * @var list<string>
     */
    public const PASSTHROUGH_PREFIXES = ['/admin', '/login', '/webhook', '/cron', '/checkout'];

    /**
     * Checks a request path against the maintenance passthrough whitelist.
     *
     * @param string $path Normalized request path.
     * @return bool True when the path must bypass maintenance blocking.
     */
    public static function isPassthroughPath(string $path): bool
    {
        foreach (self::PASSTHROUGH_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
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

        // Honor the shared maintenance whitelist (same gate the Kernel applies)
        // so login, admin, webhooks, cron, and in-flight checkouts keep working. :D
        if (self::isPassthroughPath($request->path())) {
            return $next($request);
        }

        // Check file-based lock first (faster than DB)
        $lockFile = dirname(__DIR__, 2) . '/storage/.maintenance';
        if (!file_exists($lockFile)) {
            return $next($request);
        }

        $data = @json_decode((string) file_get_contents($lockFile), true);
        $retryAfter = 600;
        if (is_array($data) && isset($data['retry_after']) && is_scalar($data['retry_after'])) {
            $retryAfter = (int) $data['retry_after'];
        }

        return Response::maintenance()
            ->withHeader('Retry-After', (string) $retryAfter);
    }
}
