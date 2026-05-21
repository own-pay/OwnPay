<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Repository\SettingsRepository;

/**
 * Service orchestrating runtime environment detection and DB-backed configuration access.
 *
 * Provides utilities to inspect runtime modes (production, development, staging)
 * and accesses the persistent key/value configuration stored within the database.
 * Persistent options map to `op_system_settings` under the 'runtime' settings group,
 * falling back to individual `op_env` records or standard environment variables.
 */
final class EnvironmentService
{
    /**
     * Retrieves the current application environment identifier.
     *
     * @return string The resolved environment name (e.g., 'production', 'development').
     */
    public static function mode(): string
    {
        return getenv('APP_ENV') ?: 'production';
    }

    /**
     * Determines if the application is running in production mode.
     *
     * @return bool True if active environment is production, false otherwise.
     */
    public static function isProduction(): bool
    {
        return self::mode() === 'production';
    }

    /**
     * Determines if the application is running in development mode.
     *
     * @return bool True if active environment matches dev configurations, false otherwise.
     */
    public static function isDevelopment(): bool
    {
        return in_array(self::mode(), ['development', 'dev', 'local'], true);
    }

    /**
     * Determines if the application is running in staging mode.
     *
     * @return bool True if active environment is staging, false otherwise.
     */
    public static function isStaging(): bool
    {
        return self::mode() === 'staging';
    }

    /**
     * Evaluates if debugging output is activated.
     *
     * @return bool True if debug variables are set to 'true', false otherwise.
     */
    public static function debugEnabled(): bool
    {
        return (getenv('APP_DEBUG') ?: 'false') === 'true';
    }

    /**
     * Retrieves the current system version specifier.
     *
     * @return string The defined version identifier.
     */
    public static function version(): string
    {
        return getenv('APP_VERSION') ?: '0.1.0';
    }

    /**
     * Evaluates core PHP and critical extension requirements.
     *
     * Checks loaded modules and PHP versions to ensure the environment is fit for operations.
     *
     * @return string[] Array of error messages detailing unmet environmental requirements. Empty if all checks pass.
     */
    public static function checkRequirements(): array
    {
        $errors = [];

        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $errors[] = 'PHP 8.2+ required (current: ' . PHP_VERSION . ')';
        }

        $requiredExtensions = ['pdo', 'pdo_mysql', 'openssl', 'mbstring', 'json', 'curl', 'bcmath'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = "PHP extension required: {$ext}";
            }
        }

        return $errors;
    }

    /**
     * Generates a descriptive map of the current runtime configuration.
     *
     * @return array{php_version: string, os: string, sapi: string, memory_limit: string|false, max_upload: string|false, timezone: string, extensions: string[]} Array describing server stats.
     */
    public static function serverInfo(): array
    {
        return [
            'php_version'  => PHP_VERSION,
            'os'           => PHP_OS,
            'sapi'         => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'max_upload'   => ini_get('upload_max_filesize'),
            'timezone'     => date_default_timezone_get(),
            'extensions'   => get_loaded_extensions(),
        ];
    }

    // ——— Persistent Key-Value Store (DB-backed) ————————————————

    /**
     * In-memory cache holding resolved configuration variables.
     *
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * Handles physical database writes/reads for system configuration.
     *
     * @var SettingsRepository|null
     */
    private static ?SettingsRepository $settingsRepo = null;

    /**
     * Initialises the environment service with the persistent database repository.
     *
     * Must be invoked during core system kernel initialization.
     *
     * @param SettingsRepository $repo Active database repository instance.
     * @return void
     */
    public static function boot(SettingsRepository $repo): void
    {
        self::$settingsRepo = $repo;
    }

    /**
     * Retrieves a persistent setting value by its configuration key.
     *
     * Resolves settings using a cascading fallback model:
     * 1. Check local static variable cache.
     * 2. Read from settings repository (stored in database settings table).
     * 3. Fallback to SQL database env table (primarily in unit test environments).
     * 4. Retrieve value from system-level environment variables.
     *
     * @param string $key Settings key selector.
     * @param string $brandId Brand identifier for isolated scopes (defaults to 'both').
     * @return string The resolved setting configuration value.
     */
    public static function get(string $key, string $brandId = 'both'): string
    {
        $cacheKey = "{$brandId}:{$key}";

        // Check local in-memory cache
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // DB repository lookup
        if (self::$settingsRepo !== null) {
            try {
                $value = self::$settingsRepo->get('runtime', $key);
                if ($value !== null) {
                    self::$cache[$cacheKey] = $value;
                    return $value;
                }
            } catch (\Throwable) {
                // Database not available — fall through to options
            }
        } else {
            // Fallback for tests (SQLite memory with op_env table)
            $dbPrefix = $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';
            try {
                $pdo = \OwnPay\Core\Database::getInstance()->pdo();
                $stmt = $pdo->prepare(
                    "SELECT `value` FROM `{$dbPrefix}env` WHERE `brand_id` = :brand_id AND `option_name` = :option_name LIMIT 1"
                );
                $stmt->execute([':brand_id' => $brandId, ':option_name' => $key]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                $value = $row ? ($row['value'] ?? '') : '';
                self::$cache[$cacheKey] = $value;

                return $value;
            } catch (\PDOException) {
                // Ignore and fall through to environment variables
            }
        }

        // System environment variable fallback
        $env = getenv($key);
        $value = $env !== false ? $env : '';
        self::$cache[$cacheKey] = $value;
        return $value;
    }

    /**
     * Persists a runtime configuration setting value.
     *
     * Updates both the local runtime memory cache and writes to the DB repository
     * or tests fallback DB table structures.
     *
     * @param string $key Configuration key selector.
     * @param string $value Target setting content to persist.
     * @param string $brandId Scope identifier targeting the configuration update.
     * @return string The saved setting value.
     */
    public static function set(string $key, string $value, string $brandId = 'both'): string
    {
        $cacheKey = "{$brandId}:{$key}";
        self::$cache[$cacheKey] = $value;

        if (self::$settingsRepo !== null) {
            try {
                self::$settingsRepo->set('runtime', $key, $value);
            } catch (\Throwable) {
                // Database not available
            }
        } else {
            // Fallback execution paths for automated unit test routines
            $dbPrefix = $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';
            $now = date('Y-m-d H:i:s');
            try {
                $pdo = \OwnPay\Core\Database::getInstance()->pdo();
                // Check if row already exists
                $stmt = $pdo->prepare(
                    "SELECT `id` FROM `{$dbPrefix}env` WHERE `brand_id` = :brand_id AND `option_name` = :option_name LIMIT 1"
                );
                $stmt->execute([':brand_id' => $brandId, ':option_name' => $key]);
                $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($existing) {
                    $stmt = $pdo->prepare(
                        "UPDATE `{$dbPrefix}env` SET `value` = :value, `updated_date` = :updated WHERE `id` = :id"
                    );
                    $stmt->execute([':value' => $value, ':updated' => $now, ':id' => $existing['id']]);
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO `{$dbPrefix}env` (`brand_id`, `option_name`, `value`, `created_date`, `updated_date`) VALUES (:brand_id, :option_name, :value, :created, :updated)"
                    );
                    $stmt->execute([
                        ':brand_id'    => $brandId,
                        ':option_name' => $key,
                        ':value'       => $value,
                        ':created'     => $now,
                        ':updated'     => $now,
                    ]);
                }
            } catch (\PDOException) {
                // Ignore errors during fallback
            }
        }

        return $value;
    }

    /**
     * Removes a stored environment config variable from database and local cache.
     *
     * @param string $key Target configuration key to remove.
     * @param string $brandId Isolated setting brand scope.
     * @return bool True if removal completed successfully, false otherwise.
     */
    public static function delete(string $key, string $brandId = 'both'): bool
    {
        $cacheKey = "{$brandId}:{$key}";
        unset(self::$cache[$cacheKey]);

        if (self::$settingsRepo !== null) {
            try {
                self::$settingsRepo->deleteSetting('runtime', $key);
                return true;
            } catch (\Throwable) {
                return false;
            }
        } else {
            // Test framework DB verification paths
            $dbPrefix = $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';
            try {
                $pdo = \OwnPay\Core\Database::getInstance()->pdo();
                $stmt = $pdo->prepare(
                    "DELETE FROM `{$dbPrefix}env` WHERE `brand_id` = :brand_id AND `option_name` = :option_name"
                );
                return $stmt->execute([':brand_id' => $brandId, ':option_name' => $key]);
            } catch (\PDOException) {
                return false;
            }
        }
    }

    /**
     * Clears all configurations stored in the memory cache.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
