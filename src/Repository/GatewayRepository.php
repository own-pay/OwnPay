<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for gateways (`op_gateways` table).
 *
 * Manages registered payment gateway adapters (both built-in and plugins).
 * Unscoped globally as gateways are globally available config templates.
 */
final class GatewayRepository extends BaseRepository
{
    protected string $table = 'op_gateways';
    protected array $fillable = ['slug', 'name', 'type', 'logo_path', 'is_builtin', 'sort_order', 'status'];

    /**
     * Finds a gateway record by its unique adapter slug.
     *
     * @param string $slug Unique identifier of the gateway adapter.
     * @return array<string, mixed>|null Gateway database record, or null if not found.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    /**
     * Lists active gateway records globally.
     *
     * @return array<int, array<string, mixed>> List of active gateway records.
     */
    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY sort_order ASC, name ASC"
        );
    }
}
