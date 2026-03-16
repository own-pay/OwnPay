<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_settlements table.
 */
final class SettlementRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_settlements';

    /**
     * Find all settlements for a merchant with pagination.
     */
    public function findByMerchant(int $merchantId, int $limit = 20, int $offset = 0): array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE merchant_id = :mid
            ORDER BY created_at DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':mid', $merchantId, \PDO::PARAM_INT);
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
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM {$this->table} WHERE public_id = :pid LIMIT 1");
        $stmt->execute([':pid' => $publicId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Update settlement status.
     */
    public function updateStatus(int $id, string $status): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare("UPDATE {$this->table} SET status = :st, updated_at = NOW(6) WHERE id = :id")
            ->execute([':st' => $status, ':id' => $id]);
    }

    /**
     * Count settlements by merchant.
     */
    public function countByMerchant(int $merchantId): int
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE merchant_id = :mid");
        $stmt->execute([':mid' => $merchantId]);
        return (int) $stmt->fetchColumn();
    }
}
