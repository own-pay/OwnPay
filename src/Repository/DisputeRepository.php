<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_disputes table.
 */
final class DisputeRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_disputes';

    /**
     * Find disputes for a merchant with pagination.
     */
    public function findByMerchant(int $merchantId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll("
            SELECT * FROM {$this->table}
            WHERE merchant_id = :mid
            ORDER BY created_at DESC
            LIMIT :lim OFFSET :off
        ", [
            'mid' => $merchantId,
            'lim' => $limit,
            'off' => $offset
        ]);
    }

    /**
     * Find a dispute by public UUID.
     */
    public function findByPublicId(string $publicId): ?array
    {
        return $this->db->fetchOne("SELECT * FROM {$this->table} WHERE public_id = :pid AND merchant_id = :mid LIMIT 1", [
            'pid' => $publicId,
            'mid' => $this->requireTenant()
        ]);
    }

    /**
     * Find a dispute by transaction ID.
     */
    public function findByTransactionId(int $transactionId): ?array
    {
        return $this->db->fetchOne("
            SELECT * FROM {$this->table}
            WHERE transaction_id = :tid AND status IN ('open', 'under_review') AND merchant_id = :mid
            LIMIT 1
        ", [
            'tid' => $transactionId,
            'mid' => $this->requireTenant()
        ]);
    }

    /**
     * Update dispute status and resolution.
     */
    public function resolve(int $id, string $status, string $resolution, ?string $evidence = null): void
    {
        $this->db->execute("
            UPDATE {$this->table}
            SET status = :st, resolution = :res, evidence = :ev,
                resolved_at = NOW(6), updated_at = NOW(6)
            WHERE id = :id AND merchant_id = :mid
        ", [
            'st' => $status,
            'res' => $resolution,
            'ev' => $evidence,
            'id' => $id,
            'mid' => $this->requireTenant()
        ]);
    }

    /**
     * Count open disputes by merchant.
     */
    public function countOpenByMerchant(int $merchantId): int
    {
        return $this->db->count($this->table, "merchant_id = :mid AND status IN ('open', 'under_review')", ['mid' => $merchantId]);
    }
}
