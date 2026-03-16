<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_gateway_configs table.
 */
final class GatewayConfigRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_gateway_configs';

    /**
     * Find a gateway config by its gateway slug and merchant.
     */
    public function findByGatewayId(string $gatewaySlug, int $merchantId): ?array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE gateway_slug = :slug AND merchant_id = :mid AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([':slug' => $gatewaySlug, ':mid' => $merchantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List all active gateways for a merchant.
     */
    public function findActiveByMerchant(int $merchantId): array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE merchant_id = :mid AND is_active = 1
            ORDER BY priority ASC
        ");
        $stmt->execute([':mid' => $merchantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Find by public ID.
     */
    public function findByPublicId(string $publicId): ?array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM {$this->table} WHERE public_id = :pid LIMIT 1");
        $stmt->execute([':pid' => $publicId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
