<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Event\EventManager;
use OwnPay\View\Theme\PlainPhpThemeRenderer;
use OwnPay\View\Theme\ThemeRendererInterface;
use OwnPay\View\Theme\ThemeRendererRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors ThemeEnginesRegisterFilterTest, but for the admin-panel filter
 * ('admin.engines.register') and its registry. PlainPhpThemeRenderer is used
 * as a stand-in ThemeRendererInterface implementation in these tests purely
 * because it already exists and requires no constructor arguments - it is
 * not actually used to render any admin template.
 */
final class AdminEnginesRegisterFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        EventManager::resetInstance();
        parent::tearDown();
    }

    public function testFilterCanAddANewAdminEngine(): void
    {
        $events = EventManager::getInstance();
        $fakeEngine = new PlainPhpThemeRenderer();
        $events->addFilter('admin.engines.register', function (array $engines) use ($fakeEngine): array {
            $engines['blade'] = $fakeEngine;
            return $engines;
        });

        $baseEngines = ['twig' => new PlainPhpThemeRenderer()];
        $filtered = $events->applyFilter('admin.engines.register', $baseEngines);
        $registry = new ThemeRendererRegistry($filtered);

        $this->assertSame($fakeEngine, $registry->get('blade'));
        $this->assertInstanceOf(ThemeRendererInterface::class, $registry->get('twig'));
    }

    public function testNoListenersLeavesDefaultAdminEnginesUnchanged(): void
    {
        $events = EventManager::getInstance();
        $baseEngines = ['twig' => new PlainPhpThemeRenderer()];
        $filtered = $events->applyFilter('admin.engines.register', $baseEngines);
        $this->assertSame($baseEngines, $filtered);
    }

    /**
     * Mirrors config/services.php's admin.renderer_registry binding: run the
     * filter, then validate its output via ThemeRendererRegistry::sanitizeEngines()
     * before constructing the registry - the same code path the container
     * uses, not a re-implementation.
     */
    public function testMalformedFilterResultFallsBackToBaseAdminEngines(): void
    {
        $events = EventManager::getInstance();
        $events->addFilter('admin.engines.register', function (array $engines): array {
            $engines['broken'] = 'not-a-renderer';
            $engines[0] = new PlainPhpThemeRenderer();
            return $engines;
        });

        $baseEngines = ['twig' => new PlainPhpThemeRenderer()];
        $filtered = $events->applyFilter('admin.engines.register', $baseEngines);

        $discarded = [];
        $engines = ThemeRendererRegistry::sanitizeEngines(
            $filtered,
            $baseEngines,
            function (int|string $name, mixed $value) use (&$discarded): void {
                $discarded[] = $name;
            }
        );
        $registry = new ThemeRendererRegistry($engines);

        $this->assertInstanceOf(ThemeRendererInterface::class, $registry->get('twig'));
        $this->assertArrayNotHasKey('broken', $engines);
        $this->assertArrayNotHasKey(0, $engines);
        $this->assertContains('broken', $discarded);
        $this->assertContains(0, $discarded);
    }

    public function testNonArrayFilterResultFallsBackToBaseAdminEngines(): void
    {
        $events = EventManager::getInstance();
        $events->addFilter('admin.engines.register', function (array $engines): mixed {
            return 'totally-broken-plugin-output';
        });

        $baseEngines = ['twig' => new PlainPhpThemeRenderer()];
        $filtered = $events->applyFilter('admin.engines.register', $baseEngines);

        $warned = false;
        $engines = ThemeRendererRegistry::sanitizeEngines(
            $filtered,
            $baseEngines,
            function () use (&$warned): void {
                $warned = true;
            }
        );
        $registry = new ThemeRendererRegistry($engines);

        $this->assertTrue($warned);
        $this->assertSame($baseEngines, $engines);
        $this->assertInstanceOf(ThemeRendererInterface::class, $registry->get('twig'));
    }
}
