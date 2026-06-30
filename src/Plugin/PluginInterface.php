<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Defines the contract for all plugins integrated into the OwnPay payment engine.
 *
 * Every plugin (gateways, themes, or addons) must implement this interface to
 * integrate with the core lifecycle events, dependency injection container, and
 * event manager, ensuring safe execution under the single-owner multi-brand model.
 *
 * Lifecycle sequence:
 * 1. register() - Declares event hook and filter bindings.
 * 2. boot() - Starts services after all dependencies are loaded.
 * 3. deactivate() - Gracefully suspends execution.
 * 4. uninstall() - Permanently purges assets, tables, and settings.
 *
 * @category Plugin
 * @package  OwnPay\Plugin
 */
interface PluginInterface
{
    /**
     * Retrieves static metadata describing the plugin.
     *
     * @return array{name: string, slug: string, version: string, description: string, author: string, type: string} The plugin metadata.
     */
    public static function metadata(): array;

    /**
     * Declares the system capabilities exposed by the plugin.
     *
     * @return array<int, \OwnPay\Plugin\Capability> List of capabilities provided.
     */
    public function capabilities(): array;

    /**
     * Registers hooks, filters, and action listeners.
     *
     * Invoked during the boot phase of every HTTP or CLI request for all active plugins,
     * allowing routing and middleware pipeline alterations.
     *
     * @param \OwnPay\Event\EventManager $events The central event management engine.
     * @param \OwnPay\Container          $container The dependency injection container.
     * @return void
     */
    public function register(EventManager $events, Container $container): void;

    /**
     * Boots the plugin after all plugins have registered their bindings.
     *
     * Enables inter-plugin communication and dependency resolution via the DI container.
     *
     * @param \OwnPay\Container $container The dependency injection container.
     * @return void
     */
    public function boot(Container $container): void;

    /**
     * Handles graceful teardown operations when the plugin is deactivated.
     *
     * Safe for temp cleanups; should not destructively delete permanent merchant records.
     *
     * @param \OwnPay\Container $container The dependency injection container.
     * @return void
     */
    public function deactivate(Container $container): void;

    /**
     * Handles destructive cleanup operations when the plugin is permanently uninstalled.
     *
     * Purges database tables, schema overrides, and config settings to prevent stale records.
     *
     * @param \OwnPay\Container $container The dependency injection container.
     * @return void
     */
    public function uninstall(Container $container): void;

    /**
     * Defines configuration fields for administration UI automatic rendering.
     *
     * Allows plugins to expose key-value settings (e.g. API credentials for Stripe or bKash)
     * which are dynamically rendered in the admin dashboard and saved to the settings repository.
     *
     * @return array<int, array{name: string, label: string, type: string, default?: mixed, options?: array<string, string>}> List of field definitions.
     */
    public function fields(): array;
}
