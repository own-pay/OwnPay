<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Core\Database;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Repository\PluginRepository;
use PHPUnit\Framework\TestCase;

final class PluginRegistryHasCapabilityTest extends TestCase
{
    private function makeRegistry(): PluginRegistry
    {
        $db = $this->createMock(Database::class);
        $repo = new PluginRepository($db);
        return new PluginRegistry($repo);
    }

    private function loadFakePlugin(PluginRegistry $registry, string $slug, array $capabilities): void
    {
        $instance = new class ($capabilities) implements PluginInterface {
            private array $caps;
            public function __construct(array $caps) { $this->caps = $caps; }
            public static function metadata(): array { return ['name' => 'Fake', 'slug' => 'fake', 'version' => '1.0.0', 'type' => 'theme']; }
            public function capabilities(): array { return $this->caps; }
            public function register(\OwnPay\Event\EventManager $events, \OwnPay\Container $container): void {}
            public function boot(\OwnPay\Container $container): void {}
            public function deactivate(\OwnPay\Container $container): void {}
            public function uninstall(\OwnPay\Container $container): void {}
            public function fields(): array { return []; }
        };

        $ref = new \ReflectionClass($registry);
        $prop = $ref->getProperty('loaded');
        $prop->setAccessible(true);
        $loaded = $prop->getValue($registry);
        $loaded[$slug] = $instance;
        $prop->setValue($registry, $loaded);
    }

    public function testReturnsTrueWhenPluginDeclaresCapability(): void
    {
        $registry = $this->makeRegistry();
        $this->loadFakePlugin($registry, 'fake-theme', [Capability::THEME]);
        $this->assertTrue($registry->hasCapability('fake-theme', Capability::THEME));
    }

    public function testReturnsFalseWhenPluginLacksCapability(): void
    {
        $registry = $this->makeRegistry();
        $this->loadFakePlugin($registry, 'fake-theme', [Capability::ADDON]);
        $this->assertFalse($registry->hasCapability('fake-theme', Capability::THEME));
    }

    public function testReturnsFalseWhenSlugNotLoaded(): void
    {
        $registry = $this->makeRegistry();
        $this->assertFalse($registry->hasCapability('nope', Capability::THEME));
    }
}
