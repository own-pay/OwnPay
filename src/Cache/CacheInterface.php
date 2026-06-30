<?php
declare(strict_types=1);

namespace OwnPay\Cache;

/**
 * Cache driver contract.
 *
 * Implementations: FileCache (shared hosting), RedisCache (VPS).
 * Container auto-selects based on CACHE_DRIVER env var.
 */
interface CacheInterface
{
    /**
     * Retrieve a cached value.
     *
     * @return mixed|null Null if not found or expired
     */
    public function get(string $key): mixed;

    /**
     * Store a value in cache.
     *
     * @param string $key
     * @param mixed  $value Must be serializable
     * @param int    $ttl   Time-to-live in seconds. 0 = forever.
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void;

    /**
     * Check if a key exists and is not expired.
     */
    public function has(string $key): bool;

    /**
     * Remove a cached value.
     */
    public function delete(string $key): void;

    /**
     * Clear all cached values.
     */
    public function flush(): void;

    /**
     * Get or set - if key exists return cached, otherwise call $callback, cache, and return.
     *
     * @param string   $key
     * @param callable $callback fn(): mixed
     * @param int      $ttl
     * @return mixed
     */
    public function remember(string $key, callable $callback, int $ttl = 3600): mixed;
}
