<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_refunds — partial/full refund records.
 */
class RefundRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_refunds';

    /**
     * Find refunds for a transaction.
     */
    public function findByTransaction(int $transactionId): array
    {
        $tc = $this->tenantCondition();
        return $this->findWhere(
            '`transaction_id` = :tid' . $tc,
            array_merge(['tid' => $transactionId], $this->tenantParams()),
            'created_at DESC'
        );
    }

    /**
     * Find refunds by merchant.
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
     * Calculate total refunded amount for a transaction.
     */
    public function totalRefunded(int $transactionId): string
    {
        $tc = $this->tenantCondition();
        $result = $this->db->fetchColumn(
            "SELECT COALESCE(SUM(`amount`), 0)
             FROM `{$this->table}`
             WHERE `transaction_id` = :tid
               AND `status` IN ('pending', 'completed'){$tc}",
            array_merge(['tid' => $transactionId], $this->tenantParams())
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
        $tc = $this->tenantCondition();
        return $this->update(
            $data,
            '`id` = :where_id' . $tc,
            array_merge(['where_id' => $id], $this->tenantParams())
        );
    }
}
