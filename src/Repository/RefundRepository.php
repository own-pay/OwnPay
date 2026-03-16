<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_refunds — partial/full refund records.
 */
class RefundRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_refunds';

    /**
     * Find refunds for a transaction.
     */
    public function findByTransaction(int $transactionId): array
    {
        return $this->findWhere(
            '`transaction_id` = :tid',
            ['tid' => $transactionId],
            'created_at DESC'
        );
    }

    /**
     * Find refunds by merchant.
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
     * Calculate total refunded amount for a transaction.
     */
    public function totalRefunded(int $transactionId): string
    {
        $result = $this->db->fetchColumn(
            "SELECT COALESCE(SUM(`amount`), 0)
             FROM `{$this->table}`
             WHERE `transaction_id` = :tid
               AND `status` IN ('pending', 'completed')",
            ['tid' => $transactionId]
        );

        return (string) $result;
    }

    /**
     * Update refund status.
     */
    public function updateStatus(int $id, string $status): int
    {
        $data = ['status' => $status];
        if ($status === 'completed') {
            $data['completed_at'] = gmdate('Y-m-d H:i:s.u');
        }
        return $this->updateById($id, $data);
    }
}
