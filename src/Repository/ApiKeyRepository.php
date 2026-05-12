<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class ApiKeyRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_api_keys';
    protected array $fillable = [
        'merchant_id', 'name', 'key_prefix', 'key_hash', 'scopes',
        'last_used_at', 'expires_at', 'status',
    ];

    /**
     * Find by key prefix + hash (for auth lookup).
     * Per security skill: timing-safe compare done in auth middleware.
     */
    public function findByPrefix(string $prefix): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE key_prefix = :p AND status = 'active' LIMIT 1",
            ['p' => $prefix]
        );
    }

    public function touchLastUsed(int $id): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET last_used_at = NOW(6) WHERE id = :id",
            ['id' => $id]
        );
    }

    public function revoke(int $id): int
    {
        return $this->updateScoped($id, ['status' => 'revoked']);
    }

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
