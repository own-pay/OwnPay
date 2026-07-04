<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\View\Theme\ActiveTheme;
use PHPUnit\Framework\TestCase;

final class ActiveThemeTest extends TestCase
{
    public function testTwigResolveTemplateReturnsTwigLoaderName(): void
    {
        $theme = new ActiveTheme('own-pay', 'twig', '/app/modules/themes/own-pay', false);
        $this->assertSame('checkout/checkout.twig', $theme->resolveTemplate('checkout/checkout.twig'));
        // extensionless logical name also normalizes to .twig
        $this->assertSame('checkout/checkout.twig', $theme->resolveTemplate('checkout/checkout'));
    }

    public function testPlainPhpResolveTemplateReturnsAbsolutePhpPath(): void
    {
        $theme = new ActiveTheme('demo', 'php', '/app/modules/themes/demo', false);
        $this->assertSame(
            '/app/modules/themes/demo/templates/checkout/checkout.php',
            $theme->resolveTemplate('checkout/checkout.twig')
        );
    }

    public function testFellBackFlagIsExposed(): void
    {
        $theme = new ActiveTheme('own-pay', 'twig', '/app/modules/themes/own-pay', true);
        $this->assertTrue($theme->fellBack);
        $this->assertSame('own-pay', $theme->slug);
        $this->assertSame('twig', $theme->engine);
    }

    public function testResolveTemplateRejectsPathTraversal(): void
    {
        $theme = new ActiveTheme('own-pay', 'twig', '/app/modules/themes/own-pay', false);
        $this->expectException(\InvalidArgumentException::class);
        $theme->resolveTemplate('../../etc/passwd');
    }

    public function testResolveTemplateRejectsAbsolutePath(): void
    {
        $theme = new ActiveTheme('own-pay', 'php', '/app/modules/themes/own-pay', false);
        $this->expectException(\InvalidArgumentException::class);
        $theme->resolveTemplate('/etc/passwd');
    }
}
