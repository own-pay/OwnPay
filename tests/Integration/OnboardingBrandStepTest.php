<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Admin\DashboardController;
use OwnPay\Http\Request;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Repository\SettingsRepository;

final class OnboardingBrandStepTest extends IntegrationTestCase
{
    private Database $db;
    private DashboardController $controller;
    private MerchantRepository $merchantRepo;
    private SettingsRepository $settingsRepo;

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }
        $this->db = Database::getInstance();
        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, $this->db);

        $controller = $container->get(DashboardController::class);
        $this->assertInstanceOf(DashboardController::class, $controller);
        $this->controller = $controller;

        $merchantRepo = $container->get(MerchantRepository::class);
        $this->assertInstanceOf(MerchantRepository::class, $merchantRepo);
        $this->merchantRepo = $merchantRepo;

        $settingsRepo = $container->get(SettingsRepository::class);
        $this->assertInstanceOf(SettingsRepository::class, $settingsRepo);
        $this->settingsRepo = $settingsRepo;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_merchants WHERE slug LIKE 'zzwizardbrand%'");
        unset($_SESSION['active_brand_id'], $_SESSION['auth_merchant_id']);
    }

    private function countBrands(): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) AS c FROM op_merchants")['c'];
    }

    public function testCreatesBrandWhenNoneExist(): void
    {
        // Isolate from any pre-existing rows left by fixtures/other suites so
        // the "zero brands" branch is actually exercised.
        $this->db->execute("DELETE FROM op_merchants");

        $req = new Request([], [
            'brand_name' => 'ZZ Wizard Brand', 'brand_email' => 'brand@zzwizardbrand.test',
            'brand_phone' => '', 'brand_currency' => 'USD', 'brand_timezone' => 'UTC',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $this->controller->createOnboardingBrand($req);
        $body = json_decode($res->getBody(), true);

        $this->assertTrue($body['success']);
        $this->assertSame(1, $this->countBrands());
    }

    public function testConfiguresExistingBrandInsteadOfDuplicating(): void
    {
        $this->db->execute("DELETE FROM op_merchants");
        $existingId = (int) $this->merchantRepo->createMerchant([
            'name' => 'Original Name', 'slug' => 'zzwizardbrand-orig', 'email' => 'orig@zzwizardbrand.test',
            'default_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active',
            'settings' => json_encode(['primary_color' => '#123456']),
        ]);

        $req = new Request([], [
            'brand_name' => 'Renamed Brand', 'brand_email' => 'renamed@zzwizardbrand.test',
            'brand_phone' => '555-0100', 'brand_currency' => 'EUR', 'brand_timezone' => 'Europe/Berlin',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $this->controller->createOnboardingBrand($req);
        $body = json_decode($res->getBody(), true);

        $this->assertTrue($body['success']);
        $this->assertSame($existingId, (int) $body['brand_id'], 'must return the existing brand id, not create a new one');
        $this->assertSame(1, $this->countBrands(), 'resuming the wizard must not create a second brand');

        $row = $this->db->fetchOne("SELECT * FROM op_merchants WHERE id = :id", ['id' => $existingId]);
        $this->assertSame('Renamed Brand', $row['name']);
        $this->assertSame('EUR', $row['default_currency']);
        $settings = json_decode($row['settings'], true);
        $this->assertSame('#123456', $settings['primary_color'], 'existing brand settings (colors) must be preserved, not wiped');
        $this->assertSame('active', $row['status'], 'existing brand status must be preserved, not reset');
    }
}
