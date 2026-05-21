<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for system plugins (`op_plugins` table).
 *
 * Manages plugin activations, discovery manifest representations, capabilities,
 * and statuses. Unscoped globally as plugins are system-wide.
 *
 * @package OwnPay\Repository
 */
final class PluginRepository extends BaseRepository
{
    /**
     * @var string Database table name.
     */
    protected string $table = 'op_plugins';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'slug', 'name', 'type', 'version', 'entrypoint',
        'capabilities', 'manifest', 'status',
    ];

    /**
     * Finds a plugin record by its unique slug identifier.
     *
     * @param string $slug Unique plugin slug.
     * @return array<string, mixed>|null The plugin record, or null if not found.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    /**
     * Lists all active plugins registered in the system.
     *
     * @return list<array<string, mixed>> List of active plugin records.
     */
    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY name ASC"
        );
    }

    /**
     * Lists plugins matching a specific type classification.
     *
     * @param string $type The plugin type (e.g. 'gateway', 'addon', 'theme').
     * @return list<array<string, mixed>> List of matching plugins.
     */
    public function listByType(string $type): array
    {
        return $this->where('type', $type, 'name', 'ASC');
    }

    /**
     * Activates a plugin by its slug.
     *
     * @param string $slug The plugin slug.
     * @return int Number of affected rows.
     */
    public function activate(string $slug): int
    {
        return $this->db->update(
            "UPDATE {$this->table} SET status = 'active' WHERE slug = :s",
            ['s' => $slug]
        );
    }

    /**
     * Deactivates a plugin by its slug.
     *
     * @param string $slug The plugin slug.
     * @return int Number of affected rows.
     */
    public function deactivate(string $slug): int
    {
        return $this->db->update(
            "UPDATE {$this->table} SET status = 'inactive' WHERE slug = :s",
            ['s' => $slug]
        );
    }
}

