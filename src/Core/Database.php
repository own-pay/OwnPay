<?php
declare(strict_types=1);

namespace OwnPay\Core;

use OwnPay\Event\EventManager;
use OwnPay\Plugin\PluginRegistry;
use PDO;
use PDOStatement;

/**
 * Thin database wrapper around PDO.
 *
 * Provides convenience methods for common queries while maintaining
 * full prepared-statement safety. No raw string interpolation ever.
 *
 * Injected via DI container — never instantiate directly.
 */
class Database
{
    private PDO $pdo;

    /** @var self|null */
    private static ?self $instance = null;

    /**
     * Reset the singleton instance (used for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * AUD-G3: Optional EventManager for query hooks.
     * Lazily injected after container build to avoid circular DI.
     */
    private ?EventManager $events = null;

    /**
     * AUD-G8: Optional PluginRegistry for sandbox checks.
     */
    private ?PluginRegistry $registry = null;

    /** Guard against infinite recursion when hooks themselves query DB */
    private bool $firingHooks = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * AUD-G3: Inject EventManager after container build.
     * Called by Kernel::boot() once DI is ready.
     */
    public function setEventManager(EventManager $events): void
    {
        $this->events = $events;
    }

    /**
     * AUD-G8: Inject PluginRegistry after container build.
     * Called by Kernel::boot() once DI is ready.
     */
    public function setPluginRegistry(PluginRegistry $registry): void
    {
        $this->registry = $registry;
    }

    

    /**
     * Create and store a singleton instance from connection parameters.
     * Used by integration tests that cannot access the DI container.
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
     * Retrieve the singleton set by init().
     *
     * @throws \RuntimeException if init() was never called.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Database::init() must be called before getInstance().');
        }
        return self::$instance;
    }

    /** Return the underlying PDO connection. */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->execute($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $stmt = $this->execute($sql, $params);
        $value = $stmt->fetchColumn($column);
        return $value !== false ? $value : null;
    }

    public function execute(string $sql, array $params = []): PDOStatement
    {
        // AUD-G8 fix: Validate SQL query safety if executed within plugin context.
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

        // AUD-G3: Fire db.query.before filter — plugins can modify SQL/params
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

        $start = hrtime(true);
        $stmt = $this->pdo->prepare($sql);

        // BUG-14/34 FIX: With ATTR_EMULATE_PREPARES=false, PDO requires
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

        // AUD-G3: Fire db.query.after action — profiling, audit logging
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

    public function insert(string $sql, array $params = []): string
    {
        $this->execute($sql, $params);
        return $this->pdo->lastInsertId();
    }

    public function update(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params)->rowCount();
    }

    public function delete(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params)->rowCount();
    }

    

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    

    public function exists(string $table, string $where, array $params = []): bool
    {
        // Validate table name to prevent SQL injection via caller
        if (!preg_match('/^[a-zA-Z0-9_`]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name: ' . $table);
        }
        $sql = "SELECT 1 FROM {$table} WHERE {$where} LIMIT 1";
        return $this->fetchColumn($sql, $params) !== null;
    }

    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        // Validate table name to prevent SQL injection via caller
        if (!preg_match('/^[a-zA-Z0-9_`]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name: ' . $table);
        }
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        return (int) $this->fetchColumn($sql, $params);
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }
}
