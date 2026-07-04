<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Event\EventManager;
use OwnPay\View\Theme\PlainPhpThemeRenderer;
use OwnPay\View\Theme\ThemeRendererInterface;
use OwnPay\View\Theme\ThemeRendererRegistry;
use PHPUnit\Framework\TestCase;

final class ThemeEnginesRegisterFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        EventManager::resetInstance();
        parent::tearDown();
    }

    public function testFilterCanAddANewEngine(): void
    {
        $events = EventManager::getInstance();
        $fakeEngine = new PlainPhpThemeRenderer(); // stand-in ThemeRendererInterface for a "third" engine
        $events->addFilter('theme.engines.register', function (array $engines) use ($fakeEngine): array {
            $engines['test-engine'] = $fakeEngine;
            return $engines;
        });

        $baseEngines = ['twig' => new PlainPhpThemeRenderer(), 'plain-php' => new PlainPhpThemeRenderer()];
        $filtered = $events->applyFilter('theme.engines.register', $baseEngines);
        $registry = new ThemeRendererRegistry($filtered);

        $this->assertSame($fakeEngine, $registry->get('test-engine'));
        $this->assertInstanceOf(ThemeRendererInterface::class, $registry->get('twig'));
    }

    public function testNoListenersLeavesDefaultEnginesUnchanged(): void
    {
        $events = EventManager::getInstance();
        $baseEngines = ['twig' => new PlainPhpThemeRenderer(), 'plain-php' => new PlainPhpThemeRenderer()];
        $filtered = $events->applyFilter('theme.engines.register', $baseEngines);
        $this->assertSame($baseEngines, $filtered);
    }
}
