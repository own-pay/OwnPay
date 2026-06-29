<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for runtime configuration settings (`op_system_settings` table).
 *
 * Supports global system configuration as well as brand-specific/merchant-scoped overrides.
 * Uses a unique constraint on (group_name, key_name, merchant_id) for safe configuration mapping.
 * Implements a cascading resolution model: brand-scoped override → global fallback.
 *
 * @package OwnPay\Repository
 */
final class SettingsRepository extends BaseRepository
{
    /**
     * @var string Database table name.
     */
    protected string $table = 'op_system_settings';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = ['group_name', 'key_name', 'value', 'type', 'merchant_id'];

    /**
     * Per-request memoization of resolved group maps, keyed by
     * "{group}|{merchant_id-or-empty}". Settings are read many times while
     * rendering a single page (brand theme, checkout copy, timers); without this
     * cache every lookup costs one database round-trip. Invalidated on every
     * write/delete so within-request read-after-write stays consistent.
     *
     * @var array<string, array<string, string>>
     */
    private array $groupCache = [];

    /**
     * Per-request memoization of single-key lookups, keyed by "{group}|{key}"
     * (global) or "{group}|{key}|{merchant_id}" (brand-scoped). Stores the raw
     * resolved value (null = key absent) so defaults are applied per call.
     *
     * @var array<string, string|null>
     */
    private array $keyCache = [];

    /**
     * Drops every memoized setting, forcing fresh database reads.
     *
     * Required by callers holding the "clear cache means re-read from DB"
     * contract (e.g. EnvironmentService::clearCache()) and after out-of-band
     * writes such as installer imports.
     */
    public function flushCache(): void
    {
        $this->groupCache = [];
        $this->keyCache = [];
    }

    /**
     * Drops memoized values for a group (global and all brand scopes).
     *
     * @param string $group Setting group category.
     */
    private function forgetGroup(string $group): void
    {
        $prefix = $group . '|';
        foreach (array_keys($this->groupCache) as $cacheKey) {
            if (str_starts_with($cacheKey, $prefix)) {
                unset($this->groupCache[$cacheKey]);
            }
        }
        foreach (array_keys($this->keyCache) as $cacheKey) {
            if (str_starts_with($cacheKey, $prefix)) {
                unset($this->keyCache[$cacheKey]);
            }
        }
    }

    /**
     * Retrieves a global setting value.
     *
     * @param string $group Setting group category.
     * @param string $key Setting key name.
     * @param string|null $default Fallback value if the setting is not found.
     * @return string|null The resolved setting value, or the default value.
     */
    public function get(string $group, string $key, ?string $default = null): ?string
    {
        $cacheKey = $group . '|' . $key;
        if (array_key_exists($cacheKey, $this->keyCache)) {
            return $this->keyCache[$cacheKey] ?? $default;
        }

        $row = $this->db->fetchOne(
            "SELECT value FROM {$this->table} WHERE group_name = :g AND key_name = :k AND merchant_id IS NULL LIMIT 1",
            ['g' => $group, 'k' => $key]
        );
        $val = $row['value'] ?? null;
        $this->keyCache[$cacheKey] = is_scalar($val) ? (string) $val : null;
        return $this->keyCache[$cacheKey] ?? $default;
    }

    /**
     * Stores or updates a global setting.
     *
     * @param string $group Setting group category.
     * @param string $key Setting key name.
     * @param string $value The setting value to save.
     * @param string $type The datatype of the setting (defaults to 'string').
     * @return void
     */
    public function set(string $group, string $key, string $value, string $type = 'string'): void
    {
        $exists = $this->db->exists($this->table, "group_name = :g AND key_name = :k AND merchant_id IS NULL", ['g' => $group, 'k' => $key]);
        if ($exists) {
            $this->db->update(
                "UPDATE {$this->table} SET value = :v, type = :t WHERE group_name = :g AND key_name = :k AND merchant_id IS NULL",
                ['v' => $value, 't' => $type, 'g' => $group, 'k' => $key]
            );
        } else {
            $this->create(['group_name' => $group, 'key_name' => $key, 'value' => $value, 'type' => $type, 'merchant_id' => null]);
        }
        $this->forgetGroup($group);
    }

    /**
     * Retrieves all global settings under a specific group as an associative key-value map.
     *
     * @param string $group Setting group category.
     * @return array<string, string> Associative map of setting keys and values.
     */
    public function getGroup(string $group): array
    {
        $cacheKey = $group . '|';
        if (isset($this->groupCache[$cacheKey])) {
            return $this->groupCache[$cacheKey];
        }

        $rows = $this->db->fetchAll(
            "SELECT key_name, value FROM {$this->table} WHERE group_name = :g AND merchant_id IS NULL",
            ['g' => $group]
        );
        $result = [];
        foreach ($rows as $row) {
            $k = $row['key_name'] ?? '';
            $v = $row['value'] ?? '';
            if (is_string($k) && is_scalar($v)) {
                $result[$k] = (string) $v;
            }
        }
        return $this->groupCache[$cacheKey] = $result;
    }

    /**
     * Updates multiple settings within a group atomically inside a transaction.
     *
     * Commonly invoked from the global administration panel settings forms.
     *
     * @param string $group Setting group category.
     * @param array<string, mixed> $keyValues Associative map of keys and their updated values.
     * @return void
     */
    public function bulkSet(string $group, array $keyValues): void
    {
        $this->db->transaction(function () use ($group, $keyValues) {
            foreach ($keyValues as $key => $value) {
                $vStr = is_scalar($value) ? (string) $value : '';
                $this->set($group, $key, $vStr);
            }
        });
    }

    /**
     * Deletes all settings matching a group (used during plugin uninstallation).
     *
     * @param string $group Setting group category.
     * @return int Number of deleted setting records.
     */
    public function deleteGroup(string $group): int
    {
        $this->forgetGroup($group);
        return $this->db->update(
            "DELETE FROM {$this->table} WHERE group_name = :g",
            ['g' => $group]
        );
    }

    // -- Brand-Scoped Settings

    /**
     * Resolves a configuration value utilizing brand-specific override cascading.
     *
     * Priority resolution flow: merchant brand setting → global fallback → default value.
     *
     * @param string $group Setting group category (e.g. 'plugin.my-gateway').
     * @param string $key Setting key name.
     * @param int $merchantId Brand / Merchant ID.
     * @param string|null $default Fallback value if no configuration exists.
     * @return string|null The resolved setting value, or default fallback.
     */
    public function getScoped(string $group, string $key, int $merchantId, ?string $default = null): ?string
    {
        $cacheKey = $group . '|' . $key . '|' . $merchantId;
        if (array_key_exists($cacheKey, $this->keyCache)) {
            return $this->keyCache[$cacheKey] ?? $default;
        }

        // Try brand-specific first
        $row = $this->db->fetchOne(
            "SELECT value FROM {$this->table} WHERE group_name = :g AND key_name = :k AND merchant_id = :mid LIMIT 1",
            ['g' => $group, 'k' => $key, 'mid' => $merchantId]
        );
        if ($row !== null) {
            $val = $row['value'] ?? null;
            $this->keyCache[$cacheKey] = is_scalar($val) ? (string) $val : null;
            return $this->keyCache[$cacheKey];
        }
        // Fall back to global (memoized in get()); brand-level absence is also
        // cached implicitly by the global key entry on subsequent calls.
        return $this->get($group, $key, $default);
    }

    /**
     * Resolves ONLY a brand's own override for a setting, WITHOUT the global fallback.
     *
     * Unlike {@see getScoped()}, this returns null when the brand has no override of its
     * own. It lets the admin UI distinguish "inherits the All-Brands default" (null) from
     * "explicitly set for this brand" (a concrete value, including an empty string).
     *
     * @param string $group Setting group category.
     * @param string $key Setting key name.
     * @param int $merchantId Brand / Merchant ID.
     * @return string|null The brand-specific value, or null when no override exists.
     */
    public function getScopedOverride(string $group, string $key, int $merchantId): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT value FROM {$this->table} WHERE group_name = :g AND key_name = :k AND merchant_id = :mid LIMIT 1",
            ['g' => $group, 'k' => $key, 'mid' => $merchantId]
        );
        if ($row === null) {
            return null;
        }
        $val = $row['value'] ?? null;
        return is_scalar($val) ? (string) $val : null;
    }

    /**
     * Stores or updates a brand-specific setting override.
     *
     * @param string $group Setting group category.
     * @param string $key Setting key name.
     * @param string $value The setting value.
     * @param int $merchantId The merchant brand ID.
     * @param string $type The setting datatype (defaults to 'string').
     * @return void
     */
    public function setScoped(string $group, string $key, string $value, int $merchantId, string $type = 'string'): void
    {
        $exists = $this->db->exists(
            $this->table,
            "group_name = :g AND key_name = :k AND merchant_id = :mid",
            ['g' => $group, 'k' => $key, 'mid' => $merchantId]
        );
        if ($exists) {
            $this->db->update(
                "UPDATE {$this->table} SET value = :v, type = :t WHERE group_name = :g AND key_name = :k AND merchant_id = :mid",
                ['v' => $value, 't' => $type, 'g' => $group, 'k' => $key, 'mid' => $merchantId]
            );
        } else {
            $this->create([
                'group_name' => $group,
                'key_name' => $key,
                'value' => $value,
                'type' => $type,
                'merchant_id' => $merchantId,
            ]);
        }
        $this->forgetGroup($group);
    }

    /**
     * Updates multiple brand-specific overrides atomically.
     *
     * @param string $group Setting group category.
     * @param array<string, mixed> $keyValues Associative map of keys and values.
     * @param int $merchantId The merchant brand ID.
     * @return void
     */
    public function bulkSetScoped(string $group, array $keyValues, int $merchantId): void
    {
        $this->db->transaction(function () use ($group, $keyValues, $merchantId) {
            foreach ($keyValues as $key => $value) {
                $vStr = is_scalar($value) ? (string) $value : '';
                $this->setScoped($group, $key, $vStr, $merchantId);
            }
        });
    }

    /**
     * Resolves all settings in a group for a specific brand, merging overrides on top of global defaults.
     *
     * @param string $group Setting group category.
     * @param int $merchantId The merchant brand ID.
     * @return array<string, string> Associative map of keys and resolved values.
     */
    public function getGroupScoped(string $group, int $merchantId): array
    {
        $cacheKey = $group . '|' . $merchantId;
        if (isset($this->groupCache[$cacheKey])) {
            return $this->groupCache[$cacheKey];
        }

        // Start with global values
        $result = $this->getGroup($group);
        // Override with brand-specific values
        $rows = $this->db->fetchAll(
            "SELECT key_name, value FROM {$this->table} WHERE group_name = :g AND merchant_id = :mid",
            ['g' => $group, 'mid' => $merchantId]
        );
        foreach ($rows as $row) {
            $k = $row['key_name'] ?? '';
            $v = $row['value'] ?? '';
            if (is_string($k) && is_scalar($v)) {
                $result[$k] = (string) $v;
            }
        }
        return $this->groupCache[$cacheKey] = $result;
    }

    /**
     * Deletes all brand-specific setting overrides for a group.
     *
     * Typically triggered when a merchant uninstalls or disables a plugin integration.
     *
     * @param string $group Setting group category.
     * @param int $merchantId The merchant brand ID.
     * @return int Number of deleted setting records.
     */
    public function deleteGroupScoped(string $group, int $merchantId): int
    {
        $this->forgetGroup($group);
        return $this->db->update(
            "DELETE FROM {$this->table} WHERE group_name = :g AND merchant_id = :mid",
            ['g' => $group, 'mid' => $merchantId]
        );
    }

    /**
     * Deletes a single global setting by its group and key.
     *
     * @param string $group Setting group category.
     * @param string $key Setting key name.
     * @return int Number of deleted setting records.
     */
    public function deleteSetting(string $group, string $key): int
    {
        $this->forgetGroup($group);
        return $this->db->update(
            "DELETE FROM {$this->table} WHERE group_name = :g AND key_name = :k AND merchant_id IS NULL",
            ['g' => $group, 'k' => $key]
        );
    }

    /**
     * Delete a single setting by group, key and merchant_id.
     */
    public function deleteSettingScoped(string $group, string $key, int $merchantId): int
    {
        $this->forgetGroup($group);
        return $this->db->update(
            "DELETE FROM {$this->table} WHERE group_name = :g AND key_name = :k AND merchant_id = :mid",
            ['g' => $group, 'k' => $key, 'mid' => $merchantId]
        );
    }
}

