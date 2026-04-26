<?php

declare(strict_types=1);

namespace OwnPay\Middleware;

/**
 * CorsMiddleware — centralized CORS handler.
 *
 * Features:
 *   - Configurable allowed origins (list or wildcard)
 *   - Proper OPTIONS preflight handling (returns 204)
 *   - Standard Access-Control-Allow-* headers
 *   - Blocks wildcard '*' origin in production mode
 *   - Exposes rate limit headers to JavaScript clients
 */
final class CorsMiddleware
{
    /** @var string[] Allowed origin domains */
    private array $allowedOrigins;

    /** @var string[] Allowed HTTP methods */
    private array $allowedMethods;

    /** @var string[] Allowed request headers */
    private array $allowedHeaders;

    /** @var string[] Headers exposed to the browser */
    private array $exposedHeaders;

    private int $maxAge;
    private bool $allowCredentials;

    public function __construct(
        array $allowedOrigins = [],
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = [
            'Authorization',
            'Content-Type',
            'Accept',
            'X-Requested-With',
            'X-OP-Signature',
            'X-OP-Timestamp',
            'Idempotency-Key',
        ],
        array $exposedHeaders = [
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
        ],
        int $maxAge = 86400,
        bool $allowCredentials = true
    ) {
        $this->allowedOrigins = $allowedOrigins;
        $this->allowedMethods = $allowedMethods;
        $this->allowedHeaders = $allowedHeaders;
        $this->exposedHeaders = $exposedHeaders;
        $this->maxAge = $maxAge;
        $this->allowCredentials = $allowCredentials;
    }

    /**
     * Handle CORS for the current request.
     *
     * Call this at the top of the request pipeline.
     * Will exit with 204 on OPTIONS preflight.
     */
    public function handle(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (empty($origin)) {
            return; // Not a CORS request
        }

        if (!$this->isOriginAllowed($origin)) {
            // Don't set CORS headers — browser will block
            return;
        }

        // Set CORS response headers
        header("Access-Control-Allow-Origin: {$origin}");
        header('Vary: Origin');

        if ($this->allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }

        if (!empty($this->exposedHeaders)) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $this->exposedHeaders));
        }

        // Handle preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
            header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
            header("Access-Control-Max-Age: {$this->maxAge}");

            http_response_code(204);
            exit;
        }
    }

    /**
     * Check if an origin is allowed.
     */
    private function isOriginAllowed(string $origin): bool
    {
        // Empty config = allow all (development only)
        if (empty($this->allowedOrigins)) {
            return true;
        }

        // Wildcard — only in non-production
        if (in_array('*', $this->allowedOrigins, true)) {
            $env = getenv('APP_ENV') ?: 'production';
            if ($env === 'production') {
                error_log("[CORS] Wildcard origin blocked in production mode");
                return false;
            }
            return true;
        }

        // Parse origin to compare domains
        $originHost = parse_url($origin, PHP_URL_HOST);

        foreach ($this->allowedOrigins as $allowed) {
            // Exact match
            if ($origin === $allowed) {
                return true;
            }

            // Host-only match (ignore scheme/port)
            $allowedHost = parse_url($allowed, PHP_URL_HOST) ?: $allowed;
            if ($originHost === $allowedHost) {
                return true;
            }

            // Subdomain wildcard (e.g. "*.example.com")
            if (str_starts_with($allowed, '*.')) {
                $baseDomain = substr($allowed, 2);
                if ($originHost === $baseDomain || str_ends_with($originHost, ".{$baseDomain}")) {
                    return true;
                }
            }
        }

        return false;
    }
}
