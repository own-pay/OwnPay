<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Plugin\Exception\PluginInUseException;

/**
 * Repository layer for system plugins (`op_plugins` table).
 *
 * Manages plugin activations, discovery manifest representations, capabilities,
 * and statuses. Unscoped globally as plugins are system-wide.
 *
 * @package OwnPay\Repository
 */
final class PluginRepository extends BaseRepository
{
    /**
     * @var string Database table name.
     */
    protected string $table = 'op_plugins';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'slug', 'name', 'type', 'version', 'entrypoint',
        'capabilities', 'manifest', 'status',
    ];

    /**
     * Finds a plugin record by its unique slug identifier.
     *
     * @param string $slug Unique plugin slug.
     * @return array<string, mixed>|null The plugin record, or null if not found.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    /**
     * Lists all active plugins registered in the system.
     *
     * @return array<int, array<string, mixed>> List of active plugin records.
     */
    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY name ASC"
        );
    }

    /**
     * Lists plugins matching a specific type classification.
     *
     * @param string $type The plugin type (e.g. 'gateway', 'addon', 'theme').
     * @return array<int, array<string, mixed>> List of matching plugins.
     */
    public function listByType(string $type): array
    {
        return $this->where('type', $type, 'name', 'ASC');
    }

    /**
     * Activates a plugin by its slug.
     *
     * @param string $slug The plugin slug.
     * @return int Number of affected rows.
     */
    public function activate(string $slug): int
    {
        return $this->db->update(
            "UPDATE {$this->table} SET status = 'active' WHERE slug = :s",
            ['s' => $slug]
        );
    }

    /**
     * Deactivates a plugin by its slug.
     *
     * @param string $slug The plugin slug.
     * @return int Number of affected rows.
     */
    public function deactivate(string $slug): int
    {
        return $this->db->update(
            "UPDATE {$this->table} SET status = 'inactive' WHERE slug = :s",
            ['s' => $slug]
        );
    }

    /**
     * Sets the status of a plugin for a specific brand context.
     *
     * @param string $slug    Unique plugin slug.
     * @param int    $brandId The brand ID.
     * @param string $status  The target status ('active' or 'inactive').
     * @return void
     */
    public function setBrandPluginStatus(string $slug, int $brandId, string $status): void
    {
        $this->db->execute(
            "INSERT INTO op_brand_plugins (merchant_id, plugin_slug, status) 
             VALUES (:merchant_id, :slug, :status)
             ON DUPLICATE KEY UPDATE status = :status_update",
            [
                'merchant_id'   => $brandId,
                'slug'          => $slug,
                'status'        => $status,
                'status_update' => $status,
            ]
        );
    }

    /**
     * Checks if a plugin is active for a specific brand.
     * Falls back to global plugin status if no brand-specific mapping exists.
     *
     * @param string $slug    Unique plugin slug.
     * @param int    $brandId The brand ID.
     * @return bool True if active, false otherwise.
     */
    public function isPluginActiveForBrand(string $slug, int $brandId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT status FROM op_brand_plugins WHERE merchant_id = :merchant_id AND plugin_slug = :slug",
            ['merchant_id' => $brandId, 'slug' => $slug]
        );

        if ($row !== null) {
            return $row['status'] === 'active';
        }

        $globalPlugin = $this->findBySlug($slug);
        return $globalPlugin !== null && $globalPlugin['status'] === 'active';
    }

    /**
     * Gets all plugin status overrides for a brand.
     *
     * @param int $brandId The brand ID context.
     * @return array<string, string> Map of plugin slug to status string.
     */
    public function getBrandPluginStatuses(int $brandId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT plugin_slug, status FROM op_brand_plugins WHERE merchant_id = :merchant_id",
            ['merchant_id' => $brandId]
        );

        $result = [];
        foreach ($rows as $row) {
            $slugVal = $row['plugin_slug'] ?? '';
            $statusVal = $row['status'] ?? '';
            if (is_string($slugVal) && is_string($statusVal)) {
                $result[$slugVal] = $statusVal;
            }
        }

        return $result;
    }

    /**
     * Lists all plugins that are active globally OR active on at least one brand.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActiveAndBrandActive(): array
    {
        $globalActive = $this->listActive();

        $brandActiveRows = $this->db->fetchAll(
            "SELECT DISTINCT plugin_slug FROM op_brand_plugins WHERE status = 'active'"
        );

        $slugs = [];
        foreach ($brandActiveRows as $row) {
            $sVal = $row['plugin_slug'] ?? null;
            if (is_string($sVal)) {
                $slugs[] = $sVal;
            }
        }

        $allActive = $globalActive;
        $activeSlugs = array_column($globalActive, 'slug');

        foreach ($slugs as $slug) {
            if (!in_array($slug, $activeSlugs, true)) {
                $plugin = $this->findBySlug($slug);
                if ($plugin !== null) {
                    $allActive[] = $plugin;
                }
            }
        }

        return $allActive;
    }


    /**
     * Counts how many brands have this plugin set to active.
     *
     * @param string $slug Unique plugin slug.
     * @return int Count of active brand instances.
     */
    public function countActiveBrandInstances(string $slug): int
    {
        $countVal = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM op_brand_plugins WHERE plugin_slug = :slug AND status = 'active'",
            ['slug' => $slug]
        );
        return is_scalar($countVal) ? (int) $countVal : 0;
    }

    /**
     * Deletes a plugin record after checking cross-tenant usage.
     * If active on any brand, halts execution, rolls back transaction, and throws.
     *
     * @param int|string $id Primary key of the plugin.
     * @return int Number of affected rows.
     * @throws PluginInUseException
     */
    public function delete(int|string $id): int
    {
        $plugin = $this->find($id);
        if ($plugin !== null) {
            $slugVal = $plugin['slug'] ?? '';
            $slug = is_scalar($slugVal) ? (string) $slugVal : '';
            $activeCount = $this->countActiveBrandInstances($slug);
            if ($activeCount > 0) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw new PluginInUseException(
                    "Plugin '{$slug}' cannot be uninstalled because it is currently active on one or more brands."
                );
            }
        }

        return parent::delete($id);
    }
}

