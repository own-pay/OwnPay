<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_merchants — tenant/business entity management.
 */
class MerchantRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_merchants';

    /**
     * Find merchant by business name (case-insensitive).
     */
    public function findByBusinessName(string $name): ?array
    {
        $tc = $this->tenantCondition();
        return $this->findOneWhere(
            '`business_name` = :name AND `deleted_at` IS NULL' . $tc,
            array_merge(['name' => $name], $this->tenantParams())
        );
    }

    /**
     * Find all active merchants.
     */
    public function findActive(): array
    {
        $tc = $this->tenantCondition();
        return $this->findWhere(
            '`status` = :status AND `deleted_at` IS NULL' . $tc,
            array_merge(['status' => 'active'], $this->tenantParams()),
            'created_at DESC'
        );
    }

    /**
     * Activate a merchant.
     */
    public function activate(int $id): int
    {
        $tc = $this->tenantCondition();
        return $this->update(
            ['status' => 'active'],
            '`id` = :where_id' . $tc,
            array_merge(['where_id' => $id], $this->tenantParams())
        );
    }

    /**
     * Suspend a merchant.
     */
    public function suspend(int $id, string $reason = ''): int
    {
        $tc = $this->tenantCondition();
        return $this->update(
            ['status' => 'suspended', 'suspend_reason' => $reason],
            '`id` = :where_id' . $tc,
            array_merge(['where_id' => $id], $this->tenantParams())
        );
    }
}
