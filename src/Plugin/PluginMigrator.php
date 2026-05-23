<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Core\Database;

/**
 * Database schema migration engine for OwnPay plugins.
 *
 * Scans, processes, and tracks SQL schema alterations declared by plugins.
 * Ensures transactions are utilized to maintain atomicity and isolates
 * execution state tracking within the `op_plugin_migrations` system table.
 *
 * @category Plugin
 * @package  OwnPay\Plugin
 */
final class PluginMigrator
{
    /**
     * Database connection wrapper instance.
     *
     * @var \OwnPay\Core\Database
     */
    private Database $db;

    /**
     * PluginMigrator constructor.
     *
     * @param \OwnPay\Core\Database $db Core database interface.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Executes pending SQL migrations for a specified plugin.
     *
     * Processes SQL statements inside a database transaction block, tracking
     * executed migration names and batches inside `op_plugin_migrations`.
     *
     * @param string $pluginSlug    Unique plugin identifier.
     * @param string $migrationsDir Directory path containing the migration SQL scripts.
     * @return array<int, string> List of executed migration filenames.
     */
    public function migrate(string $pluginSlug, string $migrationsDir): array
    {
        if (!is_dir($migrationsDir)) {
            return [];
        }

        $executed = $this->getExecuted($pluginSlug);
        $batch = $this->getNextBatch($pluginSlug);
        $pending = $this->getPending($migrationsDir, $executed);
        $ran = [];

        foreach ($pending as $file) {
            $sql = file_get_contents($migrationsDir . '/' . $file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            $this->db->transaction(function () use ($sql, $pluginSlug, $file, $batch) {
                $statements = array_filter(
                    array_map('trim', explode(';', $sql)),
                    fn(string $s) => $s !== ''
                );

                foreach ($statements as $stmt) {
                    $this->db->execute($stmt);
                }

                $this->db->insert(
                    "INSERT INTO op_plugin_migrations (plugin_slug, migration, batch) VALUES (:slug, :mig, :batch)",
                    ['slug' => $pluginSlug, 'mig' => $file, 'batch' => $batch]
                );
            });

            $ran[] = $file;
        }

        return $ran;
    }

    /**
     * Rolls back the last batch of executed migrations for a plugin.
     *
     * Executes the corresponding rollback scripts (*.down.sql) if present on the filesystem,
     * and deletes the tracking records from `op_plugin_migrations`.
     *
     * @param string $pluginSlug    Unique plugin identifier.
     * @param string $migrationsDir Directory path containing the migration SQL scripts.
     * @return array<int, string> List of rolled back migration filenames.
     */
    public function rollback(string $pluginSlug, string $migrationsDir): array
    {
        $lastBatch = $this->getLastBatch($pluginSlug);
        if ($lastBatch === 0) {
            return [];
        }

        $migrations = $this->db->fetchAll(
            "SELECT migration FROM op_plugin_migrations WHERE plugin_slug = :slug AND batch = :batch ORDER BY id DESC",
            ['slug' => $pluginSlug, 'batch' => $lastBatch]
        );

        $rolledBack = [];
        foreach ($migrations as $row) {
            if (!isset($row['migration']) || !is_string($row['migration'])) {
                continue;
            }
            $downFile = $migrationsDir . '/' . str_replace('.sql', '.down.sql', $row['migration']);
            if (file_exists($downFile)) {
                $sql = file_get_contents($downFile);
                if ($sql !== false && trim($sql) !== '') {
                    $statements = array_filter(array_map('trim', explode(';', $sql)), fn(string $s) => $s !== '');
                    foreach ($statements as $stmt) {
                        $this->db->execute($stmt);
                    }
                }
            }

            $this->db->delete(
                "DELETE FROM op_plugin_migrations WHERE plugin_slug = :slug AND migration = :mig",
                ['slug' => $pluginSlug, 'mig' => $row['migration']]
            );

            $rolledBack[] = $row['migration'];
        }

        return $rolledBack;
    }

    /**
     * Resolves the list of migrations that have already been executed.
     *
     * @param string $slug Unique plugin identifier.
     * @return array<int, string> List of completed migration names.
     */
    private function getExecuted(string $slug): array
    {
        $rows = $this->db->fetchAll(
            "SELECT migration FROM op_plugin_migrations WHERE plugin_slug = :slug",
            ['slug' => $slug]
        );
        $executed = [];
        foreach ($rows as $row) {
            if (isset($row['migration']) && is_string($row['migration'])) {
                $executed[] = $row['migration'];
            }
        }
        return $executed;
    }

    /**
     * Filters directory files to identify migrations that are pending execution.
     *
     * Excludes rollback (.down.sql) scripts and returns a sorted list of files.
     *
     * @param string             $dir      Directory path of the migration scripts.
     * @param array<int, string> $executed List of already executed migrations.
     * @return array<int, string> Sorted list of pending migration filenames.
     */
    private function getPending(string $dir, array $executed): array
    {
        $files = glob($dir . '/*.sql') ?: [];
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (str_ends_with($name, '.down.sql')) {
                continue;
            }
            if (!in_array($name, $executed, true)) {
                $pending[] = $name;
            }
        }
        sort($pending);
        return $pending;
    }

    /**
     * Calculates the sequence number for the next migration batch.
     *
     * @param string $slug Unique plugin identifier.
     * @return int Next batch number.
     */
    private function getNextBatch(string $slug): int
    {
        return $this->getLastBatch($slug) + 1;
    }

    /**
     * Resolves the maximum batch sequence number executed for a plugin.
     *
     * @param string $slug Unique plugin identifier.
     * @return int Last batch sequence number, default 0 if none run.
     */
    private function getLastBatch(string $slug): int
    {
        $row = $this->db->fetchOne(
            "SELECT MAX(batch) as batch FROM op_plugin_migrations WHERE plugin_slug = :slug",
            ['slug' => $slug]
        );
        $batch = is_array($row) && isset($row['batch']) ? $row['batch'] : null;
        return is_numeric($batch) ? (int) $batch : 0;
    }
}
