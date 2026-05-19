<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Plugin interface — every plugin MUST implement this.
 *
 * Lifecycle: register() ─ boot() ─ deactivate() ─ uninstall()
 */
interface PluginInterface
{
    /**
     * Plugin metadata.
     * @return array{name: string, slug: string, version: string, description: string, author: string, type: string}
     */
    public static function metadata(): array;

    /**
     * Declare capabilities this plugin provides.
     * @return Capability[]
     */
    public function capabilities(): array;

    /**
     * Register hooks, filters, and event listeners.
     * Called on every request when plugin is active.
     */
    public function register(EventManager $events, Container $container): void;

    /**
     * Boot the plugin after all plugins registered.
     * Access to full container and other plugins.
     */
    public function boot(Container $container): void;

    /**
     * Called when plugin is deactivated.
     */
    public function deactivate(Container $container): void;

    /**
     * Called when plugin is uninstalled (permanent removal).
     * Clean up DB tables, files, etc.
     */
    public function uninstall(Container $container): void;

    /**
     * Define settings fields for admin UI auto-rendering.
     * @return array<int, array{name: string, label: string, type: string, default?: mixed, options?: array}>
     */
    public function fields(): array;
}
