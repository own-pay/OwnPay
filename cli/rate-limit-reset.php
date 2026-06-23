<?php
declare(strict_types=1);

/**
 * OwnPay Rate Limit Reset & Signed URL Generator CLI
 */

// Define project paths
$projectRoot = dirname(__DIR__);
$autoload = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    fwrite(STDERR, "Composer autoload not found. Run composer install.\n");
    exit(1);
}
require_once $autoload;

// Set up output styling helpers
define('CLI_RESET', "\033[0m");
define('CLI_BOLD', "\033[1m");
define('CLI_GREEN', "\033[32m");
define('CLI_RED', "\033[31m");
define('CLI_YELLOW', "\033[33m");
define('CLI_BLUE', "\033[34m");
define('CLI_CYAN', "\033[36m");

// Parse arguments
$options = getopt('', ['ip:', 'all', 'generate-url', 'expires:']);

if (empty($options) || (isset($options['generate-url']) && !isset($options['ip']))) {
    echo CLI_CYAN . CLI_BOLD . "OwnPay Rate Limit Reset CLI" . CLI_RESET . "\n";
    echo "Usage:\n";
    echo "  " . CLI_GREEN . "php cli/rate-limit-reset.php --ip=<ip_address>" . CLI_RESET . "    Reset all rate limits for a specific IP\n";
    echo "  " . CLI_GREEN . "php cli/rate-limit-reset.php --all" . CLI_RESET . "                 Reset ALL rate limits globally\n";
    echo "  " . CLI_GREEN . "php cli/rate-limit-reset.php --generate-url --ip=<ip_address> [--expires=<seconds>]" . CLI_RESET . "\n";
    echo "                                           Generate a secure HMAC-signed reset URL (default expires: 300s)\n";
    exit(0);
}

try {
    // Boot Kernel and retrieve Container using reflection
    $kernel = new \OwnPay\Kernel();
    $ref = new \ReflectionClass($kernel);
    $bootMethod = $ref->getMethod('boot');
    $bootMethod->setAccessible(true);
    $bootMethod->invoke($kernel);

    $containerProperty = $ref->getProperty('container');
    $containerProperty->setAccessible(true);
    /** @var \OwnPay\Container $container */
    $container = $containerProperty->getValue($kernel);

    $db = $container->get(\OwnPay\Core\Database::class);
    if (!$db instanceof \OwnPay\Core\Database) {
        throw new \RuntimeException('Database connection could not be resolved from Container.');
    }

    if (isset($options['all'])) {
        // Reset ALL globally
        echo CLI_YELLOW . "Flushing all rate limits globally..." . CLI_RESET . "\n";

        // Delete from DB
        $db->execute("DELETE FROM op_rate_limits");

        // Delete from Redis
        if ($container->has(\OwnPay\Cache\RedisCache::class)) {
            try {
                $cache = $container->get(\OwnPay\Cache\RedisCache::class);
                if ($cache instanceof \OwnPay\Cache\RedisCache) {
                    $redis = $cache->redis();
                    $cursor = null;
                    $pattern = 'op:rl:*';
                    do {
                        $result = $redis->scan($cursor, $pattern, 100);
                        if ($result !== false && count($result) > 0) {
                            $redis->del(...$result);
                        }
                    } while ($cursor > 0);
                }
            } catch (\Throwable $e) {
                echo CLI_RED . "Redis flush failed: " . $e->getMessage() . CLI_RESET . "\n";
            }
        }

        echo CLI_GREEN . CLI_BOLD . "SUCCESS: All rate limits have been cleared globally." . CLI_RESET . "\n";
        exit(0);
    }

    $ipOpt = $options['ip'] ?? '';
    if (is_array($ipOpt)) {
        $ipOpt = end($ipOpt);
    }
    $ip = is_string($ipOpt) ? trim($ipOpt) : '';

    if ($ip === '') {
        echo CLI_RED . "ERROR: IP address is required." . CLI_RESET . "\n";
        exit(1);
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo CLI_RED . "ERROR: Invalid IP address format: '{$ip}'." . CLI_RESET . "\n";
        exit(1);
    }

    if (isset($options['generate-url'])) {
        // Generate Signed URL
        $expiresOpt = $options['expires'] ?? null;
        if (is_array($expiresOpt)) {
            $expiresOpt = end($expiresOpt);
        }
        $expiresSec = is_numeric($expiresOpt) ? (int) $expiresOpt : 300;
        $expirationTime = time() + $expiresSec;

        $appKeyRaw = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? '');
        $appKey = is_string($appKeyRaw) ? $appKeyRaw : '';
        if ($appKey === '') {
            echo CLI_RED . "ERROR: APP_KEY is not configured on the server. Cannot sign URLs." . CLI_RESET . "\n";
            exit(1);
        }

        $payload = 'ip=' . $ip . '&expires=' . $expirationTime;
        $signature = hash_hmac('sha256', $payload, $appKey);

        // Resolve App URL from Settings or ENV
        $settings = $container->get(\OwnPay\Repository\SettingsRepository::class);
        $appUrl = '';
        if ($settings instanceof \OwnPay\Repository\SettingsRepository) {
            $appUrlVal = $settings->get('general', 'base_url', '');
            $appUrl = is_string($appUrlVal) ? trim($appUrlVal) : '';
        }
        if ($appUrl === '') {
            $envUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? 'http://localhost');
            $appUrl = is_string($envUrl) ? $envUrl : 'http://localhost';
        }

        $url = rtrim($appUrl, '/') . '/rate-limit/emergency-reset?ip=' . urlencode($ip) . '&expires=' . $expirationTime . '&signature=' . $signature;

        echo CLI_GREEN . CLI_BOLD . "SUCCESS: Signed Emergency Reset URL Generated!" . CLI_RESET . "\n";
        echo "IP Target:   " . CLI_CYAN . $ip . CLI_RESET . "\n";
        echo "Expires In:  " . CLI_YELLOW . $expiresSec . " seconds" . CLI_RESET . " (at " . date('Y-m-d H:i:s', $expirationTime) . ")\n\n";
        echo CLI_BOLD . "Reset URL:" . CLI_RESET . "\n" . $url . "\n\n";
        echo CLI_YELLOW . "Visit this URL in any browser to immediately clear all limits for this IP." . CLI_RESET . "\n";
        exit(0);
    }

    // Reset specific IP
    echo CLI_YELLOW . "Flushing rate limits for IP: {$ip}..." . CLI_RESET . "\n";

    // Delete from DB
    $db->execute("DELETE FROM op_rate_limits WHERE key_name LIKE :pattern", ['pattern' => "rl:{$ip}:%"]);

    // Delete from Redis
    if ($container->has(\OwnPay\Cache\RedisCache::class)) {
        try {
            $cache = $container->get(\OwnPay\Cache\RedisCache::class);
            if ($cache instanceof \OwnPay\Cache\RedisCache) {
                $redis = $cache->redis();
                $cursor = null;
                $pattern = 'op:rl:' . $ip . ':*';
                do {
                    $result = $redis->scan($cursor, $pattern, 100);
                    if ($result !== false && count($result) > 0) {
                        $redis->del(...$result);
                    }
                } while ($cursor > 0);
            }
        } catch (\Throwable $e) {
            echo CLI_RED . "Redis key deletion failed: " . $e->getMessage() . CLI_RESET . "\n";
        }
    }

    echo CLI_GREEN . CLI_BOLD . "SUCCESS: Rate limits for IP '{$ip}' have been cleared." . CLI_RESET . "\n";
    exit(0);

} catch (\Throwable $e) {
    echo CLI_RED . CLI_BOLD . "CRITICAL ERROR: " . $e->getMessage() . CLI_RESET . "\n";
    exit(1);
}
