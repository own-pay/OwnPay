<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for idempotency keys (`op_idempotency_keys` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Implements API replay prevention by recording requests, hashing signatures,
 * caching response envelopes, and purging expired keys.
 */
final class IdempotencyRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_idempotency_keys';
    protected array $fillable = [
        'merchant_id', 'idempotency_key', 'request_hash',
        'response_code', 'response_body', 'expires_at',
    ];

    /**
     * Determines whether the table has a public ID column.
     *
     * @return bool False since this table does not contain a public_id column.
     */
    protected function hasPublicId(): bool
    {
        return false;
    }

    /**
     * Finds an idempotency record by its key under the active tenant context.
     *
     * Ensures keys are only matches if they have not yet expired.
     *
     * @param string $key The idempotency key string.
     * @return array<string, mixed>|null Idempotency key record, or null if not found or expired.
     */
    public function findByKey(string $key): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM `{$this->table}` WHERE `idempotency_key` = :key AND `merchant_id` = :mid AND `expires_at` > NOW(6) LIMIT 1",
            ['key' => $key, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Stores the cached response payload for a completed API request execution.
     *
     * @param int|string $id Primary key identifier of the idempotency record.
     * @param string $responseBody The cached raw JSON response payload.
     * @param int $responseCode The HTTP status code returned by the operation.
     * @return int Number of affected rows.
     */
    public function complete(int|string $id, string $responseBody, int $responseCode): int
    {
        return $this->updateScoped($id, [
            'response_body' => $responseBody,
            'response_code' => $responseCode,
        ]);
    }

    /**
     * Deletes an active idempotency key lock to permit execution retries on transient failures.
     *
     * @param string $key The target idempotency key string.
     * @return int Number of affected rows.
     */
    public function deleteKey(string $key): int
    {
        return $this->db->delete(
            "DELETE FROM `{$this->table}` WHERE `idempotency_key` = :key AND `merchant_id` = :mid",
            ['key' => $key, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Purges expired idempotency records older than the specified duration.
     *
     * Global housekeeper task; intentionally unscoped.
     *
     * @param int $hours The cutoff age threshold in hours (default is 24).
     * @return int Number of deleted records.
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
