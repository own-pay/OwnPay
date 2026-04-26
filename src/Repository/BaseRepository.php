<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\Database;
use OwnPay\Core\UuidGenerator;

/**
 * Abstract base repository — provides type-safe CRUD for all op_* tables.
 *
 * All queries use prepared statements with named parameters.
 * Subclasses only need to set $table and optionally override methods.
 */
abstract class BaseRepository
{
    protected Database $db;

    /** Fully-qualified table name including prefix, e.g. 'op_merchants'. */
    protected string $table;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    // ─── Read ────────────────────────────────────────────────────────

    /**
     * Find a single row by internal auto-increment ID.
     * Automatically applies tenant scoping if the repository uses TenantScope.
     */
    public function findById(int $id): ?array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE `id` = :id";
        $params = ['id' => $id];

        // Apply tenant scope if available (repositories using TenantScope trait)
        if (method_exists($this, 'tenantCondition')) {
            $sql .= $this->tenantCondition();
            $params = array_merge($params, $this->tenantParams());
        }

        return $this->db->fetchOne($sql . ' LIMIT 1', $params);
    }

    /**
     * Find a single row by public UUID.
     * Automatically applies tenant scoping if the repository uses TenantScope.
     */
    public function findByPublicId(string $publicId): ?array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE `public_id` = :pid";
        $params = ['pid' => $publicId];

        // Apply tenant scope if available (repositories using TenantScope trait)
        if (method_exists($this, 'tenantCondition')) {
            $sql .= $this->tenantCondition();
            $params = array_merge($params, $this->tenantParams());
        }

        return $this->db->fetchOne($sql . ' LIMIT 1', $params);
    }

    /**
     * Find rows matching a WHERE clause with named parameters.
     *
     * @param string $where  e.g. "status = :status AND merchant_id = :mid"
     * @param array  $params e.g. ['status' => 'active', 'mid' => 5]
     * @param string $orderBy e.g. "created_at DESC"
     * @param int|null $limit
     */
    public function findWhere(
        string $where,
        array $params = [],
        string $orderBy = '',
        ?int $limit = null
    ): array {
        $sql = "SELECT * FROM `{$this->table}` WHERE {$where}";

        if ($orderBy !== '') {
            $sql .= " ORDER BY {$orderBy}";
        }
        if ($limit !== null) {
            $sql .= " LIMIT {$limit}";
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Find a single row matching a WHERE clause.
     */
    public function findOneWhere(string $where, array $params = []): ?array
    {
        $rows = $this->findWhere($where, $params, '', 1);
        return $rows[0] ?? null;
    }

    /**
     * Count rows matching a WHERE clause.
     */
    public function count(string $where = '1=1', array $params = []): int
    {
        $result = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM `{$this->table}` WHERE {$where}",
            $params
        );
        return (int) $result;
    }

    // ─── Write ───────────────────────────────────────────────────────

    /**
     * Insert a row and return the auto-increment ID.
     *
     * Automatically generates public_id (UUID v7) if the column exists
     * and is not provided in $data.
     *
     * @param array<string, mixed> $data Column => value pairs
     * @return int The inserted row's auto-increment ID
     */
    public function insert(array $data): int
    {
        // Auto-generate public_id if not provided
        if (!isset($data['public_id']) && $this->hasPublicId()) {
            $data['public_id'] = UuidGenerator::generate();
        }

        // Auto-set created_at / updated_at if not provided
        $now = gmdate('Y-m-d H:i:s.u');
        if (!isset($data['created_at'])) {
            $data['created_at'] = $now;
        }
        if (!isset($data['updated_at']) && $this->hasUpdatedAt()) {
            $data['updated_at'] = $now;
        }

        $columns = array_keys($data);
        $placeholders = array_map(fn(string $col) => ":{$col}", $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $this->table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        $this->db->execute($sql, $data);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update rows matching a WHERE clause.
     *
     * @param array<string, mixed> $data   Column => value pairs to SET
     * @param string               $where  WHERE clause with named placeholders
     * @param array<string, mixed> $params WHERE parameters
     * @return int Number of affected rows
     */
    public function update(array $data, string $where, array $params = []): int
    {
        // Auto-touch updated_at
        if (!isset($data['updated_at']) && $this->hasUpdatedAt()) {
            $data['updated_at'] = gmdate('Y-m-d H:i:s.u');
        }

        $setClauses = [];
        $merged = [];
        foreach ($data as $col => $value) {
            $paramKey = "set_{$col}";
            $setClauses[] = "`{$col}` = :{$paramKey}";
            $merged[$paramKey] = $value;
        }

        // Merge WHERE params (prefixed to avoid collision)
        foreach ($params as $k => $v) {
            $merged[$k] = $v;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $this->table,
            implode(', ', $setClauses),
            $where
        );

        $stmt = $this->db->execute($sql, $merged);
        return $stmt->rowCount();
    }

    /**
     * Update a single row by internal ID.
     */
    public function updateById(int $id, array $data): int
    {
        return $this->update($data, '`id` = :where_id', ['where_id' => $id]);
    }

    /**
     * Soft-delete by setting deleted_at timestamp.
     */
    public function softDelete(int $id): int
    {
        return $this->update(
            ['deleted_at' => gmdate('Y-m-d H:i:s.u')],
            '`id` = :where_id AND `deleted_at` IS NULL',
            ['where_id' => $id]
        );
    }

    /**
     * Hard-delete a row by ID.
     */
    public function delete(int $id): int
    {
        $stmt = $this->db->execute(
            "DELETE FROM `{$this->table}` WHERE `id` = :id",
            ['id' => $id]
        );
        return $stmt->rowCount();
    }

    // ─── Schema Hints ────────────────────────────────────────────────
    // Subclasses can override these if their table doesn't have these columns.

    protected function hasPublicId(): bool
    {
        return true;
    }

    protected function hasUpdatedAt(): bool
    {
        return true;
    }
}
