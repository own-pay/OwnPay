<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\View\Theme\ActiveTheme;
use OwnPay\View\Theme\PlainPhpThemeRenderer;
use OwnPay\View\Theme\ThemeRendererRegistry;
use PHPUnit\Framework\TestCase;

final class ThemeRenderPipelineTest extends TestCase
{
    public function testPlainPhpDemoThemeRendersCheckoutEndToEnd(): void
    {
        $themesDir = dirname(__DIR__, 2) . '/modules/themes';
        $theme = new ActiveTheme('plain-php-demo', 'php', $themesDir . '/plain-php-demo', false);
        $registry = new ThemeRendererRegistry(['php' => new PlainPhpThemeRenderer()]);

        $html = $registry->get($theme->engine)->render(
            $theme->resolveTemplate('checkout/checkout.twig'),
            ['brand' => ['name' => 'Acme'], 'txn' => ['amount' => '10.00', 'currency' => 'USD'], 'gateways' => [['name' => 'bKash']]]
        );

        $this->assertStringContainsString('Acme', $html);
        $this->assertStringContainsString('bKash', $html);
        $this->assertStringContainsString('php engine', $html);
    }
}
