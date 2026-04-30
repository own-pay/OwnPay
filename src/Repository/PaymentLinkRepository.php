<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class PaymentLinkRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_payment_links';
    protected array $fillable = [
        'merchant_id', 'uuid', 'slug', 'title', 'description', 'amount', 'currency',
        'is_amount_fixed', 'min_amount', 'max_amount', 'redirect_url',
        'max_uses', 'use_count', 'expires_at', 'status',
    ];

    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    public function incrementUseCount(int $id): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET use_count = use_count + 1 WHERE id = :id AND merchant_id = :mid",
            ['id' => $id, 'mid' => $this->requireTenant()]
        );
    }
}
