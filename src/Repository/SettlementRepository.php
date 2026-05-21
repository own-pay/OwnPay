<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository class responsible for managing database persistence and retrieval
 * of merchant settlement structures within the 'op_settlements' table.
 *
 * Provides transactional queries scoped under tenant context (via TenantScope)
 * to prevent unauthorized cross-brand access to settlement operations.
 */
final class SettlementRepository extends BaseRepository
{
    use TenantScope;

    /**
     * The database table name associated with this repository.
     *
     * @var string
     */
    protected string $table = 'op_settlements';

    /**
     * Retrieves a paginated list of settlement records for a specific merchant.
     *
     * @param int $merchantId The unique identifier of the target merchant brand.
     * @param int $limit Maximum number of settlement records to return. Defaults to 20.
     * @param int $offset Numerical offset for database query pagination. Defaults to 0.
     * @return array<int, array<string, mixed>> List of matching settlement records.
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
     * Resolves a single settlement record by its secure, public UUID string
     * within the active tenant context.
     *
     * @param string $publicId The secure public-facing UUID of the settlement.
     * @return array<string, mixed>|null The settlement details array, or null if not found.
     * @throws \RuntimeException If the active tenant identifier is not resolved.
     */
    public function findByPublicId(string $publicId): ?array
    {
        return $this->db->fetchOne("SELECT * FROM {$this->table} WHERE public_id = :pid AND merchant_id = :mid LIMIT 1", [
            'pid' => $publicId,
            'mid' => $this->requireTenant()
        ]);
    }

    /**
     * Updates the status code of a settlement record inside the active tenant scope.
     *
     * @param int $id The internal primary key of the settlement record.
     * @param string $status The target status value (e.g., pending, completed, failed).
     * @return void
     * @throws \RuntimeException If the active tenant identifier is not resolved.
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
     * Counts the total number of settlements recorded for the specified merchant.
     *
     * @param int $merchantId The unique identifier of the merchant brand.
     * @return int Total number of settlement records.
     */
    public function countByMerchant(int $merchantId): int
    {
        return $this->db->count($this->table, "merchant_id = :mid", ['mid' => $merchantId]);
    }
}
