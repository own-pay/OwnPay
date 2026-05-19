<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class SettingsRepository extends BaseRepository
{
    protected string $table = 'op_system_settings';
    protected array $fillable = ['group_name', 'key_name', 'value', 'type', 'merchant_id'];

    public function get(string $group, string $key, ?string $default = null): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT value FROM {$this->table} WHERE group_name = :g AND key_name = :k AND merchant_id IS NULL LIMIT 1",
            ['g' => $group, 'k' => $key]
        );
        return $row['value'] ?? $default;
    }

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
    }

    /**
     * Get all settings in group as key=>value map (global only).
     * @return array<string, string>
     */
    public function getGroup(string $group): array
    {
        $rows = $this->db->fetchAll(
            "SELECT key_name, value FROM {$this->table} WHERE group_name = :g AND merchant_id IS NULL",
            ['g' => $group]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key_name']] = $row['value'];
        }
        return $result;
    }

    /**
     * Bulk update settings (used by admin settings page).
     */
    public function bulkSet(string $group, array $keyValues): void
    {
        $this->db->transaction(function () use ($group, $keyValues) {
            foreach ($keyValues as $key => $value) {
                $this->set($group, $key, (string) $value);
            }
        });
    }

    /**
     * Delete all settings in a group (used by plugin uninstall).
     */
    public function deleteGroup(string $group): int
    {
        return $this->db->update(
            "DELETE FROM {$this->table} WHERE group_name = :g",
            ['g' => $group]
        );
    }

    // ── AUD-G5: Brand-Scoped Settings ─────────────────────────

    /**
     * Get setting with brand cascade: brand-specific → global fallback.
     *
     * @param string $group     Setting group (e.g. 'plugin.my-gateway')
     * @param string $key       Setting key
     * @param int    $merchantId Brand ID
     * @param string|null $default  Fallback if neither brand nor global exists
     */
    public function getScoped(string $group, string $key, int $merchantId, ?string $default = null): ?string
    {
        // Try brand-specific first
        $row = $this->db->fetchOne(
            "SELECT value FROM {$this->table} WHERE group_name = :g AND key_name = :k AND merchant_id = :mid LIMIT 1",
            ['g' => $group, 'k' => $key, 'mid' => $merchantId]
        );
        if ($row !== null) {
            return $row['value'];
        }
        // Fall back to global
        return $this->get($group, $key, $default);
    }

    /**
     * Set a brand-specific setting override.
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
    }

    /**
     * Bulk set brand-scoped settings.
     */
    public function bulkSetScoped(string $group, array $keyValues, int $merchantId): void
    {
        $this->db->transaction(function () use ($group, $keyValues, $merchantId) {
            foreach ($keyValues as $key => $value) {
                $this->setScoped($group, $key, (string) $value, $merchantId);
            }
        });
    }

    /**
     * Get all settings in group for a brand (merged: brand overrides global).
     * @return array<string, string>
     */
    public function getGroupScoped(string $group, int $merchantId): array
    {
        // Start with global values
        $result = $this->getGroup($group);
        // Override with brand-specific values
        $rows = $this->db->fetchAll(
            "SELECT key_name, value FROM {$this->table} WHERE group_name = :g AND merchant_id = :mid",
            ['g' => $group, 'mid' => $merchantId]
        );
        foreach ($rows as $row) {
            $result[$row['key_name']] = $row['value'];
        }
        return $result;
    }

    /**
     * Delete brand-scoped settings for a group (used when brand unlinks plugin).
     */
    public function deleteGroupScoped(string $group, int $merchantId): int
    {
        return $this->db->update(
            "DELETE FROM {$this->table} WHERE group_name = :g AND merchant_id = :mid",
            ['g' => $group, 'mid' => $merchantId]
        );
    }
}
