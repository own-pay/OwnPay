<?php

declare(strict_types=1);

namespace OwnPay\Modules\Themes\ReferenceMinimal;

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;

final class Theme implements PluginInterface
{
    public static function metadata(): array
    {
        return [
            'name'        => 'Reference Minimal',
            'slug'        => 'reference-minimal',
            'version'     => '1.0.0',
            'description' => 'Polished, minimal reference theme rendered by the plain-PHP engine.',
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
        // Plain-PHP themes resolve their own templates via ActiveTheme::resolveTemplate()
        // and render a complete standalone HTML document per page - no checkout.template
        // filters or checkout.head/checkout.footer hook-echo actions are needed, matching
        // the existing plain-php-demo theme's convention.
    }

    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function fields(): array
    {
        // No plugin-settings customizer: logo/accent_color/show_powered_by/
        // footer_text/support_email/custom_css already have a working,
        // brand-scoped home via $brand (BrandThemeService). Building a second,
        // disconnected settings surface for the same concepts would silently
        // do nothing when edited - see the plan's Global Constraints.
        return [];
    }
}
