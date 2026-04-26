<?php
declare(strict_types=1);

namespace OwnPay\Service;

use OwnPay\Core\Database;
use PDO;
use PDOException;

/**
 * Modern replacement for procedural getData(), insertData(), updateData(), deleteData().
 *
 * Key differences from the legacy functions:
 *   - select() returns an array directly (not a JSON string)
 *   - Uses Database::getInstance() singleton (not connectDatabase())
 *   - All methods are static for easy migration from procedural calls
 *   - Legacy "--" sentinel values are transparently converted to null
 */
final class CrudService
{
    /**
     * Validate a SQL fragment ($condition / $select / $tableName) against injection
     * patterns. Rejects strings that contain SQL string-literal quotes, statement
     * terminators, or comment markers — UNLESS $allowRaw is true (rare legitimate
     * cases like `ORDER BY created_at DESC LIMIT 10`).
     *
     * Throws InvalidArgumentException on rejection so the caller cannot silently
     * bypass the guard.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertSafeSqlFragment(string $value, string $context): void
    {
        // String quotes — legitimate values must be passed via $params
        if (str_contains($value, "'") || str_contains($value, '"')) {
            throw new \InvalidArgumentException(
                "CrudService: {$context} fragment contains a string literal. Pass values via the \$params array (named placeholders like :foo)."
            );
        }
        // Statement terminator — only one statement per call allowed
        if (str_contains($value, ';')) {
            throw new \InvalidArgumentException(
                "CrudService: {$context} fragment contains a semicolon. Multiple statements per call are not permitted."
            );
        }
        // SQL comment markers
        if (str_contains($value, '--') || str_contains($value, '/*') || str_contains($value, '*/')) {
            throw new \InvalidArgumentException(
                "CrudService: {$context} fragment contains a SQL comment marker (--, /*, */)."
            );
        }
        // NUL byte
        if (str_contains($value, "\0")) {
            throw new \InvalidArgumentException(
                "CrudService: {$context} fragment contains a NUL byte."
            );
        }
    }

    /**
     * Validate that a table name is a safe identifier.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertSafeTableName(string $tableName): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $tableName)) {
            throw new \InvalidArgumentException(
                "CrudService: invalid table name '{$tableName}'. Must match /^[A-Za-z_][A-Za-z0-9_]{0,63}\$/."
            );
        }
    }

    /**
     * SELECT rows from a table.
     *
     * Returns ['status' => bool, 'response' => array] — same structure as
     * json_decode(getData(...), true) but WITHOUT the json_encode/decode overhead.
     *
     * SECURITY CONTRACT (F1 — see docs/security_audit/full_codebase_audit.md):
     *   - $tableName must match /^[A-Za-z_][A-Za-z0-9_]{0,63}$/ (validated)
     *   - $condition / $select must NEVER contain user input as literal text;
     *     pass values via $params with named placeholders (:foo)
     *   - The injection guard rejects single-quote, double-quote, semicolon,
     *     and SQL comment markers in $condition/$select. Set $allowRawCondition
     *     to true ONLY for hardcoded fragments like "ORDER BY id DESC LIMIT 10"
     *     that you know are constant.
     *
     * @param string $tableName  Fully-qualified table name (e.g. $db_prefix . 'transaction')
     * @param string $condition  WHERE clause with named params (e.g. "WHERE id = :id")
     * @param string $select     Column expression (default: '* FROM')
     * @param array  $params     Named parameter bindings
     * @param bool   $allowRawCondition  Opt-in to bypass injection guard for known-constant fragments
     * @return array{status: bool, response: array}
     * @throws \InvalidArgumentException on injection-pattern rejection
     */
    public static function select(
        string $tableName,
        string $condition = '',
        string $select = '* FROM',
        array $params = [],
        bool $allowRawCondition = false,
    ): array {
        self::assertSafeTableName($tableName);
        if (!$allowRawCondition) {
            self::assertSafeSqlFragment($condition, '$condition');
            self::assertSafeSqlFragment($select, '$select');
        }

        $pdo = Database::getInstance()->getPdo();

        $sql = "SELECT {$select} `{$tableName}` {$condition}";

        try {
            $stmt = $pdo->prepare($sql);

            foreach ($params as $key => $value) {
                $pdoType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $pdoType);
            }

            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Transition bridge: convert legacy "--" sentinel values to NULL.
            foreach ($data as &$row) {
                foreach ($row as $col => $val) {
                    if ($val === '--') {
                        $row[$col] = null;
                    }
                }
            }
            unset($row);

            return [
                'status' => !empty($data),
                'response' => $data,
            ];
        } catch (PDOException $e) {
            error_log("CrudService::select PDO Error: " . $e->getMessage());
            return ['status' => false, 'response' => []];
        }
    }

    /**
     * INSERT a row into a table.
     *
     * Auto-discovers table columns via SHOW COLUMNS and fills defaults
     * for any columns not provided. Same behavior as legacy insertData().
     *
     * @param string $tableName  Fully-qualified table name
     * @param array  $columns    Column names to insert
     * @param array  $values     Corresponding values
     * @return bool  True on success
     */
    public static function insert(string $tableName, array $columns, array $values): bool
    {
        $pdo = Database::getInstance()->getPdo();

        try {
            $stmtColumns = $pdo->prepare("SHOW COLUMNS FROM `{$tableName}`");
            $stmtColumns->execute();
            $tableCols = $stmtColumns->fetchAll(PDO::FETCH_ASSOC);

            $finalColumns = [];
            $finalValues = [];
            $placeholders = [];

            $userData = array_combine($columns, $values);

            foreach ($tableCols as $col) {
                $colName = $col['Field'];

                if (stripos($col['Extra'], 'auto_increment') !== false && !isset($userData[$colName])) {
                    continue;
                }

                $finalColumns[] = $colName;
                $placeholders[] = ":val_{$colName}";

                if (isset($userData[$colName])) {
                    $finalValues[$colName] = $userData[$colName];
                } elseif ($col['Default'] !== null) {
                    $finalValues[$colName] = $col['Default'];
                } else {
                    $finalValues[$colName] = null;
                }
            }

            $sql = "INSERT INTO `{$tableName}` (" . implode(', ', $finalColumns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);

            foreach ($finalValues as $colName => $val) {
                $stmt->bindValue(":val_{$colName}", $val);
            }

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("CrudService::insert failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * UPDATE rows in a table.
     *
     * @param string $tableName   Fully-qualified table name
     * @param array  $columns     Column names to update
     * @param array  $values      Corresponding new values
     * @param string $condition   WHERE clause (e.g. "id = :id")
     * @param array  $whereParams Named parameters for the WHERE clause
     * @return bool  True on success
     */
    public static function update(
        string $tableName,
        array $columns,
        array $values,
        string $condition,
        array $whereParams = [],
    ): bool {
        $pdo = Database::getInstance()->getPdo();

        $setClauses = [];
        foreach ($columns as $index => $col) {
            $setClauses[] = "`{$col}` = :val{$index}";
        }
        $setString = implode(', ', $setClauses);

        $sql = "UPDATE `{$tableName}` SET {$setString} WHERE {$condition}";

        try {
            $stmt = $pdo->prepare($sql);

            foreach ($values as $index => $value) {
                if ($value === '' || $value === null) {
                    $stmt->bindValue(":val{$index}", null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(":val{$index}", $value);
                }
            }

            foreach ($whereParams as $key => $val) {
                $pdoType = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $val, $pdoType);
            }

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("CrudService::update PDO Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * DELETE rows from a table.
     *
     * @param string $tableName   Fully-qualified table name
     * @param string $condition   WHERE clause (e.g. "id = :id")
     * @param array  $whereParams Named parameters for the WHERE clause
     * @return bool  True on success
     */
    public static function delete(string $tableName, string $condition, array $whereParams = []): bool
    {
        $pdo = Database::getInstance()->getPdo();

        $sql = "DELETE FROM `{$tableName}` WHERE {$condition}";

        try {
            $stmt = $pdo->prepare($sql);

            foreach ($whereParams as $key => $val) {
                $pdoType = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $val, $pdoType);
            }

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("CrudService::delete PDO Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Optimistic-lock–aware UPDATE.
     *
     * Appends `AND version = :current_version` to the WHERE clause and
     * auto-increments `version`. Returns affected row count (0 = stale).
     *
     * @return int Affected rows (0 means concurrent modification detected)
     */
    public static function optimisticUpdate(
        string $tableName,
        array $columns,
        array $values,
        string $condition,
        array $whereParams,
        int $currentVersion,
    ): int {
        $pdo = Database::getInstance()->getPdo();

        $setClauses = [];
        foreach ($columns as $index => $col) {
            $setClauses[] = "`{$col}` = :val{$index}";
        }
        $setClauses[] = '`version` = `version` + 1';
        $setString = implode(', ', $setClauses);

        $sql = "UPDATE `{$tableName}` SET {$setString} WHERE {$condition} AND `version` = :_olv_version";

        try {
            $stmt = $pdo->prepare($sql);

            foreach ($values as $index => $value) {
                if ($value === '' || $value === null) {
                    $stmt->bindValue(":val{$index}", null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(":val{$index}", $value);
                }
            }

            foreach ($whereParams as $key => $val) {
                $pdoType = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $val, $pdoType);
            }

            $stmt->bindValue(':_olv_version', $currentVersion, PDO::PARAM_INT);

            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("CrudService::optimisticUpdate PDO Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count rows matching a condition (replacement for limit_checker).
     *
     * SECURITY CONTRACT (F1 — see docs/security_audit/full_codebase_audit.md):
     *   Same injection guard as ::select(). $condition must use named placeholders
     *   for any user data, and $tableName must match the safe identifier regex.
     *
     * @param string $tableName  Fully-qualified table name
     * @param string $condition  WHERE clause (e.g. "WHERE status = :status")
     * @param array  $params     Named parameter bindings
     * @param bool   $allowRawCondition  Opt-in to bypass injection guard
     * @return int
     * @throws \InvalidArgumentException on injection-pattern rejection
     */
    public static function count(
        string $tableName,
        string $condition = '',
        array $params = [],
        bool $allowRawCondition = false,
    ): int {
        self::assertSafeTableName($tableName);
        if (!$allowRawCondition) {
            self::assertSafeSqlFragment($condition, '$condition');
        }

        $pdo = Database::getInstance()->getPdo();

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM `{$tableName}` {$condition}");
            foreach ($params as $key => $value) {
                $pdoType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $pdoType);
            }
            $stmt->execute();
            return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        } catch (PDOException $e) {
            error_log("CrudService::count error: " . $e->getMessage());
            return 0;
        }
    }
}
