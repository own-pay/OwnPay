<?php

declare(strict_types=1);

namespace OwnPay\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thread-safe PDO singleton for Own Pay.
 *
 * Usage:
 *   $db = Database::getInstance();
 *   $db->fetchAll('SELECT * FROM op_merchants WHERE status = ?', ['active']);
 */
final class Database
{
    private static ?self $instance = null;

    private PDO $pdo;

    private function __construct(
        string $host,
        string $dbName,
        string $user,
        string $pass,
        int    $port = 3306,
        string $charset = 'utf8mb4'
    ) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $dbName,
            $charset
        );

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            ]);

            // Force UTC for all connections
            $this->pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup(): never
    {
        throw new RuntimeException('Cannot unserialize Database singleton.');
    }

    /**
     * Initialize the singleton with explicit credentials.
     */
    public static function init(
        string $host,
        string $dbName,
        string $user,
        string $pass,
        int    $port = 3306
    ): self {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = new self($host, $dbName, $user, $pass, $port);
        return self::$instance;
    }

    /**
     * Get the singleton instance. Must call init() first.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException(
                'Database not initialized. Call Database::init() first.'
            );
        }

        return self::$instance;
    }

    /**
     * Reset singleton (for testing only).
     * @internal
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // ─── Query Helpers ───────────────────────────────────────────────

    /**
     * Execute a statement and return the PDOStatement.
     *
     * @param string $sql    SQL with ? or :named placeholders
     * @param array  $params Positional or named parameters
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch all rows as associative arrays.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single row.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fetch a single scalar value.
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        return $this->execute($sql, $params)->fetchColumn($column);
    }

    /**
     * Get the last inserted auto-increment ID.
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Get the row count affected by the last statement.
     */
    public function rowCount(PDOStatement $stmt): int
    {
        return $stmt->rowCount();
    }

    // ─── Transaction Control ─────────────────────────────────────────

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Execute a callable inside a DB transaction.
     * Automatically commits on success, rolls back on exception.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Get the raw PDO instance (escape hatch for advanced queries).
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
