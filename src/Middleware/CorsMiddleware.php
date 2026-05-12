<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * CORS middleware â€” handles preflight and CORS headers for API routes.
 *
 * Per OWASP: restrict origins, no wildcard with credentials.
 */
final class CorsMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        $origin = $request->header('Origin');
        $allowedOrigins = $this->getAllowedOrigins();

        // Preflight
        if ($request->isMethod('OPTIONS')) {
            $response = Response::empty(204);
            return $this->addCorsHeaders($response, $origin, $allowedOrigins);
        }

        $response = $next($request);
        return $this->addCorsHeaders($response, $origin, $allowedOrigins);
    }

    private function addCorsHeaders(Response $response, string $origin, array $allowedOrigins): Response
    {
        if ($origin === '') {
            return $response;
        }

        // Strict origin check â€” no wildcards when credentials used
        if (in_array($origin, $allowedOrigins, true) || in_array('*', $allowedOrigins, true)) {
            $response->withHeader('Access-Control-Allow-Origin', $origin);
            $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
            $response->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
            $response->withHeader('Access-Control-Max-Age', '86400');
            $response->withHeader('Vary', 'Origin');
        }

        return $response;
    }

    /**
     * @return string[]
     */
    private function getAllowedOrigins(): array
    {
        $env = getenv('CORS_ALLOWED_ORIGINS') ?: '*';
        return array_map('trim', explode(',', $env));
    }
}
