<?php
declare(strict_types=1);

namespace OwnPay\Modules\Themes\OwnPay;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;

/**
 * Own Pay Default Theme — implements PluginInterface.
 * Registers checkout templates and assets.
 */
final class Theme implements PluginInterface
{
    public function register(EventManager $events): void
    {
        // Register checkout template paths
        $events->addFilter('checkout.template', function (string $template): string {
            return 'checkout/checkout.twig';
        });

        $events->addFilter('checkout.status.template', function (string $template): string {
            return 'checkout/checkout-status.twig';
        });

        // Register landing page features
        $events->addFilter('landing.features', function (array $features): array {
            return $features; // Default features from LandingController
        });

        // Enqueue assets
        $events->addAction('checkout.head', function (): void {
            echo '<link rel="stylesheet" href="/assets/css/checkout.css">';
        });

        $events->addAction('checkout.footer', function (): void {
            echo '<script src="/assets/js/op-fetch.js"></script>';
            echo '<script src="/assets/js/checkout.js"></script>';
        });
    }

    public function getInfo(): array
    {
        $manifest = json_decode(file_get_contents(__DIR__ . '/manifest.json'), true);
        return $manifest ?: [];
    }

    /**
     * Safe brand color — regex validated, falls back to teal.
     */
    public static function safeBrandColor(string $color = ''): string
    {
        if ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $color;
        }
        return '#0D9488';
    }
}
