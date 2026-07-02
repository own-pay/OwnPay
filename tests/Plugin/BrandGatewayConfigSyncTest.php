<?php

declare(strict_types=1);

namespace Tests\Plugin;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Repository\PluginRepository;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Repository\GatewayRepository;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Plugin\PluginManager;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Http\Request;
use OwnPay\Controller\Admin\PluginController;
use Tests\Integration\IntegrationTestCase;

final class BrandGatewayConfigSyncTest extends IntegrationTestCase
{
    private Container $c;
    private Database $db;
    private PluginRepository $pluginRepo;
    private MerchantRepository $merchantRepo;
    private GatewayRepository $gwRepo;
    private GatewayConfigRepository $gwConfigRepo;
    private PluginManager $pluginManager;
    private PluginRegistry $pluginRegistry;
    private BrandContext $brandContext;
    private PluginController $pluginController;

    private array $testMerchantIds = [];
    private string $dummySlug = 'test-gateway-plugin';
    private string $dummyType = 'gateway';
    private string $dummyTypeDir = 'gateways';
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
        $this->gwRepo = $this->c->get(GatewayRepository::class);
        $this->gwConfigRepo = $this->c->get(GatewayConfigRepository::class);
        $this->pluginManager = $this->c->get(PluginManager::class);
        $this->pluginRegistry = $this->c->get(PluginRegistry::class);
        $this->brandContext = $this->c->get(BrandContext::class);
        $this->pluginController = $this->c->get(PluginController::class);

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
        $gw = $this->gwRepo->findBySlug($this->dummySlug);
        if ($gw !== null) {
            $this->db->execute("DELETE FROM op_gateway_configs WHERE gateway_id = :gw_id", ['gw_id' => $gw['id']]);
            $this->db->execute("DELETE FROM op_gateways WHERE id = :id", ['id' => $gw['id']]);
        }

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
            'name' => 'Test Gateway Plugin',
            'version' => '1.0.0',
            'type' => $this->dummyType,
            'entrypoint' => 'TestGatewayPlugin.php',
            'description' => 'A dummy plugin for gateway lifecycle tests.',
            'icon' => 'logo.png',
            'capabilities' => ['checkout'],
        ];

        file_put_contents($this->modulesPath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        file_put_contents($this->modulesPath . '/logo.png', 'fake image bytes');
        file_put_contents($this->modulesPath . '/TestGatewayPlugin.php', "<?php\n\nnamespace OwnPay\\Plugins\\TestGatewayPlugin;\n\nclass TestGatewayPlugin implements \\OwnPay\\Plugin\\PluginInterface {\n    public function boot(\\OwnPay\\Container \$c): void {}\n    public function deactivate(\\OwnPay\\Container \$c): void {}\n    public function uninstall(\\OwnPay\\Container \$c): void {}\n    public function capabilities(): array { return [\\OwnPay\\Plugin\\Capability::Checkout]; }\n    public function fields(): array { return []; }\n}\n");

        $this->pluginRepo->create([
            'slug' => $this->dummySlug,
            'name' => 'Test Gateway Plugin',
            'type' => $this->dummyType,
            'version' => '1.0.0',
            'status' => 'inactive',
            'entrypoint' => 'TestGatewayPlugin.php',
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

    public function testGatewaySyncWorkflow(): void
    {
        $this->createDummyPlugin();
        $merchantId = $this->createTestMerchant('Gateway Brand', 'gateway-brand', 'gatewaybrand@example.com');

        $gw = $this->gwRepo->findBySlug($this->dummySlug);
        $this->assertNull($gw);

        $res = $this->pluginManager->activate($this->dummySlug, $merchantId);
        $this->assertTrue($res['success']);

        $gw = $this->gwRepo->findBySlug($this->dummySlug);
        $this->assertNotNull($gw);
        $gwId = (int) $gw['id'];

        $config = $this->gwConfigRepo->forTenant($merchantId)->findForGateway($gwId);
        $this->assertNotNull($config);
        $this->assertSame('active', $config['status']);

        $checkoutGws = $this->gwConfigRepo->forTenant($merchantId)->listActiveForCheckout();
        $slugs = array_column($checkoutGws, 'slug');
        $this->assertContains($this->dummySlug, $slugs);

        $res = $this->pluginManager->deactivate($this->dummySlug, $merchantId);
        $this->assertTrue($res['success']);

        $config = $this->gwConfigRepo->forTenant($merchantId)->findForGateway($gwId);
        $this->assertSame('inactive', $config['status']);

        $checkoutGws = $this->gwConfigRepo->forTenant($merchantId)->listActiveForCheckout();
        $slugs = array_column($checkoutGws, 'slug');
        $this->assertNotContains($this->dummySlug, $slugs);

        $this->brandContext->setActiveBrandId($merchantId);

        $request = new Request(
            [],
            ['settings' => ['secret_key' => 'testing123']]
        );
        $request->setRouteParams(['slug' => $this->dummySlug]);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['active_brand_id'] = $merchantId;

        $response = $this->pluginController->saveSettings($request);
        $this->assertSame(302, $response->getStatusCode());

        $config = $this->gwConfigRepo->forTenant($merchantId)->findForGateway($gwId);
        $this->assertSame('active', $config['status']);

        $checkoutGws = $this->gwConfigRepo->forTenant($merchantId)->listActiveForCheckout();
        $slugs = array_column($checkoutGws, 'slug');
        $this->assertContains($this->dummySlug, $slugs);
    }
}
