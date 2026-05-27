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

/**
 * TenantPluginLifecycleTest — Automated integration tests for brand-scoped plugin activation/deactivation
 * and global deletion block if a plugin is active on any brand.
 *
 * @group Integration
 */
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

        // Initialize container
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
        // 1. Delete pivot table entries
        $this->db->execute("DELETE FROM op_brand_plugins WHERE plugin_slug = :slug", ['slug' => $this->dummySlug]);

        // 2. Delete plugin record
        $plugin = $this->pluginRepo->findBySlug($this->dummySlug);
        if ($plugin !== null) {
            $this->db->execute("DELETE FROM op_plugins WHERE id = :id", ['id' => $plugin['id']]);
        }

        // 3. Delete merchants
        foreach ($this->testMerchantIds as $id) {
            $this->db->execute("DELETE FROM op_merchants WHERE id = :id", ['id' => $id]);
        }
        $this->testMerchantIds = [];

        // 4. Delete dummy plugin files
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

        // Insert database record
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

    /**
     * Test brand-scoped activation/deactivation updates op_brand_plugins and leaves global status untouched.
     */
    public function testBrandScopedActivationDeactivation(): void
    {
        $this->createDummyPlugin();
        $merchantA = $this->createTestMerchant('Brand A', 'brand-a', 'branda@example.com');
        $merchantB = $this->createTestMerchant('Brand B', 'brand-b', 'brandb@example.com');

        // Verify initial state
        $plugin = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertNotNull($plugin);
        $this->assertSame('inactive', $plugin['status']);
        $this->assertFalse($this->pluginRepo->isPluginActiveForBrand($this->dummySlug, $merchantA));
        $this->assertFalse($this->pluginRepo->isPluginActiveForBrand($this->dummySlug, $merchantB));

        // Activate for Brand A
        $res = $this->pluginManager->activate($this->dummySlug, $merchantA);
        $this->assertTrue($res['success']);

        // Verify Brand A is active, Brand B is inactive, and global status is still inactive
        $this->assertTrue($this->pluginRepo->isPluginActiveForBrand($this->dummySlug, $merchantA));
        $this->assertFalse($this->pluginRepo->isPluginActiveForBrand($this->dummySlug, $merchantB));
        $plugin = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertSame('inactive', $plugin['status']);

        // Deactivate for Brand A
        $res = $this->pluginManager->deactivate($this->dummySlug, $merchantA);
        $this->assertTrue($res['success']);

        // Verify Brand A is now inactive
        $this->assertFalse($this->pluginRepo->isPluginActiveForBrand($this->dummySlug, $merchantA));
    }

    /**
     * Test EventManager correctly executes hooks when plugin is active for current brand context
     * and filters them out when inactive.
     */
    public function testEventManagerHookFiltering(): void
    {
        $this->createDummyPlugin();
        $merchantA = $this->createTestMerchant('Brand A', 'brand-a', 'branda@example.com');
        $merchantB = $this->createTestMerchant('Brand B', 'brand-b', 'brandb@example.com');

        // Set up action callback registered with the dummy plugin as owner
        $actionFired = false;
        $this->eventManager->addAction('test.tenant.lifecycle.hook', function () use (&$actionFired) {
            $actionFired = true;
        }, 10, $this->dummySlug);

        // Activate for Brand A, keep inactive for Brand B
        $this->pluginManager->activate($this->dummySlug, $merchantA);

        // Scenario 1: Active Brand context is Brand A (Should run hook)
        $this->brandContext->setActiveBrandId($merchantA);
        $this->pluginRegistry->clearBrandActiveCache($merchantA);
        
        $actionFired = false;
        $this->eventManager->doAction('test.tenant.lifecycle.hook');
        $this->assertTrue($actionFired, 'Action hook should fire when active for current brand');

        // Scenario 2: Active Brand context is Brand B (Should NOT run hook)
        $this->brandContext->setActiveBrandId($merchantB);
        $this->pluginRegistry->clearBrandActiveCache($merchantB);

        $actionFired = false;
        $this->eventManager->doAction('test.tenant.lifecycle.hook');
        $this->assertFalse($actionFired, 'Action hook should NOT fire when inactive for current brand');
    }

    /**
     * Test global deletion is blocked if the plugin is active on any brand.
     * Transaction safety rules require rolling back active transactions before throwing the exception.
     */
    public function testGlobalDeletionBlockWhenActiveOnAnyBrand(): void
    {
        $this->createDummyPlugin();
        $merchantA = $this->createTestMerchant('Brand A', 'brand-a', 'branda@example.com');

        // Activate for Brand A
        $this->pluginManager->activate($this->dummySlug, $merchantA);

        // Try uninstallation via PluginManager
        try {
            $this->pluginManager->uninstall($this->dummySlug);
            $this->fail('Uninstall should have failed with PluginInUseException');
        } catch (PluginInUseException $e) {
            $this->assertStringContainsString('cannot be uninstalled because it is currently active', $e->getMessage());
        }

        // Try direct deletion on Repository within a database transaction
        $plugin = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertNotNull($plugin);

        $this->db->beginTransaction();
        $this->assertTrue($this->db->inTransaction());

        try {
            $this->pluginRepo->delete((int) $plugin['id']);
            $this->fail('Direct delete on repository should have failed with PluginInUseException');
        } catch (PluginInUseException $e) {
            // Verify database transaction was rolled back
            $this->assertFalse($this->db->inTransaction());
            $this->assertStringContainsString('cannot be uninstalled because it is currently active', $e->getMessage());
        }
    }

    /**
     * Test global deletion succeeds when active brand count is zero.
     */
    public function testGlobalDeletionSucceedsWhenActiveBrandCountIsZero(): void
    {
        $this->createDummyPlugin();
        $merchantA = $this->createTestMerchant('Brand A', 'brand-a', 'branda@example.com');

        // Inactive for Brand A
        $this->pluginManager->deactivate($this->dummySlug, $merchantA);

        // Perform uninstall
        $res = $this->pluginManager->uninstall($this->dummySlug);
        $this->assertTrue($res['success']);

        // Verify DB record is gone
        $plugin = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertNull($plugin);
    }
}
