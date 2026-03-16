<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_idempotency_keys — API replay prevention.
 */
class IdempotencyRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_idempotency_keys';

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
        return $this->findOneWhere(
            '`scope` = :scope AND `idempotency_key` = :key',
            ['scope' => $scope, 'key' => $key]
        );
    }

    /**
     * Store the response payload for a completed request.
     */
    public function complete(int $id, string $responsePayload, int $httpStatus): int
    {
        return $this->updateById($id, [
            'response_payload' => $responsePayload,
            'http_status' => $httpStatus,
            'status' => 'completed',
        ]);
    }

    /**
     * Clean up expired keys (older than $hours).
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
