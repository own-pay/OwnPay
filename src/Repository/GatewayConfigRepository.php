<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_gateway_configs table.
 */
final class GatewayConfigRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_gateway_configs';

    /**
     * Find a gateway config by its gateway slug and merchant.
     */
    public function findByGatewayId(string $gatewaySlug, int $merchantId): ?array
    {
        $tc = $this->tenantCondition();
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE gateway_slug = :slug AND merchant_id = :mid AND is_active = 1{$tc}
            LIMIT 1
        ");
        $stmt->execute(array_merge([':slug' => $gatewaySlug, ':mid' => $merchantId], $this->tenantParams()));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List all active gateways for a merchant.
     */
    public function findActiveByMerchant(int $merchantId): array
    {
        $tc = $this->tenantCondition();
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE merchant_id = :mid AND is_active = 1{$tc}
            ORDER BY priority ASC
        ");
        $stmt->execute(array_merge([':mid' => $merchantId], $this->tenantParams()));
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Find by public ID.
     */
    public function findByPublicId(string $publicId): ?array
    {
        $tc = $this->tenantCondition();
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM {$this->table} WHERE public_id = :pid{$tc} LIMIT 1");
        $stmt->execute(array_merge([':pid' => $publicId], $this->tenantParams()));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
