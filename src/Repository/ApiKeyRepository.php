<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_api_keys — hashed API key registry.
 */
class ApiKeyRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_api_keys';

    /**
     * Find an active key by its SHA-256 hash.
     */
    public function findByHash(string $hash): ?array
    {
        $tc = $this->tenantCondition();
        return $this->findOneWhere(
            '`key_hash` = :hash AND `status` = :status' . $tc,
            array_merge(['hash' => $hash, 'status' => 'active'], $this->tenantParams())
        );
    }

    /**
     * Find an active key by its prefix (for identification).
     */
    public function findByPrefix(string $prefix): ?array
    {
        $tc = $this->tenantCondition();
        return $this->findOneWhere(
            '`key_prefix` = :prefix AND `status` = :status' . $tc,
            array_merge(['prefix' => $prefix, 'status' => 'active'], $this->tenantParams())
        );
    }

    /**
     * Find all keys for a merchant.
     */
    public function findByMerchant(int $merchantId): array
    {
        $tc = $this->tenantCondition();
        return $this->findWhere(
            '`merchant_id` = :mid' . $tc,
            array_merge(['mid' => $merchantId], $this->tenantParams()),
            'created_at DESC'
        );
    }

    /**
     * Revoke a key (soft status change).
     */
    public function revoke(int $id): int
    {
        $tc = $this->tenantCondition();
        return $this->update(
            ['status' => 'revoked', 'revoked_at' => gmdate('Y-m-d H:i:s.u')],
            '`id` = :where_id' . $tc,
            array_merge(['where_id' => $id], $this->tenantParams())
        );
    }

    /**
     * Record last-used timestamp and IP.
     */
    public function touchUsage(int $id, string $ip): int
    {
        $tc = $this->tenantCondition();
        return $this->update(
            ['last_used_at' => gmdate('Y-m-d H:i:s.u'), 'last_used_ip' => $ip],
            '`id` = :where_id' . $tc,
            array_merge(['where_id' => $id], $this->tenantParams())
        );
    }

    /**
     * Set expiry for a key (used during rotation grace period).
     */
    public function setExpiry(int $id, string $expiresAt): int
    {
        $tc = $this->tenantCondition();
        return $this->update(
            ['expires_at' => $expiresAt],
            '`id` = :where_id' . $tc,
            array_merge(['where_id' => $id], $this->tenantParams())
        );
    }
}
