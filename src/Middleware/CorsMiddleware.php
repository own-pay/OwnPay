<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * CORS middleware - handles preflight and CORS headers for API routes.
 *
 * Per OWASP: restrict origins, no wildcard with credentials.
 */
final class CorsMiddleware
{
    /** @phpstan-ignore property.onlyWritten */
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

        // Wildcard mode — allow all origins (AUD-B7 fix: was broken, returned [])
        if (in_array('*', $allowedOrigins, true)) {
            $response->withHeader('Access-Control-Allow-Origin', '*');
            $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
            $response->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
            $response->withHeader('Access-Control-Max-Age', '86400');
            // BUG-020 FIX: Explicitly deny credentials with wildcard origin
            // Per OWASP, wildcard + credentials is a critical CORS misconfiguration
            $response->withHeader('Access-Control-Allow-Credentials', 'false');
            return $response;
        }

        // Strict origin check - explicit matches only
        if (in_array($origin, $allowedOrigins, true)) {
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
        $env = getenv('CORS_ALLOWED_ORIGINS') ?: '';
        if ($env === '') {
            return []; // No cross-origin allowed unless configured
        }
        // AUD-B7 fix: '*' now correctly returns ['*'] instead of empty array
        return array_map('trim', explode(',', $env));
    }
}

