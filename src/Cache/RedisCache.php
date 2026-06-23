<?php
declare(strict_types=1);

namespace OwnPay\Cache;

/**
 * Redis-based cache driver - VPS/dedicated server.
 *
 * Requires ext-redis. Falls back gracefully if Redis unavailable.
 * Prefix isolates OwnPay keys from other apps sharing same Redis.
 */
final class RedisCache implements CacheInterface
{
    private \Redis $redis;
    private string $prefix;

    /**
     * @throws \RedisException If connection fails
     */
    public function __construct(string $host = '127.0.0.1', int $port = 6379, string $prefix = 'op:')
    {
        $this->prefix = $prefix;
        $this->redis = new \Redis();

        if (!$this->redis->connect($host, $port, 2.0)) {
            throw new \RuntimeException("Cannot connect to Redis at {$host}:{$port}");
        }

        // Select DB 0 for cache
        $this->redis->select(0);
    }

    public function get(string $key): mixed
    {
        $raw = $this->redis->get($this->prefix . $key);

        if (!is_string($raw)) {
            return null;
        }

        // Restrict unserialize - no object instantiation (prevents RCE via gadget chains)
        $data = @unserialize($raw, ['allowed_classes' => false]);
        return $data !== false ? $data : null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $serialized = serialize($value);
        $prefixedKey = $this->prefix . $key;

        if ($ttl > 0) {
            $this->redis->setex($prefixedKey, $ttl, $serialized);
        } else {
            $this->redis->set($prefixedKey, $serialized);
        }
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->prefix . $key);
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function flush(): void
    {
        // Only flush keys with our prefix - not the entire Redis
        $cursor = null;
        $pattern = $this->prefix . '*';

        do {
            $result = $this->redis->scan($cursor, $pattern, 100);
            if ($result !== false && count($result) > 0) {
                $this->redis->del(...$result);
            }
        } while ($cursor > 0);
    }

    public function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * Get underlying Redis instance for advanced operations.
     */
    public function redis(): \Redis
    {
        return $this->redis;
    }
}
