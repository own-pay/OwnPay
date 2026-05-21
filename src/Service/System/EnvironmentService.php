<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Repository\SettingsRepository;

/**
 * Environment service — runtime environment detection and DB-backed key/value store.
 *
 * get()/set() persist to op_system_settings (group: 'runtime') with in-memory cache.
 */
final class EnvironmentService
{
    /**
     * Get current environment mode.
     */
    public static function mode(): string
    {
        return getenv('APP_ENV') ?: 'production';
    }

    public static function isProduction(): bool
    {
        return self::mode() === 'production';
    }

    public static function isDevelopment(): bool
    {
        return in_array(self::mode(), ['development', 'dev', 'local'], true);
    }

    public static function isStaging(): bool
    {
        return self::mode() === 'staging';
    }

    /**
     * Check if debug mode is enabled.
     */
    public static function debugEnabled(): bool
    {
        return (getenv('APP_DEBUG') ?: 'false') === 'true';
    }

    /**
     * Get app version from config/app.php or env.
     */
    public static function version(): string
    {
        return getenv('APP_VERSION') ?: '0.1.0';
    }

    /**
     * Check PHP requirements.
     * @return string[] Errors (empty = OK)
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
     * Get server info.
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

    /** @var array<string, string> In-memory cache */
    private static array $cache = [];

    /** @var ?SettingsRepository Injected via boot */
    private static ?SettingsRepository $settingsRepo = null;

    /**
     * Bootstrap the persistent store with a SettingsRepository.
     * Called once during Kernel boot.
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

        // Memory cache first
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

        // Env fallback
        $env = getenv($key);
        $value = $env !== false ? $env : '';
        self::$cache[$cacheKey] = $value;
        return $value;
    }

    /**
     * Set a persistent runtime value.
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
                // DB not available
            }
        }

        return $value;
    }

    /**
     * Delete an environment setting.
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
     * Clear the in-memory cache.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
