<?php
declare(strict_types=1);

namespace OwnPay\Modules\Themes\OwnPay;

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;

/**
 * OwnPay Default Theme - implements PluginInterface.
 * Registers checkout templates and assets.
 */
final class Theme implements PluginInterface
{
    public static function metadata(): array
    {
        return [
            'name'        => 'OwnPay Theme',
            'slug'        => 'own-pay',
            'version'     => '1.0.0',
            'description' => 'Default OwnPay checkout and landing page theme.',
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
        // Register checkout template paths
        $events->addFilter('checkout.template', function (string $template): string {
            return 'checkout/checkout.twig';
        });

        $events->addFilter('checkout.status.template', function (string $template): string {
            return 'checkout/checkout-status.twig';
        });

        $events->addFilter('checkout.payment_link.template', function (string $template): string {
            return 'checkout/payment-link-amount.twig';
        });

        // Register landing page features
        $events->addFilter('landing.features', function (array $features): array {
            return $features;
        });

        // Enqueue assets
        $events->addAction('checkout.head', function (): void {
            echo '<link rel="stylesheet" href="/assets/css/checkout.css">';
        });

        $events->addAction('checkout.footer', function () use ($container): void {
            $nonceVal = $container->has('csp_nonce') ? $container->get('csp_nonce') : '';
            $nonceAttr = is_string($nonceVal) && $nonceVal !== '' ? ' nonce="' . htmlspecialchars($nonceVal, ENT_QUOTES, 'UTF-8') . '"' : '';
            echo '<script' . $nonceAttr . ' src="/assets/js/op-fetch.js"></script>';
            echo '<script' . $nonceAttr . ' src="/assets/js/checkout.js"></script>';
        });
    }

    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function fields(): array
    {
        return [
            [
                'name'    => 'primary_color',
                'label'   => 'Primary Color',
                'type'    => 'color',
                'default' => '#0D9488',
                'help'    => 'Main brand color for checkout pages.',
            ],
            [
                'name'    => 'accent_color',
                'label'   => 'Accent Color',
                'type'    => 'color',
                'default' => '#6C5CE7',
                'help'    => 'Accent color for buttons and highlights.',
            ],
            [
                'name'    => 'checkout_logo',
                'label'   => 'Checkout Logo URL',
                'type'    => 'text',
                'default' => '',
                'help'    => 'URL to logo displayed on checkout pages.',
            ],
            [
                'name'    => 'show_powered_by',
                'label'   => 'Show "Powered by OwnPay"',
                'type'    => 'toggle',
                'default' => '1',
            ],
        ];
    }

    /**
     * Safe brand color - regex validated, falls back to teal.
     */
    public static function safeBrandColor(string $color = ''): string
    {
        if ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $color;
        }
        return '#0D9488';
    }
}
