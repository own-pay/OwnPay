<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware responsible for handling Cross-Origin Resource Sharing (CORS).
 *
 * Intercepts HTTP OPTIONS preflight requests and appends appropriate CORS headers
 * to both preflight and standard HTTP responses. Implements OWASP security
 * recommendations by strictly managing allowed origin matching and preventing
 * credential exposure when wildcard origins are configured.
 */
final class CorsMiddleware
{
    /** @phpstan-ignore property.onlyWritten */
    private Container $container;

    /**
     * Constructs a new instance of CorsMiddleware.
     *
     * @param Container $container Dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Handles the incoming HTTP request CORS preflight check or response headers injection.
     *
     * @param Request $request The incoming HTTP request instance.
     * @param callable(Request): Response $next Next middleware/handler in the execution stack.
     * @return Response The HTTP response instance.
     */
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

    /**
     * Adds the computed CORS headers to the HTTP response based on allowed origin criteria.
     *
     * @param Response $response The target response instance to modify.
     * @param string $origin The requested origin host header value.
     * @param string[] $allowedOrigins Array of whitelisted origin strings parsed from configuration.
     * @return Response The updated response instance with CORS headers applied.
     */
    private function addCorsHeaders(Response $response, string $origin, array $allowedOrigins): Response
    {
        if ($origin === '') {
            return $response;
        }

        // Wildcard mode - allow all origins
        if (in_array('*', $allowedOrigins, true)) {
            $response->withHeader('Access-Control-Allow-Origin', '*');
            $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
            $response->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
            $response->withHeader('Access-Control-Max-Age', '86400');
            // Explicitly deny credentials with wildcard origin per OWASP recommendations.
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
     * Resolves the list of allowed origins configured in the system environment.
     *
     * @return string[] List of allowed origin string matches.
     */
    private function getAllowedOrigins(): array
    {
        $env = getenv('CORS_ALLOWED_ORIGINS') ?: '';
        if ($env === '') {
            return []; // No cross-origin allowed unless configured
        }
        return array_map('trim', explode(',', $env));
    }
}

