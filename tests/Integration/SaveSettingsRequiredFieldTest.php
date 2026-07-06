<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Controller\Admin\PluginController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\GatewayRepository;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Repository\PluginRepository;

/**
 * Proves PluginController::saveSettings() actually rejects a submission missing a
 * required field end-to-end (not just the isolated validateRequiredFields() logic) -
 * uses the real bundled 'affirm' gateway module, which declares three required fields
 * (public_key text, private_key password, mode select).
 *
 * The 'affirm' module ships on the filesystem (modules/gateways/affirm) so
 * resolvePluginInstance() finds it via PluginLoader::discover() without any DB row.
 * But saveSettings()'s gateway-vs-plugin branch checks PluginRepository::findBySlug(),
 * and its gateway-credential read/write path keys off a GatewayRepository (`op_gateways`)
 * row to resolve the gateway_id used by `op_gateway_configs`. Both rows normally only
 * exist after the plugin has been activated through PluginManager::activate(); a fresh
 * test database has neither for a not-yet-activated gateway, so this test seeds/removes
 * them itself rather than assuming they preexist.
 *
 * Uses its own dedicated test merchant rather than the `is_platform = 1` row: that row
 * is only seeded once at DB init and some suites (e.g. OnboardingBrandStepTest, which
 * deliberately runs `DELETE FROM op_merchants` with no WHERE clause to test the
 * "zero brands" onboarding path) can legitimately wipe the whole op_merchants table
 * between test classes, so depending on a pre-seeded row is order-dependent and flaky.
 */
final class SaveSettingsRequiredFieldTest extends IntegrationTestCase
{
    private const SLUG = 'affirm';

    private Database $db;
    private GatewayRepository $gwRepo;
    private PluginRepository $pluginRepo;
    private MerchantRepository $merchantRepo;
    private int $brandId;
    private int $gatewayId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $_ENV['ENCRYPTION_KEY'] = $_ENV['PII_ENCRYPTION_KEY'] ?? 'cd4c6edf857c4ad19cb41784e849adf79ec3fc20319c28e735bd3fbd801eca33';

        $this->db = Database::getInstance();

        $container = $this->buildContainer();
        $gwRepo = $container->get(GatewayRepository::class);
        $this->assertInstanceOf(GatewayRepository::class, $gwRepo);
        $this->gwRepo = $gwRepo;

        $pluginRepo = $container->get(PluginRepository::class);
        $this->assertInstanceOf(PluginRepository::class, $pluginRepo);
        $this->pluginRepo = $pluginRepo;

        $merchantRepo = $container->get(MerchantRepository::class);
        $this->assertInstanceOf(MerchantRepository::class, $merchantRepo);
        $this->merchantRepo = $merchantRepo;

        $this->db->execute("DELETE FROM op_merchants WHERE slug = 'zz-affirm-required-field-test'");
        $this->brandId = (int) $this->merchantRepo->createMerchant([
            'name'             => 'ZZ Affirm Required Field Test Brand',
            'slug'             => 'zz-affirm-required-field-test',
            'email'            => 'zz-affirm-required-field-test@example.test',
            'default_currency' => 'USD',
            'timezone'         => 'UTC',
            'status'           => 'active',
        ]);

        $existingGw = $this->gwRepo->findBySlug(self::SLUG);
        if ($existingGw !== null) {
            $this->gatewayId = (int) $existingGw['id'];
        } else {
            $this->gatewayId = (int) $this->gwRepo->create([
                'slug'   => self::SLUG,
                'name'   => 'Affirm',
                'type'   => 'api',
                'status' => 'active',
            ]);
        }

        if ($this->pluginRepo->findBySlug(self::SLUG) === null) {
            $this->pluginRepo->create([
                'slug'       => self::SLUG,
                'name'       => 'Affirm',
                'type'       => 'gateway',
                'version'    => '1.0.0',
                'entrypoint' => 'AffirmGateway.php',
                'status'     => 'active',
            ]);
        }

        $this->db->execute("DELETE FROM op_gateway_configs WHERE gateway_id = :gid AND merchant_id = :mid", ['gid' => $this->gatewayId, 'mid' => $this->brandId]);
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_gateway_configs WHERE gateway_id = :gid AND merchant_id = :mid", ['gid' => $this->gatewayId, 'mid' => $this->brandId]);
            $this->db->execute("DELETE FROM op_gateways WHERE id = :id AND slug = :slug", ['id' => $this->gatewayId, 'slug' => self::SLUG]);
            $this->db->execute("DELETE FROM op_plugins WHERE slug = :slug", ['slug' => self::SLUG]);
            $this->db->execute("DELETE FROM op_merchants WHERE id = :id", ['id' => $this->brandId]);
        }
        parent::tearDown();
    }

    private function buildContainer(): Container
    {
        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, $this->db);
        return $container;
    }

    public function testMissingRequiredFieldIsRejectedAndNotPersisted(): void
    {
        $container = $this->buildContainer();
        $controller = $container->get(PluginController::class);
        $this->assertInstanceOf(PluginController::class, $controller);

        $request = new Request(
            post: ['settings' => ['public_key' => '', 'private_key' => '', 'mode' => '']]
        );
        $request->setAttribute('merchant_id', $this->brandId);
        $request->setRouteParams(['slug' => 'affirm']);

        $response = $controller->saveSettings($request);

        $this->assertSame(302, $response->getStatusCode());

        $gwConfigRepo = $container->get(GatewayConfigRepository::class);
        $this->assertInstanceOf(GatewayConfigRepository::class, $gwConfigRepo);
        $saved = $gwConfigRepo->forTenant($this->brandId)->findForGateway($this->gatewayId);
        $this->assertTrue($saved === null || empty($saved['credentials_enc']), 'Settings should not have been persisted when required fields were missing.');
    }

    public function testAllRequiredFieldsPresentIsPersisted(): void
    {
        $container = $this->buildContainer();
        $controller = $container->get(PluginController::class);
        $this->assertInstanceOf(PluginController::class, $controller);

        $request = new Request(
            post: ['settings' => ['public_key' => 'pk_test_123', 'private_key' => 'sk_test_456', 'mode' => 'sandbox']]
        );
        $request->setAttribute('merchant_id', $this->brandId);
        $request->setRouteParams(['slug' => 'affirm']);

        $response = $controller->saveSettings($request);

        $this->assertSame(302, $response->getStatusCode());

        $gwRepo = $container->get(GatewayRepository::class);
        $this->assertInstanceOf(GatewayRepository::class, $gwRepo);
        $gwConfigRepo = $container->get(GatewayConfigRepository::class);
        $this->assertInstanceOf(GatewayConfigRepository::class, $gwConfigRepo);
        $saved = $gwConfigRepo->forTenant($this->brandId)->findForGateway($this->gatewayId);
        $this->assertNotNull($saved);
        $this->assertNotEmpty($saved['credentials_enc'] ?? '');
    }
}
