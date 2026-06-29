<?php
declare(strict_types=1);

namespace OwnPay\Core;

use OwnPay\Event\EventManager;
use OwnPay\Plugin\PluginRegistry;
use PDO;
use PDOStatement;

/**
 * Class Database
 *
 * Thin database wrapper around PDO.
 * Provides convenience methods for common queries while maintaining
 * full prepared-statement safety. No raw string interpolation ever.
 * Injected via DI container - never instantiate directly.
 *
 * @package OwnPay\Core
 */
class Database
{
    /**
     * @var PDO The underlying PDO instance.
     */
    private PDO $pdo;

    /**
     * @var self|null The singleton instance used for testing/fallback context.
     */
    private static ?self $instance = null;

    /**
     * @var EventManager|null Optional EventManager for query hooks.
     */
    private ?EventManager $events = null;

    /**
     * @var PluginRegistry|null Optional PluginRegistry for sandbox checks.
     */
    private ?PluginRegistry $registry = null;

    /**
     * @var bool Guard against infinite recursion when hooks themselves query the database.
     */
    private bool $firingHooks = false;

    /**
     * Database constructor.
     *
     * @param PDO $pdo The underlying PDO connection.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Resets the singleton instance (used for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Manually registers the singleton instance (used in container factory).
     *
     * @param self $instance The active database instance.
     * @return void
     */
    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Injects the EventManager after container build.
     * Called by Kernel::boot() once DI is ready.
     *
     * @param EventManager $events The event manager.
     * @return void
     */
    public function setEventManager(EventManager $events): void
    {
        $this->events = $events;
    }

    /**
     * Injects the PluginRegistry after container build.
     * Called by Kernel::boot() once DI is ready.
     *
     * @param PluginRegistry $registry The plugin registry.
     * @return void
     */
    public function setPluginRegistry(PluginRegistry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * Creates and stores a singleton instance from connection parameters.
     * Used by integration tests that cannot access the DI container.
     *
     * @param string $host   The database host.
     * @param string $name   The database name.
     * @param string $user   The database username.
     * @param string $pass   The database password.
     * @param int    $port   The database port.
     * @return self The initialized Database instance.
     */
    public static function init(
        string $host,
        string $name,
        string $user,
        string $pass,
        int $port = 3306
    ): self {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        self::$instance = new self($pdo);
        return self::$instance;
    }

    /**
     * Retrieves the singleton set by init().
     *
     * @throws \RuntimeException If init() was never called.
     * @return self The active Database instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Database::init() must be called before getInstance().');
        }
        return self::$instance;
    }

    /**
     * Returns the underlying PDO connection.
     *
     * @return PDO The PDO connection object.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Fetches all matching rows for a query.
     *
     * @param string $sql    The SQL query.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @return array<int, array<string, mixed>> The array of query results.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetches a single row.
     *
     * @param string $sql    The SQL query.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @return array<string, mixed>|null The result row or null if not found.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->execute($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $mapped = [];
            foreach ($row as $k => $v) {
                $mapped[(string)$k] = $v;
            }
            return $mapped;
        }
        return null;
    }

    /**
     * Fetches a single column value.
     *
     * @param string $sql    The SQL query.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @param int    $column The 0-indexed column offset to fetch.
     * @return mixed The column value or null if not found.
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $stmt = $this->execute($sql, $params);
        $value = $stmt->fetchColumn($column);
        return $value !== false ? $value : null;
    }

    /**
     * Prepares and executes an SQL statement with parameter binding.
     *
     * @param string $sql    The SQL query.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @throws \RuntimeException If the query is blocked by sandbox policy.
     * @return PDOStatement The executed PDO statement.
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        // Fire db.query.before filter - plugins can modify SQL/params
        // Guard prevents infinite recursion when hook listeners query DB
        if ($this->events !== null && !$this->firingHooks) {
            $this->firingHooks = true;
            try {
                /** @var array<string, mixed> $queryData */
                $queryData = $this->events->applyFilter('db.query.before', [
                    'sql' => $sql,
                    'params' => $params,
                ]);
                if (isset($queryData['sql']) && is_string($queryData['sql'])) {
                    $sql = $queryData['sql'];
                }
                if (isset($queryData['params']) && is_array($queryData['params'])) {
                    $params = $queryData['params'];
                }
            } finally {
                $this->firingHooks = false;
            }
        }

        // Validate SQL query safety if executed within plugin context.
        if ($this->registry !== null && $this->events !== null) {
            $activeOwner = $this->events->getActiveOwner();
            if ($activeOwner !== 'core') {
                $sandbox = $this->registry->getSandbox($activeOwner);
                if ($sandbox !== null) {
                    if (!$sandbox->validateSql($sql)) {
                        throw new \RuntimeException(
                            "Database query blocked by plugin sandbox for '{$activeOwner}': direct access to core tables or dangerous SQL operations are restricted."
                        );
                    }
                }
            }
        }

        $start = hrtime(true);
        $stmt = $this->pdo->prepare($sql);

        // With ATTR_EMULATE_PREPARES=false, PDO requires
        // LIMIT/OFFSET bound as PDO::PARAM_INT. Using $stmt->execute()
        // binds everything as PDO::PARAM_STR, causing MySQL errors.
        foreach ($params as $key => $value) {
            $paramKey = is_int($key) ? $key + 1 : ":{$key}";
            if (is_int($value)) {
                $stmt->bindValue($paramKey, $value, PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($paramKey, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $stmt->bindValue($paramKey, $value, PDO::PARAM_BOOL);
            } else {
                $stmt->bindValue($paramKey, $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        // Fire db.query.after action - profiling, audit logging
        if ($this->events !== null && !$this->firingHooks) {
            $this->firingHooks = true;
            try {
                $this->events->doAction('db.query.after', $sql, $params, $durationMs);
            } finally {
                $this->firingHooks = false;
            }
        }

        return $stmt;
    }

    /**
     * Executes an INSERT query and returns the last insert ID.
     *
     * @param string $sql    The SQL INSERT statement.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @return string The auto-incremented last insert ID.
     */
    public function insert(string $sql, array $params = []): string
    {
        $this->execute($sql, $params);
        $id = $this->pdo->lastInsertId();
        return is_string($id) ? $id : '0';
    }

    /**
     * Executes an UPDATE query and returns the number of affected rows.
     *
     * @param string $sql    The SQL UPDATE statement.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @return int The count of affected rows.
     */
    public function update(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params)->rowCount();
    }

    /**
     * Executes a DELETE query and returns the number of deleted rows.
     *
     * @param string $sql    The SQL DELETE statement.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @return int The count of deleted rows.
     */
    public function delete(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params)->rowCount();
    }

    /**
     * Wraps execution in a transaction context.
     *
     * @template T
     * @param callable(): T $callback The transaction operations.
     * @throws \Throwable If a statement fails, transaction is rolled back.
     * @return T The callback result.
     */
    public function transaction(callable $callback): mixed
    {
        $hasActive = $this->pdo->inTransaction();
        if (!$hasActive) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if (!$hasActive) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if (!$hasActive) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Begins an SQL transaction.
     *
     * @return void
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Commits the active transaction.
     *
     * @return void
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * Rolls back the active transaction.
     *
     * @return void
     */
    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * Checks if transaction is active.
     *
     * @return bool True if currently in transaction, false otherwise.
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Checks if a row exists in the database.
     *
     * @param string $table  The table name.
     * @param string $where  The SQL WHERE clause.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @throws \InvalidArgumentException If table name contains forbidden characters.
     * @return bool True if row exists, false otherwise.
     */
    public function exists(string $table, string $where, array $params = []): bool
    {
        // Validate table name to prevent SQL injection via caller
        if (!preg_match('/^[a-zA-Z0-9_`]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name: ' . $table);
        }
        $sql = "SELECT 1 FROM {$table} WHERE {$where} LIMIT 1";
        return $this->fetchColumn($sql, $params) !== null;
    }

    /**
     * Counts rows matching selection parameters.
     *
     * @param string $table  The table name.
     * @param string $where  The SQL WHERE clause.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @throws \InvalidArgumentException If table name contains forbidden characters.
     * @return int The row count.
     */
    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        // Validate table name to prevent SQL injection via caller
        if (!preg_match('/^[a-zA-Z0-9_`]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name: ' . $table);
        }
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $val = $this->fetchColumn($sql, $params);
        return is_scalar($val) ? (int) $val : 0;
    }

    /**
     * Returns the last auto-incremented ID.
     *
     * @return string The last insert ID.
     */
    public function lastInsertId(): string
    {
        $id = $this->pdo->lastInsertId();
        return is_string($id) ? $id : '0';
    }
}
