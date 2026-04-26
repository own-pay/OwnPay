<?php

declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Repository\RateLimitRepository;

/**
 * RateLimiterMiddleware — sliding-window rate limiter.
 *
 * Enforces per-key request limits with:
 *   - Configurable limits per window (default: 60 req/min)
 *   - Separate limits for read (GET) vs write (POST/PUT/DELETE)
 *   - Standard rate limit headers (X-RateLimit-*, Retry-After)
 *   - Whitelist for internal/admin keys
 *   - 429 Too Many Requests response on breach
 */
final class RateLimiterMiddleware
{
    private const DEFAULT_READ_LIMIT = 120;  // GET requests per window
    private const DEFAULT_WRITE_LIMIT = 30;   // POST/PUT/DELETE per window
    private const DEFAULT_WINDOW_SEC = 60;   // 1-minute window

    private RateLimitRepository $repo;

    /** @var string[] Key prefixes exempt from rate limiting */
    private array $whitelist = [];

    public function __construct(
        ?RateLimitRepository $repo = null,
        array $whitelist = []
    ) {
        $this->repo = $repo ?? new RateLimitRepository();
        $this->whitelist = $whitelist;
    }

    /**
     * Check rate limit. Must be called AFTER authentication
     * (needs the key ID to scope limits).
     *
     * @param int    $keyId       API key ID
     * @param string $keyPrefix   Key prefix (for whitelist check)
     * @param string $method      HTTP method
     * @param int|null $customLimit Override limit for this key
     * @return array{
     *   allowed: bool,
     *   limit: int,
     *   remaining: int,
     *   retryAfter: int,
     *   headers: array<string, string>
     * }
     */
    public function check(
        int $keyId,
        string $keyPrefix = '',
        string $method = 'GET',
        ?int $customLimit = null
    ): array {
        // Whitelist bypass
        if ($this->isWhitelisted($keyPrefix)) {
            return [
                'allowed' => true,
                'limit' => PHP_INT_MAX,
                'remaining' => PHP_INT_MAX,
                'retryAfter' => 0,
                'headers' => [],
            ];
        }

        $isWrite = in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH'], true);
        $limit = $customLimit ?? ($isWrite ? self::DEFAULT_WRITE_LIMIT : self::DEFAULT_READ_LIMIT);
        $window = self::DEFAULT_WINDOW_SEC;

        // Scope key: separate read/write counters
        $rateKey = "api_key:{$keyId}:" . ($isWrite ? 'write' : 'read');

        // Record hit and get current count
        $currentCount = $this->repo->hit($rateKey, $window);
        $remaining = max(0, $limit - $currentCount);

        $headers = [
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset' => (string) (time() + $window),
        ];

        if ($currentCount > $limit) {
            $headers['Retry-After'] = (string) $window;

            return [
                'allowed' => false,
                'limit' => $limit,
                'remaining' => 0,
                'retryAfter' => $window,
                'headers' => $headers,
            ];
        }

        return [
            'allowed' => true,
            'limit' => $limit,
            'remaining' => $remaining,
            'retryAfter' => 0,
            'headers' => $headers,
        ];
    }

    /**
     * Enforce rate limit — sends 429 response and exits if exceeded.
     * Call this in the request pipeline; it sets headers on every valid request too.
     */
    public function enforce(int $keyId, string $keyPrefix = '', string $method = 'GET'): void
    {
        $result = $this->check($keyId, $keyPrefix, $method);

        // Always set rate limit headers
        foreach ($result['headers'] as $name => $value) {
            header("{$name}: {$value}");
        }

        if (!$result['allowed']) {
            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'code' => 'RATE_LIMIT_EXCEEDED',
                    'message' => "Too many requests. Limit: {$result['limit']}/min. Retry after {$result['retryAfter']}s.",
                ],
            ]);
            exit;
        }
    }

    /**
     * Check if key prefix is whitelisted.
     */
    private function isWhitelisted(string $keyPrefix): bool
    {
        foreach ($this->whitelist as $prefix) {
            if (str_starts_with($keyPrefix, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
