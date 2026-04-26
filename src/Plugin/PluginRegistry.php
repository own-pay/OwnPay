<?php

declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Core\Database;

/**
 * In-memory registry of all loaded plugin instances and their manifests.
 *
 * Also manages the persistent cache of active plugins, avoiding a DB
 * query on every request.  The cache is a flat JSON file at:
 *   storage/plugins/cache/plugin_registry.json
 *
 * Rebuilt automatically when plugins are activated/deactivated, or when
 * the cache file is missing.
 */
final class PluginRegistry
{
    private const CACHE_DIR  = 'storage/plugins/cache';
    private const CACHE_FILE = 'storage/plugins/cache/plugin_registry.json';

    /** @var array<string, PluginInterface> slug => live instance */
    private array $instances = [];

    /** @var array<string, PluginManifest> slug => parsed manifest */
    private array $manifests = [];

    // ── Instance management ─────────────────────────────────────────

    /**
     * Register a loaded plugin instance and its manifest.
     */
    public function add(string $slug, PluginInterface $plugin, PluginManifest $manifest): void
    {
        $this->instances[$slug] = $plugin;
        $this->manifests[$slug] = $manifest;
    }

    public function get(string $slug): ?PluginInterface
    {
        return $this->instances[$slug] ?? null;
    }

    public function getManifest(string $slug): ?PluginManifest
    {
        return $this->manifests[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return isset($this->instances[$slug]);
    }

    /**
     * @return array<string, PluginInterface>
     */
    public function all(): array
    {
        return $this->instances;
    }

    /**
     * @return array<string, PluginManifest>
     */
    public function allManifests(): array
    {
        return $this->manifests;
    }

    public function count(): int
    {
        return count($this->instances);
    }

    // ── Persistent active-list (cache + DB fallback) ────────────────

    /**
     * Load the list of active plugins — from cache file or database.
     *
     * Each entry is an associative array:
     *   ['slug', 'type', 'entrypoint', 'load_order']
     *
     * @return list<array{slug: string, type: string, entrypoint: string, load_order: int}>
     */
    public function loadActiveList(): array
    {
        $root = $this->getProjectRoot();
        $cachePath = $root . '/' . self::CACHE_FILE;

        // Try cache first
        if (is_file($cachePath)) {
            $data = json_decode((string) file_get_contents($cachePath), true);
            if (is_array($data) && $data !== []) {
                return $data;
            }
        }

        // Cache miss — query database and rebuild
        return $this->rebuildCache();
    }

    /**
     * Query the database for active plugins and write the cache file.
     *
     * @return list<array{slug: string, type: string, entrypoint: string, load_order: int}>
     */
    public function rebuildCache(): array
    {
        $root = $this->getProjectRoot();
        $prefix = $_ENV['DB_PREFIX'] ?? 'op_';

        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT slug, type, entrypoint, load_order
                   FROM `{$prefix}plugins`
                  WHERE status = 'active'
               ORDER BY load_order ASC, slug ASC"
            );
        } catch (\Throwable $e) {
            // Table may not exist yet (fresh install before migration)
            error_log('[OwnPay][PluginRegistry] Cannot query op_plugins: ' . $e->getMessage());
            $rows = [];
        }

        $list = array_map(fn(array $row): array => [
            'slug'       => (string) $row['slug'],
            'type'       => (string) $row['type'],
            'entrypoint' => (string) $row['entrypoint'],
            'load_order' => (int) $row['load_order'],
        ], $rows);

        $this->writeCache($list);
        return $list;
    }

    /**
     * Delete the cache file so it is rebuilt on next request.
     */
    public function clearCache(): void
    {
        $root = $this->getProjectRoot();
        $cachePath = $root . '/' . self::CACHE_FILE;

        if (is_file($cachePath)) {
            unlink($cachePath);
        }
    }

    // ── Internals ───────────────────────────────────────────────────

    /**
     * Write the active-plugin list to the JSON cache file.
     *
     * @param list<array{slug: string, type: string, entrypoint: string, load_order: int}> $list
     */
    private function writeCache(array $list): void
    {
        $root = $this->getProjectRoot();
        $cacheDir = $root . '/' . self::CACHE_DIR;
        $cachePath = $root . '/' . self::CACHE_FILE;

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents(
            $cachePath,
            json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }

    private function getProjectRoot(): string
    {
        // __DIR__ = src/Plugin/, so root is two levels up
        return dirname(__DIR__, 2);
    }
}
