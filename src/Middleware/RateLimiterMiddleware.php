<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware handling sliding-window rate limiting.
 *
 * Implements rate limiting using Redis as the primary engine and falling back to a database
 * table representation (`op_rate_limits`). Prevents brute-force attacks and DDoS threats.
 */
final class RateLimiterMiddleware
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $container;

    /**
     * Constructs a new RateLimiterMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Enforces the rate limiting window on incoming HTTP requests.
     *
     * Injects rate limit metadata headers (X-RateLimit-Limit, X-RateLimit-Remaining)
     * and short-circuits with a 429 JSON response when exceeded.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        try {
            $config = $this->container->get('config.app');
            $limit = (int) ($config['rate_limit']['api_per_minute'] ?? 60);
            $window = 60; // 1 minute

            $key = $this->buildKey($request);
            $now = time();

            $hits = $this->getHits($key, $now, $window);

            if ($hits >= $limit) {
                return Response::json([
                    'success' => false,
                    'message' => 'Rate limit exceeded. Try again later.',
                ], 429)
                    ->withHeader('Retry-After', (string) $window)
                    ->withHeader('X-RateLimit-Limit', (string) $limit)
                    ->withHeader('X-RateLimit-Remaining', '0');
            }

            $this->increment($key, $now, $window);

            $response = $next($request);
            $response->withHeader('X-RateLimit-Limit', (string) $limit);
            $response->withHeader('X-RateLimit-Remaining', (string) max(0, $limit - $hits - 1));

            return $response;
        } catch (\PDOException|\RuntimeException $e) {
            // DB unavailable (install mode, outage) — skip rate limiting
            $this->logWarning('Rate limiter skipped: ' . $e->getMessage());
            return $next($request);
        }
    }

    /**
     * Generates a unique cache/DB rate limiting key based on request metadata.
     *
     * Uses 5 segments for deeper path uniqueness.
     *
     * @param Request $request The request context.
     * @return string The generated rate limiting key.
     */
    private function buildKey(Request $request): string
    {
        $ip = $request->ip();
        $prefix = explode('/', trim($request->path(), '/'));
        
        $pathKey = implode('.', array_slice($prefix, 0, 5));
        return "rl:{$ip}:{$pathKey}";
    }

    /**
     * Resolves current request hits count for the rate limit key.
     *
     * @param string $key The rate limit key.
     * @param int $now The current timestamp.
     * @param int $window The rate limiting window length.
     * @return int The total hit count.
     */
    private function getHits(string $key, int $now, int $window): int
    {
        // Try Redis first
        if ($this->container->has(\OwnPay\Cache\RedisCache::class)) {
            try {
                /** @var \OwnPay\Cache\RedisCache $cache */
                $cache = $this->container->get(\OwnPay\Cache\RedisCache::class);
                // Use raw Redis GET, not cache->get().
                // INCR stores plain integers; cache->get() unserializes and
                // unserialize("5") returns false → always reads 0.
                $val = $cache->redis()->get('op:' . $key);
                return $val !== false ? (int) $val : 0;
            } catch (\Throwable $e) {
                $this->logWarning('Redis getHits failed: ' . $e->getMessage());
            }
        }

        // DB fallback
        $db = $this->container->get(\OwnPay\Core\Database::class);
        $row = $db->fetchOne(
            "SELECT hits FROM op_rate_limits WHERE key_name = :k AND expires_at > :now LIMIT 1",
            ['k' => $key, 'now' => $now]
        );
        return $row ? (int) $row['hits'] : 0;
    }

    /**
     * Increments the rate limiting counter atomically.
     *
     * Leverages native Redis INCR or DB atomic UPSERT (INSERT ON DUPLICATE KEY UPDATE)
     * to prevent Time-of-Check to Time-of-Use (TOCTOU) race conditions.
     *
     * @param string $key The rate limit key.
     * @param int $now The current timestamp.
     * @param int $window The window length.
     * @return void
     */
    private function increment(string $key, int $now, int $window): void
    {
        // Try Redis (atomic via native INCR — no TOCTOU)
        if ($this->container->has(\OwnPay\Cache\RedisCache::class)) {
            try {
                /** @var \OwnPay\Cache\RedisCache $cache */
                $cache = $this->container->get(\OwnPay\Cache\RedisCache::class);
                $redis = $cache->redis();
                $prefixedKey = 'op:' . $key;

                // Use native Redis INCR — single atomic operation.
                // Previous code did get() then set(current+1), losing counts
                // under concurrent requests (two requests read same value,
                // both write value+1, effectively counting 1 hit instead of 2).
                $hits = $redis->incr($prefixedKey);

                // Set TTL only on first increment (when key was just created)
                if ($hits === 1) {
                    $redis->expire($prefixedKey, $window);
                }

                return;
            } catch (\Throwable $e) {
                $this->logWarning('Redis increment failed: ' . $e->getMessage());
            }
        }

        // Atomic upsert — no TOCTOU race
        $db = $this->container->get(\OwnPay\Core\Database::class);
        $expires = $now + $window;

        $db->execute(
            "INSERT INTO op_rate_limits (key_name, hits, window_start, expires_at)
             VALUES (:k, 1, :ws, :exp)
             ON DUPLICATE KEY UPDATE
                hits = IF(expires_at > :now2, hits + 1, 1),
                window_start = IF(expires_at > :now3, window_start, :ws2),
                expires_at = IF(expires_at > :now4, expires_at, :exp2)",
            [
                'k' => $key, 'ws' => $now, 'exp' => $expires,
                'now2' => $now, 'now3' => $now, 'ws2' => $now,
                'now4' => $now, 'exp2' => $expires
            ]
        );

        // Probabilistic cleanup of expired entries (1% chance)
        if (random_int(1, 100) === 1) {
            $db->delete("DELETE FROM op_rate_limits WHERE expires_at <= :now", ['now' => $now]);
        }
    }

    /**
     * Logs warning messages safely without raising exceptions.
     *
     * @param string $message The warning details.
     * @return void
     */
    private function logWarning(string $message): void
    {
        try {
            if ($this->container->has(\OwnPay\Service\System\Logger::class)) {
                $this->container->get(\OwnPay\Service\System\Logger::class)->warning($message);
            }
        } catch (\Throwable) {}
    }
}
