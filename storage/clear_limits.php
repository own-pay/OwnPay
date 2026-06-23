<?php
declare(strict_types=1);

/**
 * OwnPay Rate Limit Purge / Clear Script
 *
 * Standalone CLI utility to force clean/truncate rate limits from
 * both the MySQL database and Redis (if configured).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
$kernel = new \OwnPay\Kernel();

// Boot application kernel and get container
$reflection = new \ReflectionClass($kernel);
$bootMethod = $reflection->getMethod('boot');
$bootMethod->setAccessible(true);
$bootMethod->invoke($kernel);

$container = $kernel->getContainer();
$pdo = $container->get(\PDO::class);

echo "\033[36m[OwnPay Rate Limit Clear] Cleaning rate limits...\033[0m\n";

try {
    $stmt = $pdo->exec("TRUNCATE TABLE `op_rate_limits`");
    echo "  -> MySQL `op_rate_limits` truncated successfully.\n";
} catch (\PDOException $e) {
    echo "\033[31m[MySQL Error] Failed to truncate table: " . $e->getMessage() . "\033[0m\n";
}

// Try clearing Redis if configured
if ($container->has(\OwnPay\Cache\RedisCache::class)) {
    try {
        $cache = $container->get(\OwnPay\Cache\RedisCache::class);
        if ($cache instanceof \OwnPay\Cache\RedisCache) {
            $redis = $cache->redis();
            $keys = $redis->keys('op:*');
            $clearedCount = 0;
            if (!empty($keys)) {
                foreach ($keys as $key) {
                    if (str_contains($key, ':rl:') || str_contains($key, 'rate_limit') || str_contains($key, 'limit')) {
                        $redis->del($key);
                        $clearedCount++;
                    }
                }
            }
            echo "  -> Redis rate limit keys cleared successfully (deleted {$clearedCount} keys).\n";
        }
    } catch (\Throwable $e) {
        echo "  -> Redis Cache clear encountered error: " . $e->getMessage() . "\n";
    }
} else {
    // Fallback direct connection check if Redis extension is loaded and we want to be safe
    if (class_exists('Redis')) {
        try {
            $redis = new Redis();
            if (@$redis->connect('127.0.0.1', 6379)) {
                $keys = $redis->keys('op:*');
                $clearedCount = 0;
                if (!empty($keys)) {
                    foreach ($keys as $key) {
                        if (str_contains($key, ':rl:') || str_contains($key, 'rate_limit') || str_contains($key, 'limit')) {
                            $redis->del($key);
                            $clearedCount++;
                        }
                    }
                }
                echo "  -> Redis direct clean: deleted {$clearedCount} keys.\n";
            }
        } catch (\Throwable $e) {
            // Ignore Redis fallback errors
        }
    }
}

echo "\033[32m[SUCCESS] Rate limits cleared successfully!\033[0m\n";
