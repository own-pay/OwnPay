<?php

declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Core\Database;

/**
 * Runs database migrations declared by plugins.
 *
 * Each plugin can declare SQL migration files in its manifest.json:
 *   "migrations": ["migrations/001_create_table.sql", "migrations/002_add_column.sql"]
 *
 * Migrations are tracked in the `op_plugin_migrations` table so they only
 * run once. They execute transactionally — if one fails, it rolls back
 * and stops (no partial state).
 *
 * Rollback is supported if the plugin provides `_down.sql` companion files
 * (e.g. `001_create_table_down.sql`).
 */
final class PluginMigrator
{
    /** Maps plugin type → base directory */
    private const TYPE_DIRS = [
        'plugin'  => 'app/modules/plugins',
        'gateway' => 'app/modules/gateways',
        'theme'   => 'app/modules/themes',
    ];

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Run all pending migrations for a plugin.
     *
     * @param string $slug The plugin slug
     * @return array{success: bool, applied: int, message: string}
     */
    public static function migrate(string $slug): array
    {
        $root = dirname(__DIR__, 2);
        $prefix = $_ENV['DB_PREFIX'] ?? 'op_';

        // Find the plugin's manifest
        $manifest = self::findManifest($root, $slug);
        if ($manifest === null) {
            return ['success' => false, 'applied' => 0, 'message' => "Plugin '{$slug}' not found."];
        }

        // No migrations declared
        if ($manifest->migrations === []) {
            return ['success' => true, 'applied' => 0, 'message' => 'No migrations to run.'];
        }

        $db = Database::getInstance();

        // Ensure the migrations table exists
        self::ensureMigrationsTable($db, $prefix);

        // Get already-applied migrations
        $applied = self::getAppliedMigrations($db, $prefix, $slug);

        // Determine pending
        $pending = array_filter(
            $manifest->migrations,
            fn(string $m): bool => !in_array($m, $applied, true),
        );

        if ($pending === []) {
            return ['success' => true, 'applied' => 0, 'message' => 'All migrations already applied.'];
        }

        // Determine the next batch number
        $batch = self::getNextBatch($db, $prefix, $slug);

        // Resolve plugin base directory
        $pluginDir = self::resolvePluginDir($root, $manifest);
        if ($pluginDir === null) {
            return ['success' => false, 'applied' => 0, 'message' => "Plugin directory not found for '{$slug}'."];
        }

        // Execute each pending migration transactionally
        $count = 0;
        foreach ($pending as $migrationPath) {
            $result = self::executeMigration($db, $prefix, $slug, $batch, $pluginDir, $migrationPath);
            if (!$result['success']) {
                return [
                    'success' => false,
                    'applied' => $count,
                    'message' => "Migration failed at '{$migrationPath}': {$result['message']}",
                ];
            }
            $count++;
        }

        return [
            'success' => true,
            'applied' => $count,
            'message' => "{$count} migration(s) applied successfully.",
        ];
    }

    /**
     * Rollback the last batch of migrations for a plugin.
     *
     * @param string $slug The plugin slug
     * @return array{success: bool, rolled_back: int, message: string}
     */
    public static function rollback(string $slug): array
    {
        $root = dirname(__DIR__, 2);
        $prefix = $_ENV['DB_PREFIX'] ?? 'op_';
        $db = Database::getInstance();

        // Find manifest for plugin dir resolution
        $manifest = self::findManifest($root, $slug);
        $pluginDir = $manifest !== null ? self::resolvePluginDir($root, $manifest) : null;

        // Get the last batch number
        $lastBatch = $db->fetchColumn(
            "SELECT MAX(batch) FROM `{$prefix}plugin_migrations` WHERE plugin_slug = :slug",
            ['slug' => $slug],
        );

        if ($lastBatch === null || $lastBatch === false) {
            return ['success' => true, 'rolled_back' => 0, 'message' => 'Nothing to roll back.'];
        }

        // Get migrations from last batch (reverse order)
        $migrations = $db->fetchAll(
            "SELECT migration FROM `{$prefix}plugin_migrations`
             WHERE plugin_slug = :slug AND batch = :batch
             ORDER BY id DESC",
            ['slug' => $slug, 'batch' => (int) $lastBatch],
        );

        $count = 0;
        foreach ($migrations as $row) {
            $migrationPath = $row['migration'];

            // Try to find a _down.sql companion file
            if ($pluginDir !== null) {
                $downResult = self::executeDownMigration($db, $pluginDir, $migrationPath);
                if (!$downResult['success']) {
                    error_log("[OwnPay][PluginMigrator] Down migration failed for '{$migrationPath}': {$downResult['message']}");
                    // Continue — we still remove the record
                }
            }

            // Remove the migration record
            $db->execute(
                "DELETE FROM `{$prefix}plugin_migrations` WHERE plugin_slug = :slug AND migration = :migration",
                ['slug' => $slug, 'migration' => $migrationPath],
            );

            $count++;
        }

        return [
            'success'     => true,
            'rolled_back' => $count,
            'message'     => "{$count} migration(s) rolled back.",
        ];
    }

    /**
     * Rollback ALL migrations for a plugin (used during uninstall).
     *
     * @param string $slug The plugin slug
     * @return array{success: bool, rolled_back: int, message: string}
     */
    public static function rollbackAll(string $slug): array
    {
        $prefix = $_ENV['DB_PREFIX'] ?? 'op_';
        $db = Database::getInstance();

        $totalRolledBack = 0;

        // Keep rolling back batch by batch until nothing left
        $maxIterations = 100; // Safety net
        for ($i = 0; $i < $maxIterations; $i++) {
            $result = self::rollback($slug);
            $totalRolledBack += $result['rolled_back'];

            if ($result['rolled_back'] === 0) {
                break;
            }
        }

        return [
            'success'     => true,
            'rolled_back' => $totalRolledBack,
            'message'     => "{$totalRolledBack} total migration(s) rolled back.",
        ];
    }

    /**
     * Get the migration status for a plugin.
     *
     * @param string $slug The plugin slug
     * @return array{applied: list<array{migration: string, batch: int, applied_at: string}>, pending: list<string>}
     */
    public static function status(string $slug): array
    {
        $root = dirname(__DIR__, 2);
        $prefix = $_ENV['DB_PREFIX'] ?? 'op_';
        $db = Database::getInstance();

        // Applied
        $applied = $db->fetchAll(
            "SELECT migration, batch, applied_at FROM `{$prefix}plugin_migrations`
             WHERE plugin_slug = :slug ORDER BY id ASC",
            ['slug' => $slug],
        );

        $appliedNames = array_column($applied, 'migration');

        // Pending (from manifest)
        $manifest = self::findManifest($root, $slug);
        $allMigrations = $manifest !== null ? $manifest->migrations : [];
        $pending = array_values(array_filter(
            $allMigrations,
            fn(string $m): bool => !in_array($m, $appliedNames, true),
        ));

        return [
            'applied' => $applied,
            'pending' => $pending,
        ];
    }

    // ── Internal helpers ───────────────────────────────────────────

    /**
     * Execute a single migration file within a transaction.
     *
     * @return array{success: bool, message: string}
     */
    private static function executeMigration(
        Database $db,
        string $prefix,
        string $slug,
        int $batch,
        string $pluginDir,
        string $migrationPath,
    ): array {
        // Validate path (no traversal)
        if (str_contains($migrationPath, '..') || str_starts_with($migrationPath, '/')) {
            return ['success' => false, 'message' => 'Path traversal detected.'];
        }

        $fullPath = $pluginDir . '/' . $migrationPath;

        // Containment check
        $realPath = realpath($fullPath);
        $realPluginDir = realpath($pluginDir);
        if ($realPath === false || $realPluginDir === false) {
            return ['success' => false, 'message' => "Migration file not found: {$migrationPath}"];
        }
        if (!str_starts_with($realPath, $realPluginDir . DIRECTORY_SEPARATOR)) {
            return ['success' => false, 'message' => 'Migration file outside plugin directory.'];
        }

        // Read SQL
        $sql = file_get_contents($realPath);
        if ($sql === false || trim($sql) === '') {
            return ['success' => false, 'message' => "Cannot read or empty migration file: {$migrationPath}"];
        }

        // Replace table prefix placeholder
        $sql = str_replace('{prefix}', $prefix, $sql);

        // Execute within transaction
        $db->beginTransaction();
        try {
            // Split by semicolons for multi-statement files
            $statements = self::splitStatements($sql);
            foreach ($statements as $stmt) {
                if (trim($stmt) === '') {
                    continue;
                }
                $db->execute($stmt);
            }

            // Record the migration
            $db->execute(
                "INSERT INTO `{$prefix}plugin_migrations` (plugin_slug, migration, batch) VALUES (:slug, :migration, :batch)",
                ['slug' => $slug, 'migration' => $migrationPath, 'batch' => $batch],
            );

            $db->commit();
            return ['success' => true, 'message' => 'OK'];
        } catch (\Throwable $e) {
            $db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Execute a _down.sql companion migration (for rollback).
     *
     * @return array{success: bool, message: string}
     */
    private static function executeDownMigration(Database $db, string $pluginDir, string $migrationPath): array
    {
        // Build down-migration filename: 001_create_table.sql → 001_create_table_down.sql
        $pathInfo = pathinfo($migrationPath);
        $downPath = ($pathInfo['dirname'] !== '.' ? $pathInfo['dirname'] . '/' : '')
            . $pathInfo['filename'] . '_down.' . ($pathInfo['extension'] ?? 'sql');

        $fullPath = $pluginDir . '/' . $downPath;
        if (!is_file($fullPath)) {
            return ['success' => true, 'message' => 'No down migration found (skipped).'];
        }

        $sql = file_get_contents($fullPath);
        if ($sql === false || trim($sql) === '') {
            return ['success' => true, 'message' => 'Down migration is empty (skipped).'];
        }

        $prefix = $_ENV['DB_PREFIX'] ?? 'op_';
        $sql = str_replace('{prefix}', $prefix, $sql);

        try {
            $statements = self::splitStatements($sql);
            foreach ($statements as $stmt) {
                if (trim($stmt) === '') {
                    continue;
                }
                $db->execute($stmt);
            }
            return ['success' => true, 'message' => 'Down migration executed.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Ensure the op_plugin_migrations table exists.
     */
    private static function ensureMigrationsTable(Database $db, string $prefix): void
    {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `{$prefix}plugin_migrations` (
                `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `plugin_slug` VARCHAR(60) NOT NULL,
                `migration`   VARCHAR(255) NOT NULL,
                `batch`       INT NOT NULL,
                `applied_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_plugin_migration` (`plugin_slug`, `migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /**
     * Get list of already-applied migration names for a plugin.
     *
     * @return list<string>
     */
    private static function getAppliedMigrations(Database $db, string $prefix, string $slug): array
    {
        $rows = $db->fetchAll(
            "SELECT migration FROM `{$prefix}plugin_migrations` WHERE plugin_slug = :slug ORDER BY id ASC",
            ['slug' => $slug],
        );

        return array_column($rows, 'migration');
    }

    /**
     * Get the next batch number for a plugin.
     */
    private static function getNextBatch(Database $db, string $prefix, string $slug): int
    {
        $max = $db->fetchColumn(
            "SELECT MAX(batch) FROM `{$prefix}plugin_migrations` WHERE plugin_slug = :slug",
            ['slug' => $slug],
        );

        return ($max !== null && $max !== false) ? ((int) $max + 1) : 1;
    }

    /**
     * Split a SQL string into individual statements by semicolons.
     *
     * Handles quoted strings and comments properly.
     *
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inLineComment = false;
        $inBlockComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            // Handle line comments
            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }
                $current .= $char;
                continue;
            }

            // Handle block comments
            if ($inBlockComment) {
                $current .= $char;
                if ($char === '*' && $next === '/') {
                    $current .= $next;
                    $i++;
                    $inBlockComment = false;
                }
                continue;
            }

            // Handle quoted strings
            if ($inSingleQuote) {
                $current .= $char;
                if ($char === '\'' && ($sql[$i - 1] ?? '') !== '\\') {
                    $inSingleQuote = false;
                }
                continue;
            }

            if ($inDoubleQuote) {
                $current .= $char;
                if ($char === '"' && ($sql[$i - 1] ?? '') !== '\\') {
                    $inDoubleQuote = false;
                }
                continue;
            }

            // Detect start of comments/quotes
            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $current .= $char;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $current .= $char;
                continue;
            }

            if ($char === '\'') {
                $inSingleQuote = true;
                $current .= $char;
                continue;
            }

            if ($char === '"') {
                $inDoubleQuote = true;
                $current .= $char;
                continue;
            }

            // Statement separator
            if ($char === ';') {
                $stmt = trim($current);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        // Add the last statement if it doesn't end with ;
        $stmt = trim($current);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }

    /**
     * Find a plugin's manifest by scanning all type directories.
     */
    private static function findManifest(string $root, string $slug): ?PluginManifest
    {
        foreach (self::TYPE_DIRS as $type => $dir) {
            $path = $root . '/' . $dir . '/' . $slug . '/manifest.json';
            if (is_file($path)) {
                try {
                    return PluginManifest::fromFile($path);
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        return null;
    }

    /**
     * Resolve the plugin's base directory from its manifest.
     */
    private static function resolvePluginDir(string $root, PluginManifest $manifest): ?string
    {
        $dir = self::TYPE_DIRS[$manifest->type] ?? null;
        if ($dir === null) {
            return null;
        }

        $path = $root . '/' . $dir . '/' . $manifest->slug;
        return is_dir($path) ? $path : null;
    }
}
