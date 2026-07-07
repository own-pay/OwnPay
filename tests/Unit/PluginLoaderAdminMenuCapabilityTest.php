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

    /**
     * Regression test for the ordering bug where registerManifestAdminMenu() was invoked inside
     * loadPlugin() BEFORE the plugin itself was registered into the registry's loaded map.
     * hasCapability() fails closed for any slug not yet present in that map, so the capability
     * check always returned false - silently breaking admin-menu registration for every plugin,
     * not just non-DASHBOARD ones.
     *
     * Unlike the two tests above (which manually inject a fake instance into the registry's
     * `loaded` array via Reflection and then call the private registerManifestAdminMenu() method
     * directly), this test drives the REAL private loadPlugin() call-site so it actually exercises
     * the call-ordering inside that method. A real on-disk plugin fixture (manifest.json +
     * entrypoint class) is required because loadPlugin() reads the manifest from disk, requires
     * the entrypoint file, and instantiates the class - none of that can be faked via Reflection
     * without bypassing the exact code path this bug lives in.
     */
    public function testLoadPluginRegistersMenuForRealDashboardCapablePlugin(): void
    {
        $modulesRoot = sys_get_temp_dir() . '/op-plugin-loader-test-' . uniqid('', true);
        $pluginDir = $modulesRoot . '/addons/dash-real';
        mkdir($pluginDir, 0777, true);

        $manifestData = [
            'name' => 'Dash Real',
            'slug' => 'dash-real',
            'version' => '1.0.0',
            'type' => 'plugin',
            'entrypoint' => 'Plugin.php',
            'namespace' => 'OwnPay\\Plugins\\DashReal\\' . uniqid('NS', false),
            'capabilities' => [Capability::DASHBOARD->value],
            'admin_menu' => [['label' => 'Dash Real Settings', 'url' => '/admin/dash-real']],
        ];
        file_put_contents($pluginDir . '/manifest.json', json_encode($manifestData, JSON_THROW_ON_ERROR));

        $className = rtrim($manifestData['namespace'], '\\') . '\\Plugin';
        $entrypointSource = <<<PHP
        <?php
        declare(strict_types=1);

        namespace {$manifestData['namespace']};

        use OwnPay\\Container;
        use OwnPay\\Event\\EventManager;
        use OwnPay\\Plugin\\Capability;
        use OwnPay\\Plugin\\PluginInterface;

        final class Plugin implements PluginInterface
        {
            public static function metadata(): array
            {
                return ['name' => 'Dash Real', 'slug' => 'dash-real', 'version' => '1.0.0', 'type' => 'plugin'];
            }

            public function capabilities(): array
            {
                return [Capability::DASHBOARD];
            }

            public function register(EventManager \$events, Container \$container): void {}

            public function boot(Container \$container): void {}

            public function deactivate(Container \$container): void {}

            public function uninstall(Container \$container): void {}

            public function fields(): array
            {
                return [];
            }
        }
        PHP;
        file_put_contents($pluginDir . '/Plugin.php', $entrypointSource);

        $container = new Container();
        $container->instance('config.app', ['paths' => ['modules' => $modulesRoot]]);
        $events = new EventManager();
        $db = $this->createMock(Database::class);
        $repo = new PluginRepository($db);
        $registry = new PluginRegistry($repo);
        $loader = new PluginLoader($container, $events, $registry);

        try {
            $ref = new \ReflectionClass($loader);
            $method = $ref->getMethod('loadPlugin');
            $method->setAccessible(true);
            $method->invoke($loader, ['slug' => 'dash-real', 'type' => 'plugin']);

            $this->assertTrue(
                $registry->isLoaded('dash-real'),
                'Plugin should be registered into the registry after loadPlugin() completes.'
            );
            $this->assertTrue(
                $events->hasAction('admin.menu.register'),
                'Admin menu action should be registered for a real DASHBOARD-capable plugin loaded via the actual loadPlugin() path.'
            );
        } finally {
            $this->removeDirectory($modulesRoot);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
