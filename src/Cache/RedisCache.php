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
     * @param string $host Redis hostname or IP.
     * @param int $port Redis TCP port.
     * @param string $prefix Key prefix used to isolate OwnPay keys from
     *                        other applications sharing the same Redis DB.
     * @param string|null $password Optional AUTH password. When non-null,
     *                              auth() is called after connect(). Required
     *                              for any Redis instance configured with
     *                              `requirepass` (CACHE-2).
     * @param string|null $username Optional Redis 6+ ACL username. When both
     *                               $username and $password are non-null,
     *                               auth([$username, $password]) is used
     *                               (the ACL form).
     * @param int $database Redis DB index (0-15). Defaults to 0. Lets
     *                       multiple OwnPay instances (or OwnPay + other
     *                       apps) share a single Redis server.
     * @throws \RedisException If connection fails.
     * @throws \RuntimeException If authentication is configured but fails.
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $prefix = 'op:',
        ?string $password = null,
        ?string $username = null,
        int $database = 0
    ) {
        $this->prefix = $prefix;
        $this->redis = new \Redis();

        if (!$this->redis->connect($host, $port, 2.0)) {
            throw new \RuntimeException("Cannot connect to Redis at {$host}:{$port}");
        }

        // CACHE-2: authenticate when credentials are configured. Previously
        // the constructor unconditionally called select(0) with no auth() -
        // operators who followed Redis security best practices (requirepass
        // / ACL) could not use the Redis driver at all, because every
        // command returned NOAUTH Authentication required. The practical
        // outcome was that operators either disabled Redis auth entirely
        // (exposing the cache to the network - a real CVE-class
        // misconfiguration) or fell back to FileCache. Now: if $password is
        // provided, authenticate. Redis 6+ ACL uses [username, password];
        // legacy requirepass uses just the password string.
        if ($password !== null && $password !== '') {
            if ($username !== null && $username !== '') {
                $authOk = $this->redis->auth([$username, $password]);
            } else {
                $authOk = $this->redis->auth($password);
            }
            if ($authOk !== true) {
                throw new \RuntimeException("Redis authentication failed for {$host}:{$port}");
            }
        }

        // Select the configured DB index (defaults to 0). Lets multiple
        // OwnPay instances (or OwnPay + other apps) share a single Redis
        // server by using different DB indexes.
        $this->redis->select($database);
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

    public function add(string $key, mixed $value, int $ttl = 3600): bool
    {
        $serialized = serialize($value);
        $prefixedKey = $this->prefix . $key;

        // Atomic set-if-not-exists via SET ... NX [EX ttl]. This is a single
        // Redis round-trip and is atomic server-side, so concurrent callers
        // cannot both succeed - exactly the semantics required for a
        // distributed lock primitive (e.g. TOTP replay protection).
        // Returns true on success, false if NX condition was not met.
        if ($ttl > 0) {
            $result = $this->redis->set($prefixedKey, $serialized, ["nx", "ex" => $ttl]);
        } else {
            $result = $this->redis->set($prefixedKey, $serialized, ["nx"]);
        }
        return $result === true;
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
