<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\Database;

/**
 * Base Repository providing common CRUD operations and query orchestration.
 *
 * Implements parameterized queries, schema column sanitization, standard offset pagination,
 * and high-performance cursor pagination.
 */
abstract class BaseRepository
{
    /**
     * The database adapter instance.
     *
     * @var Database
     */
    protected Database $db;

    /**
     * The name of the database table.
     *
     * @var string
     */
    protected string $table;

    /**
     * The primary key column name.
     *
     * @var string
     */
    protected string $primaryKey = 'id';

    /**
     * List of columns allowed to be mass-assigned.
     *
     * @var array<int, string>
     */
    protected array $fillable = [];

    /**
     * Hard upper bound on the number of rows a single paginate()/
     * cursorPaginate() call may return (REPO-3).
     *
     * Without this clamp, a caller passing $perPage = 1_000_000 causes the
     * DB to materialise up to 1M rows and PDO::FETCH_ASSOC to hydrate them
     * all into one array, easily exhausting the FPM worker's memory limit
     * (default 128M ~ 1.3k wide transaction rows before OOM). The OFFSET
     * math explodes the same way and forces MySQL to scan-and-discard a
     * million rows - a slow-query DoS on top of the memory pressure.
     */
    public const int MAX_PER_PAGE = 100;

    /**
     * Initializes the repository with the database connector.
     *
     * @param Database $db Database adapter instance.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // --- Read --------------------------------------------------

    /**
     * Retrieves a single record by primary key value.
     *
     * @param int|string $id Primary key identifier.
     * @return array<string, mixed>|null Database row array, or null if not found.
     */
    public function find(int|string $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Retrieves a single record by UUID.
     *
     * @param string $uuid The UUID string.
     * @return array<string, mixed>|null Database row array, or null if not found.
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE uuid = :uuid LIMIT 1",
            ['uuid' => $uuid]
        );
    }

    /**
     * Retrieves a single record matching a specific column value.
     *
     * @param string $column Column name to search.
     * @param mixed $value Value to filter by.
     * @return array<string, mixed>|null Database row array, or null if not found.
     * @throws \InvalidArgumentException If the column name is malformed.
     */
    public function findBy(string $column, mixed $value): ?array
    {
        $safeCol = $this->sanitizeColumn($column);
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE {$safeCol} = :val LIMIT 1",
            ['val' => $value]
        );
    }

    /**
     * Retrieves a list of records matching a column value with ordering and limit.
     *
     * @param string $column Column name to filter.
     * @param mixed $value Value to match.
     * @param string $orderBy Column name to order by.
     * @param string $direction Sort direction ('ASC' or 'DESC').
     * @param int $limit Maximum records to return.
     * @return array<int, array<string, mixed>> List of matching database rows.
     * @throws \InvalidArgumentException If columns or sort parameters are malformed.
     */
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
     * Executes standard offset-based pagination.
     *
     * Validates where constraints to block potential SQL injection sequences.
     *
     * @param int $page Page number (1-indexed).
     * @param int $perPage Maximum items per page.
     * @param string $where Additional SQL WHERE conditions.
     * @param array<string, mixed> $params Parameter binds for the WHERE query.
     * @param string $orderBy SQL ORDER BY clause.
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int} Pagination envelope.
     * @throws \InvalidArgumentException If the WHERE clause contains forbidden SQL structures or injection patterns.
     */
    public function paginate(int $page = 1, int $perPage = 20, string $where = '1=1', array $params = [], string $orderBy = 'id DESC'): array
    {
        // (REPO-2) Shared safety check: reject forbidden SQL structures in $where.
        // The helper also implements the REPO-1 conditional-comment defence
        // (checks both the original and cleaned WHERE clause for keywords) and
        // returns the cleaned WHERE for execution.
        $cleanedWhere = $this->validateWhereClause($where);

        $safeOrder = $this->sanitizeOrderBy($orderBy);
        $page = max(1, (int) $page);
        // (REPO-3) Clamp $perPage to a sane maximum so a caller cannot force
        // a million-row result set and OOM the FPM worker / DoS the DB.
        $perPage = $this->clampPerPage($perPage);
        $offset = ($page - 1) * $perPage;

        // REPO-1: execute against $cleanedWhere (comments stripped), not the
        // original $where. This neutralizes conditional comments.
        $totalVal = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE {$cleanedWhere}",
            $params
        );
        $total = is_scalar($totalVal) ? (int) $totalVal : 0;

        $items = $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE {$cleanedWhere} ORDER BY {$safeOrder} LIMIT :lim OFFSET :off",
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
     * Executes high-performance cursor-based pagination.
     *
     * Safe from performance degradation on large tables.
     *
     * @param int $perPage Maximum items per page.
     * @param string|null $afterId Cursor value to start listing after (primary key value).
     * @param string $where Additional SQL WHERE conditions.
     * @param array<string, mixed> $params Parameter binds for the WHERE query.
     * @return array{items: array<int, array<string, mixed>>, next_cursor: string|null} Pagination envelope with cursor pointer.
     */
    public function cursorPaginate(int $perPage = 20, ?string $afterId = null, string $where = '1=1', array $params = []): array
    {
        // (REPO-2) Apply the same forbidden-keyword / comment-strip check as
        // paginate() so a caller that interpolates user input into $where
        // cannot bypass the guard by routing through cursorPaginate().
        // REPO-1: execute against the cleaned $where (comments stripped) to
        // neutralise MySQL conditional comments.
        $cleanedWhere = $this->validateWhereClause($where);

        // (REPO-3) Same $perPage clamp as paginate(); the +1 lookahead below
        // would otherwise inflate a 1M cap to a 1,000,001-row fetch.
        $perPage = $this->clampPerPage($perPage);

        $sql = "SELECT * FROM {$this->table} WHERE {$cleanedWhere}";
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

        $nextCursor = null;
        if ($hasMore && !empty($items)) {
            $lastItem = $items[array_key_last($items)];
            $pkVal = $lastItem[$this->primaryKey] ?? '';
            $nextCursor = is_scalar($pkVal) ? (string) $pkVal : null;
        }

        return [
            'items'       => $items,
            'next_cursor' => $nextCursor,
        ];
    }

    // --- Write

    /**
     * Inserts a new record into the database table, filtering out non-fillable columns.
     *
     * @param array<string, mixed> $data Raw field value pairs to insert.
     * @return string Last inserted primary key ID.
     * @throws \InvalidArgumentException If no fillable columns remain after filtering.
     */
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

    /**
     * Updates an existing database record, filtering out non-fillable columns.
     *
     * @param int|string $id Primary key identifier.
     * @param array<string, mixed> $data Raw field value pairs to update.
     * @return int Number of affected rows.
     */
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

    /**
     * Deletes a record by its primary key.
     *
     * @param int|string $id Primary key identifier.
     * @return int Number of affected rows.
     */
    public function delete(int|string $id): int
    {
        return $this->db->delete(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id",
            ['id' => $id]
        );
    }

    // --- Helpers

    /**
     * Returns the underlying database connection adapter.
     *
     * @return Database Database adapter.
     */
    public function getDatabase(): Database
    {
        return $this->db;
    }

    /**
     * Checks if a record exists in the table matching the primary key value.
     *
     * @param int|string $id Primary key identifier.
     * @return bool True if record exists, false otherwise.
     */
    public function exists(int|string $id): bool
    {
        return $this->db->exists($this->table, "{$this->primaryKey} = :id", ['id' => $id]);
    }

    /**
     * Returns the total count of records matching conditions.
     *
     * @param string $where SQL WHERE clause constraints.
     * @param array<string, mixed> $params Query parameter binds.
     * @return int Total records count.
     */
    public function count(string $where = '1=1', array $params = []): int
    {
        return $this->db->count($this->table, $where, $params);
    }

    /**
     * Intersects the data parameters with the configured list of fillable columns.
     *
     * @param array<string, mixed> $data Raw parameters.
     * @return array<string, mixed> Filtered columns.
     */
    protected function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            return $data;
        }
        return array_intersect_key($data, array_flip($this->fillable));
    }

    /**
     * Enforces alphanumeric column validation to block SQL injection vectors on raw identifiers.
     *
     * @param string $column The target column name.
     * @return string Sanitized column name.
     * @throws \InvalidArgumentException If the column name contains non-alphanumeric characters.
     */
    protected function sanitizeColumn(string $column): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException("Invalid column: {$column}");
        }
        return $column;
    }

    /**
     * Validates a raw SQL WHERE clause fragment before it is concatenated
     * into a query, and returns a comment-stripped version safe for execution.
     *
     * Defence-in-depth (REPO-2): every public method that interpolates a
     * caller-supplied $where string (paginate(), cursorPaginate()) routes
     * through this helper so the safety check cannot be bypassed by picking
     * a different pagination method. The check is NOT a substitute for
     * parameterized binds - callers must always pass user input via $params.
     *
     * REPO-1: returns the cleaned $where (block and line comments stripped)
     * so the caller can execute against the stripped version rather than the
     * original. This neutralises MySQL conditional comments (e.g. the
     * `/*!50000 ... * /` form with the space removed) which would otherwise
     * execute despite being stripped from the keyword check. Strips SQL
     * comments (block `/* ... * /` and line `-- ...`) which could be used
     * to break keyword matching, then rejects dangerous command
     * keywords, statement terminators, and inline comment markers.
     *
     * @param string $where Raw SQL WHERE clause fragment.
     * @return string The cleaned WHERE clause (comments stripped) safe for execution.
     * @throws \InvalidArgumentException If the WHERE clause contains forbidden SQL structures or injection patterns.
     */
    protected function validateWhereClause(string $where): string
    {
        // REPO-1: defence-in-depth against conditional-comment SQL injection.
        //
        // MySQL treats `/*!50000 ... */` as a conditional comment that executes
        // the enclosed SQL only if the server version >= 5.00.00 (essentially
        // always). A naive check that runs keyword matching against the
        // comment-stripped $where only would miss an injection like
        //   WHERE id=1/*!50000 UNION*//*!50000 SELECT * FROM op_users*/
        // because the stripped version becomes `WHERE id=1` (passes) while the
        // executed SQL becomes `WHERE id=1 UNION SELECT * FROM op_users`.
        //
        // Fix: run the keyword check against BOTH the original $where and the
        // cleaned version, so conditional-comment-embedded keywords are caught
        // before stripping.

        // Strip SQL comments to prevent bypass via inline sequences.
        $cleanedWhere = preg_replace('/\/\*.*?\*\//s', ' ', $where) ?? $where;
        $cleanedWhere = preg_replace('/--.*$/m', ' ', $cleanedWhere) ?? $cleanedWhere;

        // Collapse all whitespace and lowercase for consistent safety verification.
        $lowerWhere = strtolower((string) preg_replace('/\s+/', ' ', trim($cleanedWhere)));
        $lowerOriginalWhere = strtolower((string) preg_replace('/\s+/', ' ', trim($where)));

        // Reject SQL command keywords to avoid space-less structures (e.g. select(1) or union(select...)).
        // Check against BOTH the cleaned $where AND the original $where so that
        // conditional-comment-embedded keywords (/*!50000 UNION*/) are caught
        // before stripping.
        if (preg_match('/\b(drop|alter|truncate|union|insert|update|delete|create|select|load_file|into\s+outfile|into\s+dumpfile)\b/i', $cleanedWhere)
            || preg_match('/\b(drop|alter|truncate|union|insert|update|delete|create|select|load_file|into\s+outfile|into\s+dumpfile)\b/i', $where)
            || str_contains($lowerWhere, ';')
            || str_contains($lowerWhere, '--')
            || str_contains($lowerOriginalWhere, ';')
        ) {
            throw new \InvalidArgumentException('Potentially unsafe WHERE clause rejected');
        }

        return $cleanedWhere;
    }

    /**
     * Clamps the supplied page size to a positive integer no greater than
     * MAX_PER_PAGE (REPO-3).
     *
     * Logs a warning via error_log() when a caller exceeds the cap so abusive
     * callers (or buggy controllers forwarding user-supplied per_page) are
     * visible to operators without breaking the request.
     *
     * @param int $perPage Requested page size.
     * @return int Clamped page size (1..MAX_PER_PAGE).
     */
    protected function clampPerPage(int $perPage): int
    {
        $clamped = min(max(1, $perPage), self::MAX_PER_PAGE);
        if ($clamped !== $perPage) {
            error_log(sprintf(
                'BaseRepository::clampPerPage: %s requested per_page=%d, clamped to %d (table=%s)',
                static::class,
                $perPage,
                $clamped,
                $this->table
            ));
        }
        return $clamped;
    }

    /**
     * Sanitizes sort parameters to construct safe ORDER BY clauses.
     *
     * @param string $orderBy Raw order string (e.g. "created_at DESC, id ASC").
     * @return string Validated and structured SQL sort string.
     */
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

    /**
     * Inserts a new record into the database table (alias of create).
     *
     * Used by internal components such as webhook inbound logs.
     *
     * @param array<string, mixed> $data Raw field value pairs to insert.
     * @return string Last inserted primary key ID.
     */
    public function insert(array $data): string
    {
        return $this->create($data);
    }
}
