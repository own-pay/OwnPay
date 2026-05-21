<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Rate limiter — sliding window via DB or Redis.
 *
 * Per OWASP: prevent brute-force and DDoS.
 * Auto-detects Redis; falls back to DB table op_rate_limits.
 */
final class RateLimiterMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        try {
            $config = $this->container->get('config.app');
            $path = '/' . trim($request->path(), '/');

            // Dynamically load the admin login slug to ensure rate limits apply perfectly
            $loginSlug = 'login';
            $cacheFile = ($config['paths']['root'] ?? dirname(__DIR__, 2)) . '/storage/cache/login_slug.cache';
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

            if ($isLoginRoute) {
                $limitConfig = $config['rate_limit']['login'] ?? ['max' => 5, 'window' => 300];
            } elseif (str_starts_with($path, '/api/')) {
                $limitConfig = $config['rate_limit']['api'] ?? ['max' => 60, 'window' => 60];
            } else {
                $limitConfig = $config['rate_limit']['global'] ?? ['max' => 120, 'window' => 60];
            }

            $limit = (int) ($limitConfig['max'] ?? 60);
            $window = (int) ($limitConfig['window'] ?? 60);

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

    private function buildKey(Request $request): string
    {
        $ip = $request->ip();
        $prefix = explode('/', trim($request->path(), '/'));
        // BUG-019 FIX: Use 5 segments (not 3) for deeper path uniqueness
        $pathKey = implode('.', array_slice($prefix, 0, 5));
        return "rl:{$ip}:{$pathKey}";
    }

    private function getHits(string $key, int $now, int $window): int
    {
        // Try Redis first
        if ($this->container->has(\OwnPay\Cache\RedisCache::class)) {
            try {
                /** @var \OwnPay\Cache\RedisCache $cache */
                $cache = $this->container->get(\OwnPay\Cache\RedisCache::class);
                // BUG-10 FIX: Use raw Redis GET, not cache->get().
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
     * Atomic increment via Redis or DB.
     *
     * BUG-018 FIX: Replaced TOCTOU SELECT+INSERT/UPDATE with atomic
     * INSERT ... ON DUPLICATE KEY UPDATE for the DB fallback.
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

                // BUG-10 FIX: Use native Redis INCR — single atomic operation.
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

        // BUG-018 FIX: Atomic upsert — no TOCTOU race
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

    private function logWarning(string $message): void
    {
        try {
            if ($this->container->has(\OwnPay\Service\System\Logger::class)) {
                $this->container->get(\OwnPay\Service\System\Logger::class)->warning($message);
            }
        } catch (\Throwable) {}
    }
}
