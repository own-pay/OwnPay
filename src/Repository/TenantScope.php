<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Trait providing tenant isolation scoping mechanism for database operations.
 *
 * Automatically limits SQL queries to the active merchant context (merchant_id)
 * to prevent cross-tenant data leakage. Supports clone-based scoping where
 * calling forTenant() returns a new scoped instance while leaving the original
 * repository unscoped.
 */
trait TenantScope
{
    /**
     * The scoped merchant identifier.
     *
     * @var int|null
     */
    protected ?int $tenantId = null;

    /**
     * Returns a cloned repository instance scoped to the specified merchant.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @return static Cloned repository instance with tenant scope configured.
     */
    public function forTenant(int $merchantId): static
    {
        $clone = clone $this;
        $clone->tenantId = $merchantId;
        return $clone;
    }

    /**
     * Returns an unscoped cloned repository instance for global superadmin operations.
     *
     * @return static Cloned repository instance with tenant scope removed.
     */
    public function forAllTenants(): static
    {
        $clone = clone $this;
        $clone->tenantId = null;
        return $clone;
    }

    /**
     * Enforces active tenant scope, throwing an exception if not set.
     *
     * @return int Active tenant/merchant identifier.
     * @throws \LogicException If tenant scope is not configured on the instance.
     */
    protected function requireTenant(): int
    {
        if ($this->tenantId === null) {
            throw new \LogicException(
                "Tenant scope not set on " . static::class . ". Call forTenant() first."
            );
        }
        return $this->tenantId;
    }

    /**
     * Retrieves a single record by primary key restricted within the active tenant context.
     *
     * @param int|string $id Primary key identifier.
     * @return array<string, mixed>|null Database row array, or null if not found.
     */
    public function findScoped(int|string $id): ?array
    {
        // tenantId === null => global read (superadmin "All Brands"): find regardless of brand.
        if ($this->tenantId === null) {
            return $this->db->fetchOne(
                "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1",
                ['id' => $id]
            );
        }
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id AND merchant_id = :mid LIMIT 1",
            ['id' => $id, 'mid' => $this->tenantId]
        );
    }

    /**
     * Pagines tenant-scoped records matching additional criteria.
     *
     * @param int $page Page number (1-indexed).
     * @param int $perPage Maximum items per page.
     * @param string $extraWhere Additional SQL WHERE conditions.
     * @param array<string, mixed> $params Parameter binds for the extra WHERE query.
     * @param string $orderBy SQL ORDER BY clause.
     * @return array<string, mixed> Pagination envelope.
     */
    public function paginateScoped(int $page = 1, int $perPage = 20, string $extraWhere = '1=1', array $params = [], string $orderBy = 'id DESC'): array
    {
        // tenantId === null => global/all-tenants read (superadmin "All Brands" view):
        // omit the merchant filter so the list aggregates across every brand.
        if ($this->tenantId === null) {
            return $this->paginate($page, $perPage, "({$extraWhere})", $params, $orderBy);
        }
        $params['_tenant'] = $this->tenantId;
        $where = "merchant_id = :_tenant AND ({$extraWhere})";
        return $this->paginate($page, $perPage, $where, $params, $orderBy);
    }

    /**
     * Creates a new database record automatically injected with the active merchant identifier.
     *
     * @param array<string, mixed> $data Field values to insert.
     * @return string Last inserted primary key ID.
     */
    public function createScoped(array $data): string
    {
        $data['merchant_id'] = $this->requireTenant();
        return $this->create($data);
    }

    /**
     * Updates an existing database record restricted within the active tenant context.
     *
     * @param int|string $id Primary key identifier.
     * @param array<string, mixed> $data Field values to update.
     * @return int Number of affected rows.
     */
    public function updateScoped(int|string $id, array $data): int
    {
        $filtered = $this->filterFillable($data);
        // Security constraint: Prevent modification of merchant_id during update to block cross-tenant resource migration attacks.
        unset($filtered['merchant_id']);
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

    /**
     * Deletes a database record restricted within the active tenant context.
     *
     * @param int|string $id Primary key identifier.
     * @return int Number of affected rows.
     */
    public function deleteScoped(int|string $id): int
    {
        return $this->db->delete(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id AND merchant_id = :mid",
            ['id' => $id, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Counts the total number of records matching the scope and optional criteria.
     *
     * @param string $extraWhere Additional SQL WHERE conditions.
     * @param array<string, mixed> $params Parameter binds for the extra WHERE query.
     * @return int Total matching records count.
     */
    public function countScoped(string $extraWhere = '1=1', array $params = []): int
    {
        // tenantId === null => global/all-tenants count (aggregate across all brands).
        if ($this->tenantId === null) {
            return $this->db->count($this->table, "({$extraWhere})", $params);
        }
        $params['_mid'] = $this->tenantId;
        return $this->db->count(
            $this->table,
            "merchant_id = :_mid AND ({$extraWhere})",
            $params
        );
    }
}
