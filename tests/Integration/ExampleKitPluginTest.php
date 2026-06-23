<?php
declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Cron\CronJobRunner;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Router;
use OwnPay\Plugin\PluginLoader;
use OwnPay\Plugin\PluginRegistry;

/**
 * End-to-end proof of the WordPress-style plugin wiring, using the shipped reference addon
 * modules/addons/example-kit. Exercises: multi-file class autoloading, manifest routes (including an
 * authenticated middleware group), manifest cron scheduling, the declarative admin_menu bridge, and an
 * EventManager hook listener.
 */
final class ExampleKitPluginTest extends IntegrationTestCase
{
    private Database $db;
    private Container $c;
    private PluginRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $this->c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->c);
        $this->c->instance(Database::class, $this->db);

        // Mark the example plugin active so EventManager owner-activation checks pass for its listeners
        // (the admin-menu and payment hooks are scoped to the 'example-kit' owner).
        $this->db->execute("DELETE FROM op_plugins WHERE slug = 'example-kit'");
        $this->db->execute(
            "INSERT INTO op_plugins (slug, name, type, version, entrypoint, capabilities, manifest, status)
             VALUES ('example-kit', 'Example Kit', 'addon', '1.0.0', 'Plugin.php', :caps, :man, 'active')",
            ['caps' => (string) json_encode(['addon', 'hooks', 'cron']), 'man' => (string) json_encode([])]
        );

        // Load the real plugin from modules/addons into the registry (without full app boot).
        $loader = $this->c->get(PluginLoader::class);
        $this->assertInstanceOf(PluginLoader::class, $loader);
        $method = new \ReflectionMethod(PluginLoader::class, 'loadPlugin');
        $method->setAccessible(true);
        $method->invoke($loader, ['slug' => 'example-kit', 'type' => 'addon']);

        $registry = $this->c->get(PluginRegistry::class);
        $this->assertInstanceOf(PluginRegistry::class, $registry);
        $this->registry = $registry;
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_plugins WHERE slug = 'example-kit'");
        }
        parent::tearDown();
    }

    public function testPluginLoadsAndMultiFileClassesAutoload(): void
    {
        $this->assertNotNull($this->registry->get('example-kit'), 'example-kit should be loaded');
        $this->assertTrue(
            class_exists('OwnPay\\Modules\\Addons\\ExampleKit\\Service\\PingTracker'),
            'a second class should autoload from the plugin directory (Service/PingTracker.php)'
        );
        $this->assertTrue(
            class_exists('OwnPay\\Modules\\Addons\\ExampleKit\\Cron\\HeartbeatJob'),
            'the cron job class should autoload from the plugin directory (Cron/HeartbeatJob.php)'
        );
    }

    public function testManifestRoutesRegisterWithDeclaredMiddleware(): void
    {
        $this->ensureInstalledMarker();

        $router = $this->c->get(Router::class);
        $this->assertInstanceOf(Router::class, $router);
        $router->loadRoutes();

        $public = $router->match($this->request('GET', '/plugins/example-kit/ping'));
        $this->assertNotNull($public, 'public plugin route should be registered');
        $this->assertSame('api-public', $public['middleware'], 'an unspecified plugin route defaults to api-public');
        $this->assertStringContainsString('\\ExampleKit\\Plugin@ping', $public['handler']);

        $admin = $router->match($this->request('GET', '/admin/example-kit'));
        $this->assertNotNull($admin, 'authenticated plugin route should be registered');
        $this->assertSame('admin', $admin['middleware'], 'plugin route must honour its declared middleware group');
        $this->assertStringContainsString('\\ExampleKit\\Plugin@adminHome', $admin['handler']);
    }

    public function testManifestCronJobIsScheduled(): void
    {
        $runner = $this->c->get(CronJobRunner::class);
        $this->assertInstanceOf(CronJobRunner::class, $runner);

        $jobs = $runner->getJobs();
        $this->assertArrayHasKey('plugin:example-kit:heartbeat', $jobs);
        $this->assertSame('every_5min', $jobs['plugin:example-kit:heartbeat']['schedule']);
    }

    public function testDeclarativeAdminMenuRendersViaHook(): void
    {
        $events = $this->c->get(EventManager::class);
        $this->assertInstanceOf(EventManager::class, $events);

        ob_start();
        $events->doAction('admin.menu.register');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Example Kit', $html);
        $this->assertStringContainsString('/admin/example-kit', $html);
    }

    public function testHookListenerFiresOnCompletedPayment(): void
    {
        $plugin = $this->registry->get('example-kit');
        $this->assertInstanceOf(\OwnPay\Modules\Addons\ExampleKit\Plugin::class, $plugin);
        $this->assertSame(0, $plugin->tracker()->count());

        $events = $this->c->get(EventManager::class);
        $this->assertInstanceOf(EventManager::class, $events);
        $events->doAction('payment.transaction.completed', ['merchant_id' => 1, 'amount' => '10.00']);

        $this->assertSame(1, $plugin->tracker()->count(), 'the plugin listener should have run for the active plugin');
    }

    private function request(string $method, string $path): Request
    {
        return new Request([], [], ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path]);
    }

    private function ensureInstalledMarker(): void
    {
        // Plugin routes only register on an installed system; the test DB has a superadmin, so the
        // marker's correct steady state is "present" (the app self-heals it on boot).
        $marker = dirname(__DIR__, 2) . '/storage/.installed';
        if (!file_exists($marker)) {
            @file_put_contents($marker, 'installed');
        }
    }
}
