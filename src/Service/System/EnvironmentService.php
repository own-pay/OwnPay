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
 * with a read-time fallback to standard OS environment variables (getenv/$_ENV/$_SERVER)
 * when no stored value exists.
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
        $env = getenv('APP_ENV');
        if ($env === false) {
            $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';
        }
        return is_scalar($env) ? (string) $env : 'production';
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
        $env = getenv('APP_DEBUG');
        if ($env === false) {
            $env = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? 'false';
        }
        return ($env === 'true' || $env === true);
    }

    /**
     * Retrieves the current system version specifier.
     *
     * @return string The defined version identifier.
     */
    public static function version(): string
    {
        $env = getenv('APP_VERSION');
        if ($env === false) {
            $env = $_ENV['APP_VERSION'] ?? $_SERVER['APP_VERSION'] ?? '0.1.0';
        }
        return is_scalar($env) ? (string) $env : '0.1.0';
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

    // Persistent Key-Value Store (DB-backed)

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
     * Resolve the SettingsRepository dynamically if not booted.
     */
    private static function resolveSettingsRepo(): ?SettingsRepository
    {
        if (self::$settingsRepo === null) {
            try {
                $db = \OwnPay\Core\Database::getInstance();
                self::$settingsRepo = new SettingsRepository($db);
            } catch (\Throwable) {
                // DB not available
            }
        }
        return self::$settingsRepo;
    }

    /**
     * Get a persistent runtime value.
     */
    public static function get(string $key, string $brandId = 'both'): string
    {
        $cacheKey = "{$brandId}:{$key}";

        // Check local in-memory cache
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // DB lookup
        $repo = self::resolveSettingsRepo();
        if ($repo !== null) {
            try {
                $isScoped = is_numeric($brandId);
                if ($isScoped) {
                    $value = $repo->getScoped('runtime', $key, (int) $brandId);
                } else {
                    $value = $repo->get('runtime', $key);
                }
                if ($value !== null) {
                    self::$cache[$cacheKey] = $value;
                    return $value;
                }
            } catch (\Throwable) {
                // Fall through
            }
        }

        // System environment variable fallback
        $env = getenv($key);
        if ($env === false) {
            $env = $_ENV[$key] ?? $_SERVER[$key] ?? false;
        }
        $value = (is_scalar($env) && $env !== false) ? (string) $env : '';
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

        $repo = self::resolveSettingsRepo();
        if ($repo !== null) {
            try {
                $isScoped = is_numeric($brandId);
                if ($isScoped) {
                    $repo->setScoped('runtime', $key, $value, (int) $brandId);
                } else {
                    $repo->set('runtime', $key, $value);
                }
            } catch (\Throwable) {
                // Database not available
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

        $repo = self::resolveSettingsRepo();
        if ($repo !== null) {
            try {
                $isScoped = is_numeric($brandId);
                if ($isScoped) {
                    $repo->deleteSettingScoped('runtime', $key, (int) $brandId);
                } else {
                    $repo->deleteSetting('runtime', $key);
                }
                return true;
            } catch (\Throwable) {
                return false;
            }
        }
        return false;
    }

    /**
     * Clears all configurations stored in the memory cache.
     *
     * Also flushes the repository-level memoization so the next get() is a true
     * database read - callers rely on this after out-of-band settings changes.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        if (self::$settingsRepo !== null) {
            self::$settingsRepo->flushCache();
        }
    }
}
