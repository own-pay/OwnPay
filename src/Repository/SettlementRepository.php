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
        $tc = $this->tenantCondition();
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE merchant_id = :mid{$tc}
            ORDER BY created_at DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':mid', $merchantId, \PDO::PARAM_INT);
        foreach ($this->tenantParams() as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Find a settlement by its public UUID.
     */
    public function findByPublicId(string $publicId): ?array
    {
        $tc = $this->tenantCondition();
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM {$this->table} WHERE public_id = :pid{$tc} LIMIT 1");
        $stmt->execute(array_merge([':pid' => $publicId], $this->tenantParams()));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Update settlement status.
     */
    public function updateStatus(int $id, string $status): void
    {
        $tc = $this->tenantCondition();
        $pdo = $this->db->getPdo();
        $pdo->prepare("UPDATE {$this->table} SET status = :st, updated_at = NOW(6) WHERE id = :id{$tc}")
            ->execute(array_merge([':st' => $status, ':id' => $id], $this->tenantParams()));
    }

    /**
     * Count settlements by merchant.
     */
    public function countByMerchant(int $merchantId): int
    {
        $tc = $this->tenantCondition();
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE merchant_id = :mid{$tc}");
        $stmt->execute(array_merge([':mid' => $merchantId], $this->tenantParams()));
        return (int) $stmt->fetchColumn();
    }
}
