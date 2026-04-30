<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Core\Database;

/**
 * Plugin migrator — runs plugin-specific SQL migrations.
 *
 * Migrations stored in: modules/{type}/{slug}/migrations/
 * Tracked in: op_plugin_migrations table.
 */
final class PluginMigrator
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Run pending migrations for plugin.
     * @return string[] List of executed migration files
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
                // Execute migration SQL (may contain multiple statements)
                $statements = array_filter(
                    array_map('trim', explode(';', $sql)),
                    fn(string $s) => $s !== ''
                );

                foreach ($statements as $stmt) {
                    $this->db->execute($stmt);
                }

                // Record migration
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
     * Rollback last batch of migrations for plugin.
     * @return string[] List of rolled back migrations
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
     * @return string[]
     */
    private function getExecuted(string $slug): array
    {
        $rows = $this->db->fetchAll(
            "SELECT migration FROM op_plugin_migrations WHERE plugin_slug = :slug",
            ['slug' => $slug]
        );
        return array_column($rows, 'migration');
    }

    /**
     * @return string[]
     */
    private function getPending(string $dir, array $executed): array
    {
        $files = glob($dir . '/*.sql') ?: [];
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            // Skip .down.sql files
            if (str_ends_with($name, '.down.sql')) {
                continue;
            }
            if (!in_array($name, $executed, true)) {
                $pending[] = $name;
            }
        }
        sort($pending); // Alphabetical = chronological with YYYY_MM_DD prefix
        return $pending;
    }

    private function getNextBatch(string $slug): int
    {
        return $this->getLastBatch($slug) + 1;
    }

    private function getLastBatch(string $slug): int
    {
        $row = $this->db->fetchOne(
            "SELECT MAX(batch) as batch FROM op_plugin_migrations WHERE plugin_slug = :slug",
            ['slug' => $slug]
        );
        return (int) ($row['batch'] ?? 0);
    }
}
