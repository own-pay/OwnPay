<?php
declare(strict_types=1);

namespace OwnPay\Service;

use OwnPay\Core\Database;
use PDO;
use PDOException;

/**
 * Modern replacement for procedural get_env() and set_env().
 *
 * Reads/writes settings from the op_env table. Includes an in-memory
 * cache to avoid repeated DB queries within the same request.
 *
 * Key difference from legacy get_env(): does NOT auto-create missing rows
 * on read. Use set() explicitly to create entries.
 */
final class EnvironmentService
{
    /** @var array<string, string> In-memory cache keyed by "{brand_id}:{option_name}" */
    private static array $cache = [];

    /** @var bool Whether the full cache has been warmed */
    private static bool $warmed = false;

    /**
     * Get an environment setting value.
     *
     * Replaces: get_env()
     *
     * @param string $optionName Setting key (e.g. 'my-plugin-api_key')
     * @param string $brandId    Brand scope ('both' for global)
     * @return string Setting value, or '' if not found
     */
    public static function get(string $optionName, string $brandId = 'both'): string
    {
        $cacheKey = "{$brandId}:{$optionName}";

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $dbPrefix = $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';

        try {
            $pdo = Database::getInstance()->getPdo();
            $stmt = $pdo->prepare(
                "SELECT `value` FROM `{$dbPrefix}env` WHERE `brand_id` = :brand_id AND `option_name` = :option_name LIMIT 1"
            );
            $stmt->execute([':brand_id' => $brandId, ':option_name' => $optionName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $value = $row ? ($row['value'] ?? '') : '';
            self::$cache[$cacheKey] = $value;

            return $value;
        } catch (PDOException $e) {
            error_log("EnvironmentService::get error: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Set an environment setting value (upsert).
     *
     * Replaces: set_env()
     *
     * @param string $optionName Setting key
     * @param string $value      Setting value
     * @param string $brandId    Brand scope ('both' for global)
     * @return string The value that was set
     */
    public static function set(string $optionName, string $value, string $brandId = 'both'): string
    {
        $dbPrefix = $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';
        $now = date('Y-m-d H:i:s');

        try {
            $pdo = Database::getInstance()->getPdo();

            // Check if row exists
            $stmt = $pdo->prepare(
                "SELECT `id` FROM `{$dbPrefix}env` WHERE `brand_id` = :brand_id AND `option_name` = :option_name LIMIT 1"
            );
            $stmt->execute([':brand_id' => $brandId, ':option_name' => $optionName]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

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
                    ':option_name' => $optionName,
                    ':value'       => $value,
                    ':created'     => $now,
                    ':updated'     => $now,
                ]);
            }

            // Update cache
            $cacheKey = "{$brandId}:{$optionName}";
            self::$cache[$cacheKey] = $value;

            return $value;
        } catch (PDOException $e) {
            error_log("EnvironmentService::set error: " . $e->getMessage());
            return $value;
        }
    }

    /**
     * Delete an environment setting.
     *
     * @param string $optionName Setting key
     * @param string $brandId    Brand scope
     * @return bool True on success
     */
    public static function delete(string $optionName, string $brandId = 'both'): bool
    {
        $dbPrefix = $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';

        try {
            $pdo = Database::getInstance()->getPdo();
            $stmt = $pdo->prepare(
                "DELETE FROM `{$dbPrefix}env` WHERE `brand_id` = :brand_id AND `option_name` = :option_name"
            );
            $result = $stmt->execute([':brand_id' => $brandId, ':option_name' => $optionName]);

            // Evict from cache
            $cacheKey = "{$brandId}:{$optionName}";
            unset(self::$cache[$cacheKey]);

            return $result;
        } catch (PDOException $e) {
            error_log("EnvironmentService::delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear the in-memory cache.
     *
     * @internal For testing only.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$warmed = false;
    }
}
