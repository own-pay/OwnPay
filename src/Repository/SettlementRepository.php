<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_settlements table.
 */
final class SettlementRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_settlements';

    /**
     * Find all settlements for a merchant with pagination.
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
     * Find a settlement by its public UUID.
     */
    public function findByPublicId(string $publicId): ?array
    {
        return $this->db->fetchOne("SELECT * FROM {$this->table} WHERE public_id = :pid AND merchant_id = :mid LIMIT 1", [
            'pid' => $publicId,
            'mid' => $this->requireTenant()
        ]);
    }

    /**
     * Update settlement status.
     */
    public function updateStatus(int $id, string $status): void
    {
        $this->db->execute("UPDATE {$this->table} SET status = :st, updated_at = NOW(6) WHERE id = :id AND merchant_id = :mid", [
            'st' => $status,
            'id' => $id,
            'mid' => $this->requireTenant()
        ]);
    }

    /**
     * Count settlements by merchant.
     */
    public function countByMerchant(int $merchantId): int
    {
        return $this->db->count($this->table, "merchant_id = :mid", ['mid' => $merchantId]);
    }
}
