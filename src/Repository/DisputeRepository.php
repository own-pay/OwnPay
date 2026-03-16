<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_disputes table.
 */
final class DisputeRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_disputes';

    /**
     * Find disputes for a merchant with pagination.
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
     * Find a dispute by public UUID.
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
     * Find a dispute by transaction ID.
     */
    public function findByTransactionId(int $transactionId): ?array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE transaction_id = :tid AND status IN ('open', 'under_review')
            LIMIT 1
        ");
        $stmt->execute([':tid' => $transactionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Update dispute status and resolution.
     */
    public function resolve(int $id, string $status, string $resolution, ?string $evidence = null): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare("
            UPDATE {$this->table}
            SET status = :st, resolution = :res, evidence = :ev,
                resolved_at = NOW(6), updated_at = NOW(6)
            WHERE id = :id
        ")->execute([
                    ':st' => $status,
                    ':res' => $resolution,
                    ':ev' => $evidence,
                    ':id' => $id,
                ]);
    }

    /**
     * Count open disputes by merchant.
     */
    public function countOpenByMerchant(int $merchantId): int
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM {$this->table}
            WHERE merchant_id = :mid AND status IN ('open', 'under_review')
        ");
        $stmt->execute([':mid' => $merchantId]);
        return (int) $stmt->fetchColumn();
    }
}
