<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_payment_intents — checkout session lifecycle.
 */
class PaymentIntentRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_payment_intents';

    /**
     * Find intent by idempotency key.
     */
    public function findByIdempotencyKey(string $key): ?array
    {
        $tc = $this->tenantCondition();
        return $this->findOneWhere(
            '`idempotency_key` = :key' . $tc,
            array_merge(['key' => $key], $this->tenantParams())
        );
    }

    /**
     * Find active intents for a merchant.
     */
    public function findByMerchant(int $merchantId, int $limit = 50): array
    {
        $tc = $this->tenantCondition();
        return $this->findWhere(
            '`merchant_id` = :mid' . $tc,
            array_merge(['mid' => $merchantId], $this->tenantParams()),
            'created_at DESC',
            $limit
        );
    }

    /**
     * Update intent status.
     */
    public function updateStatus(int $id, string $newStatus): int
    {
        $tc = $this->tenantCondition();
        return $this->update(
            ['status' => $newStatus],
            '`id` = :where_id' . $tc,
            array_merge(['where_id' => $id], $this->tenantParams())
        );
    }

    /**
     * Mark intent as expired.
     */
    public function expire(int $id): int
    {
        $tc = $this->tenantCondition();
        return $this->update(
            ['status' => 'expired', 'expired_at' => gmdate('Y-m-d H:i:s.u')],
            '`id` = :where_id' . $tc,
            array_merge(['where_id' => $id], $this->tenantParams())
        );
    }
}
