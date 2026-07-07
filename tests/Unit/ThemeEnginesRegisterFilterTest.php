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

        $baseEngines = ['twig' => new PlainPhpThemeRenderer(), 'php' => new PlainPhpThemeRenderer()];
        $filtered = $events->applyFilter('theme.engines.register', $baseEngines);
        $registry = new ThemeRendererRegistry($filtered);

        $this->assertSame($fakeEngine, $registry->get('test-engine'));
        $this->assertInstanceOf(ThemeRendererInterface::class, $registry->get('twig'));
    }

    public function testNoListenersLeavesDefaultEnginesUnchanged(): void
    {
        $events = EventManager::getInstance();
        $baseEngines = ['twig' => new PlainPhpThemeRenderer(), 'php' => new PlainPhpThemeRenderer()];
        $filtered = $events->applyFilter('theme.engines.register', $baseEngines);
        $this->assertSame($baseEngines, $filtered);
    }

    /**
     * Mirrors config/services.php: run the filter, then validate its output via
     * ThemeRendererRegistry::sanitizeEngines() before constructing the registry.
     * This is the same code path the container uses (not a re-implementation),
     * so the test exercises the real validation logic rather than a stand-in.
     */
    public function testMalformedFilterResultFallsBackToBaseEngines(): void
    {
        $events = EventManager::getInstance();
        $events->addFilter('theme.engines.register', function (array $engines): array {
            $engines['blade'] = 'not-a-renderer'; // wrong type, not a ThemeRendererInterface
            $engines[0] = new PlainPhpThemeRenderer(); // non-string key
            return $engines;
        });

        $baseEngines = ['twig' => new PlainPhpThemeRenderer(), 'php' => new PlainPhpThemeRenderer()];
        $filtered = $events->applyFilter('theme.engines.register', $baseEngines);

        $discarded = [];
        $engines = ThemeRendererRegistry::sanitizeEngines(
            $filtered,
            $baseEngines,
            function (int|string $name, mixed $value) use (&$discarded): void {
                $discarded[] = $name;
            }
        );
        $registry = new ThemeRendererRegistry($engines);

        // Valid base engines survive despite the plugin's malformed additions.
        $this->assertInstanceOf(ThemeRendererInterface::class, $registry->get('twig'));
        $this->assertInstanceOf(ThemeRendererInterface::class, $registry->get('php'));
        $this->assertArrayNotHasKey('blade', $engines);
        $this->assertArrayNotHasKey(0, $engines);
        $this->assertContains('blade', $discarded);
        $this->assertContains(0, $discarded);
    }

    public function testNonArrayFilterResultFallsBackToBaseEngines(): void
    {
        $events = EventManager::getInstance();
        $events->addFilter('theme.engines.register', function (array $engines): mixed {
            return 'totally-broken-plugin-output'; // filter listener returns a non-array
        });

        $baseEngines = ['twig' => new PlainPhpThemeRenderer(), 'php' => new PlainPhpThemeRenderer()];
        $filtered = $events->applyFilter('theme.engines.register', $baseEngines);

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
        $this->assertInstanceOf(ThemeRendererInterface::class, $registry->get('php'));
    }

    public function testAllInvalidEntriesFallsBackToBaseEnginesRatherThanEmptyRegistry(): void
    {
        $baseEngines = ['twig' => new PlainPhpThemeRenderer(), 'php' => new PlainPhpThemeRenderer()];
        // Every entry in the filtered result is invalid - must not end up with
        // zero registered engines, which would break checkout rendering site-wide.
        $filtered = [0 => 'bad', 'also-bad' => 'not-a-renderer'];

        $engines = ThemeRendererRegistry::sanitizeEngines($filtered, $baseEngines);

        $this->assertSame($baseEngines, $engines);
        $this->assertNotEmpty($engines);
    }
}
