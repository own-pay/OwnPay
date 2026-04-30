<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * CSRF middleware — validates token on state-changing requests.
 *
 * Per OWASP: synchronizer token pattern.
 * Skips GET/HEAD/OPTIONS. API routes use bearer auth instead.
 */
final class CsrfMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        // Safe methods — no CSRF check needed
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Skip for API routes (authenticated via bearer/JWT, not cookies)
        if (str_starts_with($request->path(), '/api/')) {
            return $next($request);
        }

        // Skip webhook/IPN endpoints
        if (str_starts_with($request->path(), '/webhook/') || str_starts_with($request->path(), '/ipn/')) {
            return $next($request);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $this->forbidden($request, 'Session not active');
        }

        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        $submittedToken = $request->post('_csrf_token')
            ?? $request->header('X-CSRF-Token');

        if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            return $this->forbidden($request, 'CSRF token mismatch');
        }

        return $next($request);
    }

    private function forbidden(Request $request, string $reason): Response
    {
        if ($request->expectsJson()) {
            return Response::json([
                'success' => false,
                'message' => 'CSRF validation failed',
            ], 403);
        }

        return Response::html('<h1>403 Forbidden</h1><p>CSRF validation failed. Please refresh and try again.</p>', 403);
    }
}
