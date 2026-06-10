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
            if (!is_array($config)) {
                $config = [];
            }
            $path = '/' . trim($request->path(), '/');

            // Dynamically load the admin login slug to ensure rate limits apply perfectly
            $loginSlug = 'login';
            $paths = $config['paths'] ?? null;
            $root = is_array($paths) && isset($paths['root']) && is_string($paths['root']) ? $paths['root'] : dirname(__DIR__, 2);
            $cacheFile = $root . '/storage/cache/login_slug.cache';
            if (file_exists($cacheFile)) {
                $cached = file_get_contents($cacheFile);
                if ($cached !== false && preg_match('/^[a-z0-9\-]+$/', $cached)) {
                    $loginSlug = $cached;
                }
            }

            $isLoginRoute = (
                $path === '/' . $loginSlug || 
                $path === '/2fa' || 
                $path === '/forgot-password' || 
                str_contains($path, '/login') || 
                str_contains($path, '/2fa') || 
                str_contains($path, '/forgot-password')
            );

            $rateLimitConfig = $config['rate_limit'] ?? null;
            $limitConfig = null;
            if (is_array($rateLimitConfig)) {
                if ($isLoginRoute) {
                    $limitConfig = $rateLimitConfig['login'] ?? null;
                } elseif (str_starts_with($path, '/api/')) {
                    $limitConfig = $rateLimitConfig['api'] ?? null;
                } else {
                    $limitConfig = $rateLimitConfig['global'] ?? null;
                }
            }
            if (!is_array($limitConfig)) {
                $limitConfig = $isLoginRoute ? ['max' => 5, 'window' => 300] : (str_starts_with($path, '/api/') ? ['max' => 60, 'window' => 60] : ['max' => 120, 'window' => 60]);
            }

            $limitVal = $limitConfig['max'] ?? 60;
            $limit = is_scalar($limitVal) ? (int) $limitVal : 60;
            $windowVal = $limitConfig['window'] ?? 60;
            $window = is_scalar($windowVal) ? (int) $windowVal : 60;

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
            $this->logWarning('Rate limiter skipped: ' . $e->getMessage());

            // Determine if the current endpoint requires strict rate limiting (fail-closed)
            $loginSlug = 'login';
            $root = dirname(__DIR__, 2);
            $cacheFile = $root . '/storage/cache/login_slug.cache';
            if (file_exists($cacheFile)) {
                $cached = file_get_contents($cacheFile);
                if ($cached !== false && preg_match('/^[a-z0-9\-]+$/', $cached)) {
                    $loginSlug = $cached;
                }
            }

            $requestPath = '/' . trim($request->path(), '/');
            $isLogin = (
                $requestPath === '/' . $loginSlug || 
                $requestPath === '/2fa' || 
                $requestPath === '/forgot-password' || 
                str_contains($requestPath, '/login') || 
                str_contains($requestPath, '/2fa') || 
                str_contains($requestPath, '/forgot-password')
            );

            if ($isLogin || str_starts_with($requestPath, '/api/mobile/v1/devices')) {
                return Response::json([
                    'success' => false,
                    'message' => 'Service temporarily unavailable. Limiter backend error.',
                ], 503);
            }

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
                $cache = $this->container->get(\OwnPay\Cache\RedisCache::class);
                if ($cache instanceof \OwnPay\Cache\RedisCache) {
                    // Use raw Redis GET, not cache->get().
                    // INCR stores plain integers; cache->get() unserializes and
                    // unserialize("5") returns false → always reads 0.
                    $val = $cache->redis()->get('op:' . $key);
                    return is_scalar($val) ? (int) $val : 0;
                }
            } catch (\Throwable $e) {
                $this->logWarning('Redis getHits failed: ' . $e->getMessage());
            }
        }

        // DB fallback
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return 0;
        }
        $row = $db->fetchOne(
            "SELECT hits FROM op_rate_limits WHERE key_name = :k AND expires_at > :now LIMIT 1",
            ['k' => $key, 'now' => $now]
        );
        return (is_array($row) && isset($row['hits']) && is_scalar($row['hits'])) ? (int) $row['hits'] : 0;
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
                $cache = $this->container->get(\OwnPay\Cache\RedisCache::class);
                if ($cache instanceof \OwnPay\Cache\RedisCache) {
                    $redis = $cache->redis();
                    $prefixedKey = 'op:' . $key;

                    // Use native Redis INCR — single atomic operation.
                    // Previous code did get() then set(current+1), losing counts
                    // under concurrent requests (two requests read same value,
                    // both write value+1, effectively counting 1 hit instead of 2).
                    $hits = $redis->incr($prefixedKey);

                    // Set TTL only on first increment (when key was just created)
                    if (is_scalar($hits) && (int)$hits === 1) {
                        $redis->expire($prefixedKey, $window);
                    }

                    return;
                }
            } catch (\Throwable $e) {
                $this->logWarning('Redis increment failed: ' . $e->getMessage());
            }
        }

        // Atomic upsert — no TOCTOU race
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return;
        }
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
                $logger = $this->container->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->warning($message);
                }
            }
        } catch (\Throwable) {}
    }
}
