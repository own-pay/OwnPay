<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\View\Theme\PlainPhpThemeRenderer;
use OwnPay\View\Theme\ThemeRendererInterface;
use OwnPay\View\Theme\ThemeRendererRegistry;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

final class ThemeRendererRegistryTest extends TestCase
{
    public function testReturnsRegisteredRenderer(): void
    {
        $plain = new PlainPhpThemeRenderer();
        $registry = new ThemeRendererRegistry(['plain-php' => $plain]);
        $this->assertSame($plain, $registry->get('plain-php'));
    }

    public function testUnknownEngineThrows(): void
    {
        $registry = new ThemeRendererRegistry(['plain-php' => new PlainPhpThemeRenderer()]);
        $this->expectException(InvalidArgumentException::class);
        $registry->get('nope');
    }

    public function testEmptyEngineFallsBackToTwigWhenPresent(): void
    {
        $twigLike = new PlainPhpThemeRenderer(); // stand-in ThemeRendererInterface
        $registry = new ThemeRendererRegistry(['twig' => $twigLike]);
        $this->assertInstanceOf(ThemeRendererInterface::class, $registry->get(''));
    }

    public function testEmptyEngineThrowsWhenTwigNotRegistered(): void
    {
        $registry = new ThemeRendererRegistry(['plain-php' => new PlainPhpThemeRenderer()]);
        $this->expectException(InvalidArgumentException::class);
        $registry->get('');
    }
}
