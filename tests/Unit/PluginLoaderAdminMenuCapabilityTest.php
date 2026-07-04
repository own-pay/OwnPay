<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginLoader;
use OwnPay\Plugin\PluginManifest;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Repository\PluginRepository;
use PHPUnit\Framework\TestCase;

final class PluginLoaderAdminMenuCapabilityTest extends TestCase
{
    private function makeLoader(): array
    {
        $container = new Container();
        $container->instance('config.app', ['paths' => ['modules' => sys_get_temp_dir()]]);
        $events = new EventManager();
        $db = $this->createMock(Database::class);
        $repo = new PluginRepository($db);
        $registry = new PluginRegistry($repo);
        $loader = new PluginLoader($container, $events, $registry);
        return [$loader, $events];
    }

    private function callRegisterManifestAdminMenu(PluginLoader $loader, PluginManifest $manifest): void
    {
        $ref = new \ReflectionClass($loader);
        $method = $ref->getMethod('registerManifestAdminMenu');
        $method->setAccessible(true);
        $method->invoke($loader, $manifest);
    }

    public function testDashboardCapablePluginRegistersMenuAction(): void
    {
        [$loader, $events] = $this->makeLoader();
        $manifest = PluginManifest::fromArray([
            'name' => 'X', 'slug' => 'dash-x', 'type' => 'plugin', 'entrypoint' => 'X.php',
            'admin_menu' => [['label' => 'X Settings', 'url' => '/admin/x']],
        ], '/tmp/dash-x');

        $ref = new \ReflectionClass($loader);
        $registryProp = $ref->getProperty('registry');
        $registryProp->setAccessible(true);
        /** @var PluginRegistry $registry */
        $registry = $registryProp->getValue($loader);
        $loadedProp = (new \ReflectionClass($registry))->getProperty('loaded');
        $loadedProp->setAccessible(true);
        $loaded = $loadedProp->getValue($registry);
        $loaded['dash-x'] = new class implements \OwnPay\Plugin\PluginInterface {
            public static function metadata(): array { return ['name' => 'X', 'slug' => 'dash-x', 'version' => '1.0', 'type' => 'plugin']; }
            public function capabilities(): array { return [Capability::DASHBOARD]; }
            public function register(EventManager $events, Container $container): void {}
            public function boot(Container $container): void {}
            public function deactivate(Container $container): void {}
            public function uninstall(Container $container): void {}
            public function fields(): array { return []; }
        };
        $loadedProp->setValue($registry, $loaded);

        $this->callRegisterManifestAdminMenu($loader, $manifest);
        $this->assertTrue($events->hasAction('admin.menu.register'));
    }

    public function testNonDashboardCapablePluginSkipsMenuRegistration(): void
    {
        [$loader, $events] = $this->makeLoader();
        $manifest = PluginManifest::fromArray([
            'name' => 'Y', 'slug' => 'nodash-y', 'type' => 'plugin', 'entrypoint' => 'Y.php',
            'admin_menu' => [['label' => 'Y Settings', 'url' => '/admin/y']],
        ], '/tmp/nodash-y');

        $ref = new \ReflectionClass($loader);
        $registryProp = $ref->getProperty('registry');
        $registryProp->setAccessible(true);
        /** @var PluginRegistry $registry */
        $registry = $registryProp->getValue($loader);
        $loadedProp = (new \ReflectionClass($registry))->getProperty('loaded');
        $loadedProp->setAccessible(true);
        $loaded = $loadedProp->getValue($registry);
        $loaded['nodash-y'] = new class implements \OwnPay\Plugin\PluginInterface {
            public static function metadata(): array { return ['name' => 'Y', 'slug' => 'nodash-y', 'version' => '1.0', 'type' => 'plugin']; }
            public function capabilities(): array { return []; }
            public function register(EventManager $events, Container $container): void {}
            public function boot(Container $container): void {}
            public function deactivate(Container $container): void {}
            public function uninstall(Container $container): void {}
            public function fields(): array { return []; }
        };
        $loadedProp->setValue($registry, $loaded);

        $this->callRegisterManifestAdminMenu($loader, $manifest);
        $this->assertFalse($events->hasAction('admin.menu.register'));
    }
}
