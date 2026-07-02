<?php

declare(strict_types=1);

namespace Tests\Plugin;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Repository\PluginRepository;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Plugin\PluginManager;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Plugin\Exception\PluginInUseException;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Event\EventManager;
use Tests\Integration\IntegrationTestCase;

final class TenantPluginLifecycleTest extends IntegrationTestCase
{
    private Container $c;
    private Database $db;
    private PluginRepository $pluginRepo;
    private MerchantRepository $merchantRepo;
    private PluginManager $pluginManager;
    private PluginRegistry $pluginRegistry;
    private BrandContext $brandContext;
    private EventManager $eventManager;

    private array $testMerchantIds = [];
    private string $dummySlug = 'tenant-test-plugin';
    private string $dummyType = 'addon';
    private string $dummyTypeDir = 'addons';
    private string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = Database::getInstance();

        $this->c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->c);

        $this->c->instance(Database::class, $this->db);

        $this->pluginRepo = $this->c->get(PluginRepository::class);
        $this->merchantRepo = $this->c->get(MerchantRepository::class);
        $this->pluginManager = $this->c->get(PluginManager::class);
        $this->pluginRegistry = $this->c->get(PluginRegistry::class);
        $this->brandContext = $this->c->get(BrandContext::class);
        $this->eventManager = $this->c->get(EventManager::class);

        $paths = $this->c->get('config.app')['paths'];
        $this->modulesPath = $paths['modules'] . '/' . $this->dummyTypeDir . '/' . $this->dummySlug;

        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanupTestData();
        }
        parent::tearDown();
    }

    private function cleanupTestData(): void
    {
        $this->db->execute("DELETE FROM op_brand_plugins WHERE plugin_slug = :slug", ['slug' => $this->dummySlug]);

        $plugin = $this->pluginRepo->findBySlug($this->dummySlug);
        if ($plugin !== null) {
            $this->db->execute("DELETE FROM op_plugins WHERE id = :id", ['id' => $plugin['id']]);
        }

        foreach ($this->testMerchantIds as $id) {
            $this->db->execute("DELETE FROM op_merchants WHERE id = :id", ['id' => $id]);
        }
        $this->testMerchantIds = [];

        if (is_dir($this->modulesPath)) {
            $this->removeDirectory($this->modulesPath);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function createDummyPlugin(): void
    {
        @mkdir($this->modulesPath, 0755, true);

        $manifest = [
            'slug' => $this->dummySlug,
            'name' => 'Tenant Test Plugin',
            'version' => '1.0.0',
            'type' => $this->dummyType,
            'entrypoint' => 'TenantTestPlugin.php',
            'description' => 'A dummy plugin for tenant-scoped lifecycle tests.'
        ];

        file_put_contents($this->modulesPath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        file_put_contents($this->modulesPath . '/TenantTestPlugin.php', "<?php\n\nclass TenantTestPlugin {}\n");

        $this->pluginRepo->create([
            'slug' => $this->dummySlug,
            'name' => 'Tenant Test Plugin',
            'type' => $this->dummyType,
            'version' => '1.0.0',
            'status' => 'inactive',
            'entrypoint' => 'TenantTestPlugin.php',
            'manifest' => json_encode($manifest)
        ]);
    }

    private function createTestMerchant(string $name, string $slug, string $email): int
    {
        $id = (int) $this->merchantRepo->createMerchant([
            'name'             => $name,
            'slug'             => $slug,
            'email'            => $email,
            'phone'            => '01711111111',
            'timezone'         => 'Asia/Dhaka',
            'default_currency' => 'BDT',
            'status'           => 'active',
        ]);
        $this->testMerchantIds[] = $id;
        return $id;
    }

    public function testBrandScopedActivationDeactivation(): void
    {
        $this->createDummyPlugin();
        $merchantA = $this->createTestMerchant('Brand A', 'brand-a', 'branda@example.com');
        $merchantB = $this->createTestMerchant('Brand B', 'brand-b', 'brandb@example.com');

        $plugin = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertNotNull($plugin);
        $this->assertSame('inactive', $plugin['status']);
        $this->assertFalse($this->pluginRepo->isPluginActiveForBrand($this->dummySlug, $merchantA));
        $this->assertFalse($this->pluginRepo->isPluginActiveForBrand($this->dummySlug, $merchantB));

        $res = $this->pluginManager->activate($this->dummySlug, $merchantA);
        $this->assertTrue($res['success']);

        $this->assertTrue($this->pluginRepo->isPluginActiveForBrand($this->dummySlug, $merchantA));
        $this->assertFalse($this->pluginRepo->isPluginActiveForBrand($this->dummySlug, $merchantB));
        $plugin = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertSame('inactive', $plugin['status']);

        $res = $this->pluginManager->deactivate($this->dummySlug, $merchantA);
        $this->assertTrue($res['success']);

        $this->assertFalse($this->pluginRepo->isPluginActiveForBrand($this->dummySlug, $merchantA));
    }

    public function testEventManagerHookFiltering(): void
    {
        $this->createDummyPlugin();
        $merchantA = $this->createTestMerchant('Brand A', 'brand-a', 'branda@example.com');
        $merchantB = $this->createTestMerchant('Brand B', 'brand-b', 'brandb@example.com');

        $actionFired = false;
        $this->eventManager->addAction('test.tenant.lifecycle.hook', function () use (&$actionFired) {
            $actionFired = true;
        }, 10, $this->dummySlug);

        $this->pluginManager->activate($this->dummySlug, $merchantA);

        $this->brandContext->setActiveBrandId($merchantA);
        $this->pluginRegistry->clearBrandActiveCache($merchantA);

        $actionFired = false;
        $this->eventManager->doAction('test.tenant.lifecycle.hook');
        $this->assertTrue($actionFired, 'Action hook should fire when active for current brand');

        $this->brandContext->setActiveBrandId($merchantB);
        $this->pluginRegistry->clearBrandActiveCache($merchantB);

        $actionFired = false;
        $this->eventManager->doAction('test.tenant.lifecycle.hook');
        $this->assertFalse($actionFired, 'Action hook should NOT fire when inactive for current brand');
    }

    public function testGlobalDeletionBlockWhenActiveOnAnyBrand(): void
    {
        $this->createDummyPlugin();
        $merchantA = $this->createTestMerchant('Brand A', 'brand-a', 'branda@example.com');

        $this->pluginManager->activate($this->dummySlug, $merchantA);

        try {
            $this->pluginManager->uninstall($this->dummySlug);
            $this->fail('Uninstall should have failed with PluginInUseException');
        } catch (PluginInUseException $e) {
            $this->assertStringContainsString('cannot be uninstalled because it is currently active', $e->getMessage());
        }

        $plugin = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertNotNull($plugin);

        $this->db->beginTransaction();
        $this->assertTrue($this->db->inTransaction());

        try {
            $this->pluginRepo->delete((int) $plugin['id']);
            $this->fail('Direct delete on repository should have failed with PluginInUseException');
        } catch (PluginInUseException $e) {
            // Transaction safety: exception must roll back the active transaction
            $this->assertFalse($this->db->inTransaction());
            $this->assertStringContainsString('cannot be uninstalled because it is currently active', $e->getMessage());
        }
    }

    public function testGlobalDeletionSucceedsWhenActiveBrandCountIsZero(): void
    {
        $this->createDummyPlugin();
        $merchantA = $this->createTestMerchant('Brand A', 'brand-a', 'branda@example.com');

        $this->pluginManager->deactivate($this->dummySlug, $merchantA);

        $res = $this->pluginManager->uninstall($this->dummySlug);
        $this->assertTrue($res['success']);

        $plugin = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertNull($plugin);
    }
}
