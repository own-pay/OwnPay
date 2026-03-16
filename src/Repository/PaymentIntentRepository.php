<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_payment_intents — checkout session lifecycle.
 */
class PaymentIntentRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_payment_intents';

    /**
     * Find intent by idempotency key.
     */
    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->findOneWhere(
            '`idempotency_key` = :key',
            ['key' => $key]
        );
    }

    /**
     * Find active intents for a merchant.
     */
    public function findByMerchant(int $merchantId, int $limit = 50): array
    {
        return $this->findWhere(
            '`merchant_id` = :mid',
            ['mid' => $merchantId],
            'created_at DESC',
            $limit
        );
    }

    /**
     * Update intent status.
     */
    public function updateStatus(int $id, string $newStatus): int
    {
        return $this->updateById($id, ['status' => $newStatus]);
    }

    /**
     * Mark intent as expired.
     */
    public function expire(int $id): int
    {
        return $this->updateById($id, [
            'status' => 'expired',
            'expired_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }
}
