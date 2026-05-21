<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_idempotency_keys — API replay prevention.
 */
final class IdempotencyRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_idempotency_keys';
    protected array $fillable = [
        'merchant_id', 'idempotency_key', 'request_hash',
        'response_code', 'response_body', 'expires_at',
    ];

    protected function hasPublicId(): bool
    {
        return false; // This table has no public_id column
    }

    /**
     * Acquire an idempotency lock.
     * Returns existing row if key already exists (replay), null if new.
     * BUG-16 FIX: Removed non-existent 'scope' column. Added expires_at check.
     */
    public function findByKey(string $key): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM `{$this->table}` WHERE `idempotency_key` = :key AND `merchant_id` = :mid AND `expires_at` > NOW(6) LIMIT 1",
            ['key' => $key, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Store the response payload for a completed request.
     * BUG-16 FIX: Use correct column names: response_body, response_code.
     * Schema has no 'status' column.
     */
    public function complete(int|string $id, string $responseBody, int $responseCode): int
    {
        return $this->updateScoped($id, [
            'response_body' => $responseBody,
            'response_code' => $responseCode,
        ]);
    }

    /**
     * Clean up expired keys (older than $hours).
     * NOTE: This is a global housekeeping operation — no tenant scoping applied.
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
