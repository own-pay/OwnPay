<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Repository\PluginRepository;

/**
 * Runtime registry storing activated plugin instances, manifests, and sandboxes.
 *
 * Keeps track of plugin status, coordinates capability lookups, and provides
 * safe sandbox lookup contexts (such as AUD-G8 enforcement) for active plugin execution.
 *
 * @category Plugin
 * @package  OwnPay\Plugin
 */
final class PluginRegistry
{
    /**
     * Database repository for plugin records.
     *
     * @var \OwnPay\Repository\PluginRepository
     */
    private PluginRepository $repo;

    /**
     * Cache of loaded plugin instances.
     *
     * @var array<string, \OwnPay\Plugin\PluginInterface>
     */
    private array $loaded = [];

    /**
     * Cache of loaded plugin manifest configurations.
     *
     * @var array<string, \OwnPay\Plugin\PluginManifest>
     */
    private array $manifests = [];

    /**
     * Cache of loaded plugin runtime sandboxes.
     *
     * @var array<string, \OwnPay\Plugin\PluginSandbox>
     */
    private array $sandboxes = [];

    /**
     * PluginRegistry constructor.
     *
     * @param \OwnPay\Repository\PluginRepository $repo Repository for querying plugin database state.
     */
    public function __construct(PluginRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Retrieves all active plugin records from the database.
     *
     * @return array<int, array<string, mixed>> List of active plugin records.
     */
    public function getActive(): array
    {
        return $this->repo->listActive();
    }

    /**
     * Registers a successfully instantiated plugin runtime object and its metadata.
     *
     * Optionally registers a PluginSandbox context to allow capability and resource
     * isolation checks during runtime operations.
     *
     * @param string                              $slug     Unique plugin identifier.
     * @param \OwnPay\Plugin\PluginInterface     $instance The plugin implementation instance.
     * @param \OwnPay\Plugin\PluginManifest      $manifest The validated manifest object.
     * @param \OwnPay\Plugin\PluginSandbox|null  $sandbox  Optional security containment sandbox.
     * @return void
     */
    public function registerLoaded(string $slug, PluginInterface $instance, PluginManifest $manifest, ?PluginSandbox $sandbox = null): void
    {
        $this->loaded[$slug] = $instance;
        $this->manifests[$slug] = $manifest;
        if ($sandbox !== null) {
            $this->sandboxes[$slug] = $sandbox;
        }
    }

    /**
     * Retrieves all loaded plugin instances.
     *
     * @return array<string, \OwnPay\Plugin\PluginInterface> Loaded instances mapped by their slug.
     */
    public function getLoaded(): array
    {
        return $this->loaded;
    }

    /**
     * Resolves a loaded plugin instance by its unique slug identifier.
     *
     * @param string $slug Unique plugin identifier.
     * @return \OwnPay\Plugin\PluginInterface|null The plugin instance, or null if not registered.
     */
    public function get(string $slug): ?PluginInterface
    {
        return $this->loaded[$slug] ?? null;
    }

    /**
     * Resolves a loaded plugin manifest object by its unique slug identifier.
     *
     * @param string $slug Unique plugin identifier.
     * @return \OwnPay\Plugin\PluginManifest|null The manifest object, or null if not registered.
     */
    public function getManifest(string $slug): ?PluginManifest
    {
        return $this->manifests[$slug] ?? null;
    }

    /**
     * Resolves a loaded plugin security sandbox by its unique slug identifier.
     *
     * Enables runtime capability assertions to be executed on behalf of the plugin.
     *
     * @param string $slug Unique plugin identifier.
     * @return \OwnPay\Plugin\PluginSandbox|null The sandbox context, or null if not registered.
     */
    public function getSandbox(string $slug): ?PluginSandbox
    {
        return $this->sandboxes[$slug] ?? null;
    }

    /**
     * Checks if a plugin is currently loaded in memory.
     *
     * @param string $slug Unique plugin identifier.
     * @return bool True if registered in the active plugins list.
     */
    public function isLoaded(string $slug): bool
    {
        return isset($this->loaded[$slug]);
    }

    /**
     * Filters all loaded plugins to return those possessing a specific capability.
     *
     * @param \OwnPay\Plugin\Capability $capability The capability to check against.
     * @return array<string, \OwnPay\Plugin\PluginInterface> Matching plugin instances mapped by slug.
     */
    public function withCapability(Capability $capability): array
    {
        $result = [];
        foreach ($this->loaded as $slug => $instance) {
            $caps = $instance->capabilities();
            foreach ($caps as $cap) {
                if ($cap === $capability) {
                    $result[$slug] = $instance;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Registers a runtime error for a plugin and flags its DB status as errored.
     *
     * Disables the plugin immediately and unloads it from memory cache.
     *
     * @param string $slug  Unique plugin identifier.
     * @param string $error Message detailing the execution failure.
     * @return void
     */
    public function markError(string $slug, string $error): void
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin !== null) {
            $this->repo->update((int) $plugin['id'], ['status' => 'error']);
        }
        unset($this->loaded[$slug], $this->manifests[$slug], $this->sandboxes[$slug]);
    }
}
