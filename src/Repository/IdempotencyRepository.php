<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_idempotency_keys â€” API replay prevention.
 */
final class IdempotencyRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_idempotency_keys';

    protected function hasPublicId(): bool
    {
        return false; // This table has no public_id column
    }

    /**
     * Acquire an idempotency lock.
     * Returns existing row if key already exists (replay), null if new.
     */
    public function findByKey(string $scope, string $key): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM `{$this->table}` WHERE `scope` = :scope AND `idempotency_key` = :key AND `merchant_id` = :mid LIMIT 1",
            ['scope' => $scope, 'key' => $key, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Store the response payload for a completed request.
     */
    public function complete(int|string $id, string $responsePayload, int $httpStatus): int
    {
        return $this->updateScoped($id, [
            'response_payload' => $responsePayload,
            'http_status' => $httpStatus,
            'status' => 'completed',
        ]);
    }

    /**
     * Clean up expired keys (older than $hours).
     * NOTE: This is a global housekeeping operation â€” no tenant scoping applied.
     */
    public function cleanup(int $hours = 24): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));
        $stmt = $this->db->execute(
            "DELETE FROM `{$this->table}` WHERE `created_at` < :cutoff",
            ['cutoff' => $cutoff]
        );
        return $stmt->rowCount();
    }
}
