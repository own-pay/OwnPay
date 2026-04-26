<?php

declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Event\EventManager;

/**
 * The universal contract that EVERY OwnPay extension must implement.
 *
 * Whether the extension is a payment gateway, a checkout theme, or a
 * feature plugin — it implements this single interface.  The plugin's
 * *type* (gateway / theme / plugin) is declared in manifest.json and
 * determines which system hooks the loader fires for it.
 *
 * Lifecycle order:
 *   install  → activate() → [register() + boot() on every request] → deactivate() → uninstall()
 *
 * @example
 *   class StripeGateway implements PluginInterface { ... }
 *   class TwentySixTheme implements PluginInterface { ... }
 *   class SmsNotifier implements PluginInterface { ... }
 */
interface PluginInterface
{
    // ── Request-time lifecycle (called on every HTTP request) ────────

    /**
     * Register hooks, filters, routes, admin menus, and cron jobs.
     *
     * Called once per request, BEFORE boot().  This is the place to wire
     * the plugin into the system via the EventManager.  Do NOT perform
     * heavy I/O here — defer that to the hook callback itself.
     *
     * @param EventManager $events  The singleton event bus
     */
    public function register(EventManager $events): void;

    /**
     * Post-registration bootstrap.
     *
     * Called once per request, AFTER every active plugin has called
     * register().  Safe to depend on hooks registered by other plugins.
     */
    public function boot(): void;

    // ── Admin-triggered lifecycle (called once each) ────────────────

    /**
     * Run first-time setup: seed data, create settings, etc.
     *
     * Called once when the admin activates the plugin.  Database
     * migrations declared in manifest.json are applied automatically
     * BEFORE this method is invoked.
     */
    public function activate(): void;

    /**
     * Suspend the plugin gracefully.
     *
     * Called when the admin deactivates the plugin.  Must leave all data
     * intact — the user may re-activate later.
     */
    public function deactivate(): void;

    /**
     * Permanent teardown: drop tables, delete settings, clean up files.
     *
     * Called once immediately before the plugin directory is deleted.
     * This is the ONLY lifecycle method that should be destructive.
     */
    public function uninstall(): void;

    // ── Metadata ────────────────────────────────────────────────────

    /**
     * Return human-readable metadata for the admin panel.
     *
     * Expected keys:
     *   'title'       => string   Display name
     *   'description' => string   One-liner
     *   'version'     => string   SemVer (must match manifest)
     *   'logo'        => ?string  Relative path to logo asset
     *
     * @return array<string, mixed>
     */
    public function info(): array;

    /**
     * Declare configurable settings for the admin settings page.
     *
     * Each entry follows the standard field schema:
     *   ['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', ...]
     *
     * Supported types: text, textarea, select, checkbox, color, image, radio, password
     *
     * @return list<array{name: string, label: string, type: string, ...}>
     */
    public function fields(): array;
}
