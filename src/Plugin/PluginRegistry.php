<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Repository\PluginRepository;

/**
 * Plugin registry — runtime registry of loaded plugin instances + DB state.
 */
final class PluginRegistry
{
    private PluginRepository $repo;

    /** @var array<string, PluginInterface> Loaded instances */
    private array $loaded = [];

    /** @var array<string, PluginManifest> Loaded manifests */
    private array $manifests = [];

    public function __construct(PluginRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Get all active plugins from DB.
     * @return array<int, array<string, mixed>>
     */
    public function getActive(): array
    {
        return $this->repo->listActive();
    }

    /** @var array<string, PluginSandbox> Loaded sandboxes */
    private array $sandboxes = [];

    /**
     * Register a loaded plugin instance.
     * AUD-G8 fix: Also accepts optional sandbox for runtime capability enforcement.
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
     * Get all loaded plugin instances.
     * @return array<string, PluginInterface>
     */
    public function getLoaded(): array
    {
        return $this->loaded;
    }

    /**
     * Get loaded instance by slug.
     */
    public function get(string $slug): ?PluginInterface
    {
        return $this->loaded[$slug] ?? null;
    }

    /**
     * Get manifest for loaded plugin.
     */
    public function getManifest(string $slug): ?PluginManifest
    {
        return $this->manifests[$slug] ?? null;
    }

    /**
     * Get sandbox for loaded plugin.
     * AUD-G8 fix: Allows runtime capability checks on plugin operations.
     */
    public function getSandbox(string $slug): ?PluginSandbox
    {
        return $this->sandboxes[$slug] ?? null;
    }

    /**
     * Check if plugin is loaded.
     */
    public function isLoaded(string $slug): bool
    {
        return isset($this->loaded[$slug]);
    }

    /**
     * Get all plugins with capability.
     * @return PluginInterface[]
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
     * Mark plugin as errored in DB.
     */
    public function markError(string $slug, string $error): void
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin !== null) {
            $this->repo->update((int) $plugin['id'], ['status' => 'error']);
        }
        // Remove from loaded
        unset($this->loaded[$slug], $this->manifests[$slug], $this->sandboxes[$slug]);
    }
}
