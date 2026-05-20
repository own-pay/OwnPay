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
        if (self::$settingsRepo !== null) {
            try {
                $value = self::$settingsRepo->get('runtime', $key);
                if ($value !== null) {
                    self::$cache[$cacheKey] = $value;
                    return $value;
                }
            } catch (\Throwable) {
                // DB not available — fall through
            }
        } else {
            // Fallback for tests (SQLite memory with op_env)
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
                // Ignore and fall through
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

        if (self::$settingsRepo !== null) {
            try {
                self::$settingsRepo->set('runtime', $key, $value);
            } catch (\Throwable) {
                // DB not available
            }
        } else {
            // Fallback for tests
            $dbPrefix = $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';
            $now = date('Y-m-d H:i:s');
            try {
                $pdo = \OwnPay\Core\Database::getInstance()->pdo();
                // Check if row exists
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
                // Ignore
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

        if (self::$settingsRepo !== null) {
            try {
                self::$settingsRepo->deleteSetting('runtime', $key);
                return true;
            } catch (\Throwable) {
                return false;
            }
        } else {
            // Fallback for tests
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
     * Clear the in-memory cache.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
