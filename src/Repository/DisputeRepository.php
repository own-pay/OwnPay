<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for customer disputes (`op_disputes` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages chargebacks, status transitions, evidence submission, and counts.
 */
final class DisputeRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_disputes';

    /**
     * Lists dispute records for a specific merchant with sorting and pagination.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param int $limit Maximum records to return.
     * @param int $offset Records offset.
     * @return array<int, array<string, mixed>> List of matching dispute records.
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
     * Finds a single dispute record by its primary key identifier, scoped by active tenant.
     *
     * @param int $id Primary key identifier of the dispute.
     * @return array<string, mixed>|null Dispute database record, or null if not found.
     */
    public function findByIdScoped(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM {$this->table} WHERE id = :id AND merchant_id = :mid LIMIT 1", [
            'id' => $id, 'mid' => $this->requireTenant()
        ]);
    }

    /**
     * Finds a dispute associated with a specific transaction identifier.
     *
     * Limits results to active status states ('open', 'under_review').
     *
     * @param int $transactionId Primary key identifier of the transaction.
     * @return array<string, mixed>|null Dispute database record, or null if not found.
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
     * Resolves an open dispute, setting the final status and evidence.
     *
     * Uses microsecond-precision timestamps.
     *
     * @param int $id Primary key identifier of the dispute.
     * @param string $status Target dispute resolution status.
     * @param string|null $evidence Optional JSON-encoded resolution evidence details.
     * @return void
     */
    public function resolve(int $id, string $status, ?string $evidence = null): void
    {
        $this->db->execute("
            UPDATE {$this->table}
            SET status = :st, evidence = :ev,
                resolved_at = NOW(6), updated_at = NOW(6)
            WHERE id = :id AND merchant_id = :mid
        ", ['st' => $status, 'ev' => $evidence, 'id' => $id, 'mid' => $this->requireTenant()]);
    }

    /**
     * Counts open disputes ('open', 'under_review') under a specific merchant.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @return int Active disputes count.
     */
    public function countOpenByMerchant(int $merchantId): int
    {
        return $this->db->count($this->table, "merchant_id = :mid AND status IN ('open', 'under_review')", ['mid' => $merchantId]);
    }
}
