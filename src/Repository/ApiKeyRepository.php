<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_api_keys — hashed API key registry.
 */
class ApiKeyRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_api_keys';

    /**
     * Find an active key by its SHA-256 hash.
     */
    public function findByHash(string $hash): ?array
    {
        return $this->findOneWhere(
            '`key_hash` = :hash AND `status` = :status',
            ['hash' => $hash, 'status' => 'active']
        );
    }

    /**
     * Find an active key by its prefix (for identification).
     */
    public function findByPrefix(string $prefix): ?array
    {
        return $this->findOneWhere(
            '`key_prefix` = :prefix AND `status` = :status',
            ['prefix' => $prefix, 'status' => 'active']
        );
    }

    /**
     * Find all keys for a merchant.
     */
    public function findByMerchant(int $merchantId): array
    {
        return $this->findWhere(
            '`merchant_id` = :mid',
            ['mid' => $merchantId],
            'created_at DESC'
        );
    }

    /**
     * Revoke a key (soft status change).
     */
    public function revoke(int $id): int
    {
        return $this->updateById($id, [
            'status' => 'revoked',
            'revoked_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * Record last-used timestamp and IP.
     */
    public function touchUsage(int $id, string $ip): int
    {
        return $this->updateById($id, [
            'last_used_at' => gmdate('Y-m-d H:i:s.u'),
            'last_used_ip' => $ip,
        ]);
    }

    /**
     * Set expiry for a key (used during rotation grace period).
     */
    public function setExpiry(int $id, string $expiresAt): int
    {
        return $this->updateById($id, [
            'expires_at' => $expiresAt,
        ]);
    }
}
