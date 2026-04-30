<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Tenant scope — auto-scopes queries to current merchant_id.
 *
 * Prevents cross-tenant data leakage (per pci-compliance + security skills).
 * Clone-based: forTenant() returns new instance, original stays unscoped.
 */
trait TenantScope
{
    protected ?int $tenantId = null;

    public function forTenant(int $merchantId): static
    {
        $clone = clone $this;
        $clone->tenantId = $merchantId;
        return $clone;
    }

    protected function requireTenant(): int
    {
        if ($this->tenantId === null) {
            throw new \LogicException(
                "Tenant scope not set on " . static::class . ". Call forTenant() first."
            );
        }
        return $this->tenantId;
    }

    public function findScoped(int|string $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id AND merchant_id = :mid LIMIT 1",
            ['id' => $id, 'mid' => $this->requireTenant()]
        );
    }

    public function paginateScoped(int $page = 1, int $perPage = 20, string $extraWhere = '1=1', array $params = [], string $orderBy = 'id DESC'): array
    {
        $params['_tenant'] = $this->requireTenant();
        $where = "merchant_id = :_tenant AND ({$extraWhere})";
        return $this->paginate($page, $perPage, $where, $params, $orderBy);
    }

    public function createScoped(array $data): string
    {
        $data['merchant_id'] = $this->requireTenant();
        return $this->create($data);
    }

    public function updateScoped(int|string $id, array $data): int
    {
        $filtered = $this->filterFillable($data);
        if (empty($filtered)) {
            return 0;
        }
        $sets = implode(', ', array_map(fn(string $k) => "{$k} = :{$k}", array_keys($filtered)));
        $filtered['_pk'] = $id;
        $filtered['_mid'] = $this->requireTenant();

        return $this->db->update(
            "UPDATE {$this->table} SET {$sets} WHERE {$this->primaryKey} = :_pk AND merchant_id = :_mid",
            $filtered
        );
    }

    public function deleteScoped(int|string $id): int
    {
        return $this->db->delete(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id AND merchant_id = :mid",
            ['id' => $id, 'mid' => $this->requireTenant()]
        );
    }

    public function countScoped(string $extraWhere = '1=1', array $params = []): int
    {
        $params['_mid'] = $this->requireTenant();
        return $this->db->count(
            $this->table,
            "merchant_id = :_mid AND ({$extraWhere})",
            $params
        );
    }
}
