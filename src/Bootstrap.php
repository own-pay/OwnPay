<?php

declare(strict_types=1);

namespace OwnPay;

use OwnPay\Core\Database;

/**
 * Own Pay Bootstrap
 *
 * Entry point for the new service layer.
 * Loads Composer autoloader and initializes the Database singleton.
 *
 * Usage:
 *   require __DIR__ . '/src/Bootstrap.php';
 *   \OwnPay\Bootstrap::init();
 */
final class Bootstrap
{
    private static bool $initialized = false;

    /**
     * Initialize the Own Pay service layer.
     *
     * @param array $dbConfig Optional DB config override.
     *   Keys: host, name, user, pass, port (all strings except port=int)
     *   If omitted, reads from global $db_host, $db_user, $db_pass, $db_name.
     */
    public static function init(array $dbConfig = []): void
    {
        if (self::$initialized) {
            return;
        }

        // Load Composer autoloader
        $autoloader = dirname(__DIR__) . '/vendor/autoload.php';
        if (!file_exists($autoloader)) {
            throw new \RuntimeException(
                'Composer autoloader not found. Run: composer install'
            );
        }
        require_once $autoloader;

        // Resolve DB credentials
        $host = $dbConfig['host'] ?? $GLOBALS['db_host'] ?? '127.0.0.1';
        $name = $dbConfig['name'] ?? $GLOBALS['db_name'] ?? 'ownpay';
        $user = $dbConfig['user'] ?? $GLOBALS['db_user'] ?? 'root';
        $pass = $dbConfig['pass'] ?? $GLOBALS['db_pass'] ?? '';
        $port = (int) ($dbConfig['port'] ?? 3306);

        // Initialize Database singleton
        Database::init($host, $name, $user, $pass, $port);

        // Set defaults
        if (date_default_timezone_get() !== 'UTC') {
            date_default_timezone_set('UTC');
        }

        bcscale(8);

        self::$initialized = true;
    }

    /**
     * Check if Bootstrap has been initialized.
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Reset state (testing only).
     * @internal
     */
    public static function reset(): void
    {
        self::$initialized = false;
        Database::reset();
    }
}
