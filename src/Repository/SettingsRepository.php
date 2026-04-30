<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class SettingsRepository extends BaseRepository
{
    protected string $table = 'op_system_settings';
    protected array $fillable = ['group_name', 'key_name', 'value', 'type'];

    public function get(string $group, string $key, ?string $default = null): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT value FROM {$this->table} WHERE group_name = :g AND key_name = :k LIMIT 1",
            ['g' => $group, 'k' => $key]
        );
        return $row['value'] ?? $default;
    }

    public function set(string $group, string $key, string $value, string $type = 'string'): void
    {
        $exists = $this->db->exists($this->table, "group_name = :g AND key_name = :k", ['g' => $group, 'k' => $key]);
        if ($exists) {
            $this->db->update(
                "UPDATE {$this->table} SET value = :v, type = :t WHERE group_name = :g AND key_name = :k",
                ['v' => $value, 't' => $type, 'g' => $group, 'k' => $key]
            );
        } else {
            $this->create(['group_name' => $group, 'key_name' => $key, 'value' => $value, 'type' => $type]);
        }
    }

    /**
     * Get all settings in group as key=>value map.
     * @return array<string, string>
     */
    public function getGroup(string $group): array
    {
        $rows = $this->db->fetchAll(
            "SELECT key_name, value FROM {$this->table} WHERE group_name = :g",
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
}
