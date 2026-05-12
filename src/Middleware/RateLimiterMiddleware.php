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
    }

    private function buildKey(Request $request): string
    {
        $ip = $request->ip();
        $prefix = explode('/', trim($request->path(), '/'));
        $pathKey = implode('.', array_slice($prefix, 0, 3));
        return "rl:{$ip}:{$pathKey}";
    }

    private function getHits(string $key, int $now, int $window): int
    {
        // Try Redis first
        if ($this->container->has(\OwnPay\Cache\RedisCache::class)) {
            try {
                /** @var \OwnPay\Cache\RedisCache $cache */
                $cache = $this->container->get(\OwnPay\Cache\RedisCache::class);
                return (int) ($cache->get($key) ?? 0);
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

    private function increment(string $key, int $now, int $window): void
    {
        // Try Redis
        if ($this->container->has(\OwnPay\Cache\RedisCache::class)) {
            try {
                /** @var \OwnPay\Cache\RedisCache $cache */
                $cache = $this->container->get(\OwnPay\Cache\RedisCache::class);
                $current = (int) ($cache->get($key) ?? 0);
                $cache->set($key, $current + 1, $window);
                return;
            } catch (\Throwable $e) {
                $this->logWarning('Redis increment failed: ' . $e->getMessage());
            }
        }

        // DB fallback — upsert
        $db = $this->container->get(\OwnPay\Core\Database::class);
        $expires = $now + $window;

        $existing = $db->fetchOne(
            "SELECT id, hits FROM op_rate_limits WHERE key_name = :k AND expires_at > :now LIMIT 1",
            ['k' => $key, 'now' => $now]
        );

        if ($existing) {
            $db->update(
                "UPDATE op_rate_limits SET hits = hits + 1 WHERE id = :id",
                ['id' => $existing['id']]
            );
        } else {
            $db->delete("DELETE FROM op_rate_limits WHERE expires_at <= :now", ['now' => $now]);
            $db->insert(
                "INSERT INTO op_rate_limits (key_name, hits, window_start, expires_at) VALUES (:k, 1, :ws, :exp)",
                ['k' => $key, 'ws' => $now, 'exp' => $expires]
            );
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
