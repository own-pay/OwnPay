<?php

declare(strict_types=1);

namespace OwnPayPlugin\HelloWorld;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;

/**
 * Minimal reference plugin for testing the plugin loader.
 */
class Plugin implements PluginInterface
{
    public function register(EventManager $events): void
    {
        $events->addAction('system.boot', [$this, 'onSystemBoot'], owner: 'hello-world');
    }

    public function boot(): void
    {
        // Nothing needed for this minimal plugin.
    }

    public function activate(): void
    {
        // No setup required.
    }

    public function deactivate(): void
    {
        // No cleanup required.
    }

    public function uninstall(): void
    {
        // No teardown required.
    }

    public function info(): array
    {
        return [
            'title'       => 'Hello World',
            'description' => 'Minimal reference plugin for testing',
            'version'     => '1.0.0',
        ];
    }

    public function fields(): array
    {
        return [];
    }

    public function onSystemBoot(): void
    {
        error_log('[HelloWorld] Plugin system.boot hook fired successfully.');
    }
}
