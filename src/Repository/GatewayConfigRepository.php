<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for gateway configurations (`op_gateway_configs` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages encrypted gateway credentials, settings JSON payload, mode,
 * and lists active integrations.
 */
final class GatewayConfigRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_gateway_configs';
    protected array $fillable = [
        'merchant_id', 'gateway_id', 'credentials_enc', 'settings', 'mode', 'status',
    ];

    /**
     * Finds a gateway configuration by its gateway identifier under the active tenant context.
     *
     * @param int $gatewayId Primary key identifier of the gateway.
     * @return array<string, mixed>|null Gateway config database record, or null if not found.
     */
    public function findForGateway(int $gatewayId): ?array
    {
        if ($this->tenantId !== null) {
            $row = $this->db->fetchOne(
                "SELECT * FROM {$this->table} WHERE gateway_id = :gid AND merchant_id = :mid LIMIT 1",
                ['gid' => $gatewayId, 'mid' => $this->tenantId]
            );
            if ($row !== null) {
                return $row;
            }
        }
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE gateway_id = :gid AND merchant_id IS NULL LIMIT 1",
            ['gid' => $gatewayId]
        );
    }

    /**
     * Lists active gateway configurations under the active tenant context.
     *
     * Joins global gateway adapters to populate metadata.
     *
     * @return array<int, array<string, mixed>> List of matching gateway configs with descriptors.
     */
    public function listActive(): array
    {
        $mid = $this->tenantId;
        if ($mid === null) {
            return $this->db->fetchAll(
                "SELECT gc.*, g.slug, g.name, g.type, g.logo_path
                 FROM {$this->table} gc
                 JOIN op_gateways g ON g.id = gc.gateway_id
                 WHERE gc.status = 'active'
                 ORDER BY g.sort_order ASC",
                []
            );
        }

        $rows = $this->db->fetchAll(
            "SELECT gc.*, g.slug, g.name, g.type, g.logo_path
             FROM {$this->table} gc
             JOIN op_gateways g ON g.id = gc.gateway_id
             WHERE (gc.merchant_id = :mid OR gc.merchant_id IS NULL) AND gc.status = 'active'
             ORDER BY g.sort_order ASC, gc.merchant_id DESC",
            ['mid' => $mid]
        );

        $unique = [];
        foreach ($rows as $row) {
            $slug = isset($row['slug']) && is_scalar($row['slug']) ? (string)$row['slug'] : '';
            if ($slug !== '' && !isset($unique[$slug])) {
                $unique[$slug] = $row;
            }
        }
        return array_values($unique);
    }

    /**
     * Finds encrypted credentials for a gateway by its unique adapter slug.
     *
     * @param string $slug Unique identifier of the gateway adapter.
     * @return string|null The encrypted credentials string, or null if not found/inactive.
     */
    public function findCredentialsBySlug(string $slug): ?string
    {
        if ($this->tenantId !== null) {
            $row = $this->db->fetchOne(
                "SELECT gc.credentials_enc FROM {$this->table} gc
                 JOIN op_gateways g ON g.id = gc.gateway_id
                 WHERE g.slug = :slug AND gc.merchant_id = :mid AND gc.status = 'active' LIMIT 1",
                ['slug' => $slug, 'mid' => $this->tenantId]
            );
            $enc = is_array($row) ? ($row['credentials_enc'] ?? null) : null;
            if (is_scalar($enc) && $enc !== '') {
                return (string) $enc;
            }
        }

        $row = $this->db->fetchOne(
            "SELECT gc.credentials_enc FROM {$this->table} gc
             JOIN op_gateways g ON g.id = gc.gateway_id
             WHERE g.slug = :slug AND gc.merchant_id IS NULL AND gc.status = 'active' LIMIT 1",
            ['slug' => $slug]
        );
        $enc = is_array($row) ? ($row['credentials_enc'] ?? null) : null;
        return is_scalar($enc) ? (string) $enc : null;
    }

    /**
     * Lists active gateway configurations with gateway descriptors.
     *
     * @return array<int, array<string, mixed>> List of matching gateway configs.
     */
    public function listActiveWithGateway(): array
    {
        return $this->listActive();
    }

    /**
     * Lists active gateways for the public checkout flow, stripping sensitive fields.
     *
     * Prevents leakage of encrypted credentials and internal settings to client-facing Twig views.
     *
     * @return array<int, array<string, mixed>> List of public-safe gateway configs.
     */
    public function listActiveForCheckout(): array
    {
        $mid = $this->requireTenant();
        $rows = $this->db->fetchAll(
            "SELECT gc.id, gc.merchant_id, gc.mode, gc.status, g.slug, g.name, g.type, g.logo_path
             FROM {$this->table} gc
             JOIN op_gateways g ON g.id = gc.gateway_id
             WHERE (gc.merchant_id = :mid OR gc.merchant_id IS NULL) AND gc.status = 'active'
             ORDER BY g.sort_order ASC, gc.merchant_id DESC",
            ['mid' => $mid]
        );

        $unique = [];
        foreach ($rows as $row) {
            $slug = isset($row['slug']) && is_scalar($row['slug']) ? (string)$row['slug'] : '';
            if ($slug !== '' && !isset($unique[$slug])) {
                $unique[$slug] = $row;
            }
        }
        return array_values($unique);
    }
}
