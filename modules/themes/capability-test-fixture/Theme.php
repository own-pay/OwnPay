<?php

declare(strict_types=1);

namespace OwnPay\Modules\Themes\CapabilityTestFixture;

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;

final class Theme implements PluginInterface
{
    public static function metadata(): array
    {
        return [
            'name'        => 'Capability Test Fixture',
            'slug'        => 'capability-test-fixture',
            'version'     => '1.0.0',
            'description' => 'Test-only theme with no THEME capability declared.',
            'author'      => 'OwnPay',
            'type'        => 'theme',
        ];
    }

    public function capabilities(): array
    {
        return []; // Deliberately declares no capabilities, including no Capability::THEME.
    }

    public function register(EventManager $events, Container $container): void
    {
    }

    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function fields(): array
    {
        return [];
    }
}
