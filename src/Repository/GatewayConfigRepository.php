<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class GatewayConfigRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_gateway_configs';
    protected array $fillable = [
        'merchant_id', 'gateway_id', 'credentials_enc', 'settings', 'mode', 'status',
    ];

    public function findForGateway(int $gatewayId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE gateway_id = :gid AND merchant_id = :mid LIMIT 1",
            ['gid' => $gatewayId, 'mid' => $this->requireTenant()]
        );
    }

    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT gc.*, g.slug, g.name, g.type, g.logo_path
             FROM {$this->table} gc
             JOIN op_gateways g ON g.id = gc.gateway_id
             WHERE gc.merchant_id = :mid AND gc.status = 'active'
             ORDER BY g.sort_order ASC",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Find encrypted credentials for a gateway by slug.
     */
    public function findCredentialsBySlug(string $slug): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT gc.credentials_enc FROM {$this->table} gc
             JOIN op_gateways g ON g.id = gc.gateway_id
             WHERE g.slug = :slug AND gc.merchant_id = :mid AND gc.status = 'active' LIMIT 1",
            ['slug' => $slug, 'mid' => $this->requireTenant()]
        );
        return $row['credentials_enc'] ?? null;
    }
}
