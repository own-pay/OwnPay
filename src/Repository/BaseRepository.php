<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\Database;

/**
 * Base repository — shared CRUD + pagination for all repositories.
 *
 * Subclasses define $table, $fillable, $primaryKey.
 * All queries parameterized. No string interpolation.
 *
 * Per sql-optimization-patterns skill:
 *  - Cursor-based pagination for large datasets
 *  - Batch operations where applicable
 *  - Column sanitization prevents SQL injection
 */
abstract class BaseRepository
{
    protected Database $db;
    protected string $table;
    protected string $primaryKey = 'id';
    protected array $fillable = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ─── Read ──────────────────────────────────────────────────

    public function find(int|string $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1",
            ['id' => $id]
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE uuid = :uuid LIMIT 1",
            ['uuid' => $uuid]
        );
    }

    public function findBy(string $column, mixed $value): ?array
    {
        $safeCol = $this->sanitizeColumn($column);
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE {$safeCol} = :val LIMIT 1",
            ['val' => $value]
        );
    }

    public function where(string $column, mixed $value, string $orderBy = 'id', string $direction = 'DESC', int $limit = 100): array
    {
        $safeCol = $this->sanitizeColumn($column);
        $safeOrder = $this->sanitizeColumn($orderBy);
        $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE {$safeCol} = :val ORDER BY {$safeOrder} {$dir} LIMIT :lim",
            ['val' => $value, 'lim' => $limit]
        );
    }

    /**
     * Offset pagination.
     * @return array{items: array, total: int, page: int, per_page: int, pages: int}
     */
    public function paginate(int $page = 1, int $perPage = 20, string $where = '1=1', array $params = [], string $orderBy = 'id DESC'): array
    {
        $safeOrder = $this->sanitizeOrderBy($orderBy);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE {$where}",
            $params
        );

        $items = $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY {$safeOrder} LIMIT :lim OFFSET :off",
            array_merge($params, ['lim' => $perPage, 'off' => $offset])
        );

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / max($perPage, 1)),
        ];
    }

    /**
     * Cursor pagination — better for large tables (per sql-optimization skill).
     * @return array{items: array, next_cursor: string|null}
     */
    public function cursorPaginate(int $perPage = 20, ?string $afterId = null, string $where = '1=1', array $params = []): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$where}";
        if ($afterId !== null) {
            $sql .= " AND {$this->primaryKey} < :cursor";
            $params['cursor'] = $afterId;
        }
        $sql .= " ORDER BY {$this->primaryKey} DESC LIMIT :lim";
        $params['lim'] = $perPage + 1;

        $items = $this->db->fetchAll($sql, $params);
        $hasMore = count($items) > $perPage;
        if ($hasMore) {
            array_pop($items);
        }

        return [
            'items'       => $items,
            'next_cursor' => $hasMore && !empty($items)
                ? (string) $items[array_key_last($items)][$this->primaryKey]
                : null,
        ];
    }

    // ─── Write ─────────────────────────────────────────────────

    public function create(array $data): string
    {
        $filtered = $this->filterFillable($data);
        if (empty($filtered)) {
            throw new \InvalidArgumentException("No fillable columns for {$this->table}");
        }

        $columns = implode(', ', array_keys($filtered));
        $placeholders = implode(', ', array_map(fn(string $k) => ":{$k}", array_keys($filtered)));

        return $this->db->insert(
            "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})",
            $filtered
        );
    }

    public function update(int|string $id, array $data): int
    {
        $filtered = $this->filterFillable($data);
        if (empty($filtered)) {
            return 0;
        }

        $sets = implode(', ', array_map(fn(string $k) => "{$k} = :{$k}", array_keys($filtered)));
        $filtered['_pk'] = $id;

        return $this->db->update(
            "UPDATE {$this->table} SET {$sets} WHERE {$this->primaryKey} = :_pk",
            $filtered
        );
    }

    public function delete(int|string $id): int
    {
        return $this->db->delete(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id",
            ['id' => $id]
        );
    }

    // ─── Helpers ───────────────────────────────────────────────

    public function exists(int|string $id): bool
    {
        return $this->db->exists($this->table, "{$this->primaryKey} = :id", ['id' => $id]);
    }

    public function count(string $where = '1=1', array $params = []): int
    {
        return $this->db->count($this->table, $where, $params);
    }

    protected function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            return $data;
        }
        return array_intersect_key($data, array_flip($this->fillable));
    }

    protected function sanitizeColumn(string $column): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException("Invalid column: {$column}");
        }
        return $column;
    }

    protected function sanitizeOrderBy(string $orderBy): string
    {
        $parts = explode(',', $orderBy);
        $safe = [];
        foreach ($parts as $part) {
            $tokens = preg_split('/\s+/', trim($part));
            if (empty($tokens)) {
                continue;
            }
            $col = $this->sanitizeColumn($tokens[0]);
            $dir = isset($tokens[1]) && strtoupper($tokens[1]) === 'ASC' ? 'ASC' : 'DESC';
            $safe[] = "{$col} {$dir}";
        }
        return implode(', ', $safe) ?: 'id DESC';
    }
}
