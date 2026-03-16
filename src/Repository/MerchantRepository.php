<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_merchants — tenant/business entity management.
 */
class MerchantRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_merchants';

    /**
     * Find merchant by business name (case-insensitive).
     */
    public function findByBusinessName(string $name): ?array
    {
        return $this->findOneWhere(
            '`business_name` = :name AND `deleted_at` IS NULL',
            ['name' => $name]
        );
    }

    /**
     * Find all active merchants.
     */
    public function findActive(): array
    {
        return $this->findWhere(
            '`status` = :status AND `deleted_at` IS NULL',
            ['status' => 'active'],
            'created_at DESC'
        );
    }

    /**
     * Activate a merchant.
     */
    public function activate(int $id): int
    {
        return $this->updateById($id, ['status' => 'active']);
    }

    /**
     * Suspend a merchant.
     */
    public function suspend(int $id, string $reason = ''): int
    {
        return $this->updateById($id, [
            'status' => 'suspended',
            'suspend_reason' => $reason,
        ]);
    }
}
