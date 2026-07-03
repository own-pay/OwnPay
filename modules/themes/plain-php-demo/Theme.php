<?php

declare(strict_types=1);

namespace OwnPay\Modules\Themes\PlainPhpDemo;

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;

final class Theme implements PluginInterface
{
    public static function metadata(): array
    {
        return [
            'name'        => 'Plain PHP Demo',
            'slug'        => 'plain-php-demo',
            'version'     => '1.0.0',
            'description' => 'Proof-of-concept plain-PHP checkout theme.',
            'author'      => 'OwnPay',
            'type'        => 'theme',
        ];
    }

    public function capabilities(): array
    {
        return [Capability::THEME];
    }

    public function register(EventManager $events, Container $container): void
    {
        // No template-name filters: the plain-PHP renderer resolves templates
        // from this theme's own directory via ActiveTheme::resolveTemplate().
    }

    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function fields(): array
    {
        return [];
    }
}
