<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class GatewayRepository extends BaseRepository
{
    protected string $table = 'op_gateways';
    protected array $fillable = ['slug', 'name', 'type', 'logo_path', 'is_builtin', 'sort_order', 'status'];

    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY sort_order ASC, name ASC"
        );
    }
}
