<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class PluginRepository extends BaseRepository
{
    protected string $table = 'op_plugins';
    protected array $fillable = [
        'slug', 'name', 'type', 'version', 'entrypoint',
        'capabilities', 'manifest', 'status',
    ];

    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY name ASC"
        );
    }

    public function listByType(string $type): array
    {
        return $this->where('type', $type, 'name', 'ASC');
    }

    public function activate(string $slug): int
    {
        return $this->db->update(
            "UPDATE {$this->table} SET status = 'active' WHERE slug = :s",
            ['s' => $slug]
        );
    }

    public function deactivate(string $slug): int
    {
        return $this->db->update(
            "UPDATE {$this->table} SET status = 'inactive' WHERE slug = :s",
            ['s' => $slug]
        );
    }
}
