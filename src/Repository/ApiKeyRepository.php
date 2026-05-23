<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for API keys (`op_api_keys` table).
 *
 * Handles API key storage, retrieval, timing-safe lookup token prefixes,
 * and key revocation.
 */
final class ApiKeyRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_api_keys';
    protected array $fillable = [
        'merchant_id', 'name', 'key_prefix', 'key_hash', 'scopes',
        'last_used_at', 'expires_at', 'status',
    ];

    /**
     * Finds an active API key record by its key prefix.
     *
     * In accordance with secure credential resolution, a timing-safe hash comparison
     * of the secret key component should be executed in the authentication middleware.
     *
     * @param string $prefix The key prefix used for rapid indexing lookups.
     * @return array<string, mixed>|null The API key database record, or null if not found.
     */
    public function findByPrefix(string $prefix): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE key_prefix = :p AND status = 'active' LIMIT 1",
            ['p' => $prefix]
        );
    }

    /**
     * Updates the last used timestamp of a specific API key.
     *
     * Uses microsecond-precision timestamps.
     *
     * @param int $id The primary key identifier of the API key.
     * @return void
     */
    public function touchLastUsed(int $id): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET last_used_at = NOW(6) WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * Revokes an API key under the active tenant context.
     *
     * @param int $id The primary key identifier of the API key.
     * @return int The number of affected rows.
     */
    public function revoke(int $id): int
    {
        return $this->updateScoped($id, ['status' => 'revoked']);
    }

    /**
     * Lists active API keys under the active tenant context.
     *
     * @return array<int, array<string, mixed>> List of active API key records.
     */
    public function listActiveKeys(): array
    {
        return $this->db->fetchAll(
            "SELECT id, name, key_prefix, scopes, last_used_at, expires_at, status, created_at
             FROM {$this->table}
             WHERE merchant_id = :mid AND status = 'active'
             ORDER BY created_at DESC",
            ['mid' => $this->requireTenant()]
        );
    }
}
