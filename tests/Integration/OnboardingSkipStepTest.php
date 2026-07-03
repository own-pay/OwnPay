<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Admin\DashboardController;
use OwnPay\Http\Request;
use OwnPay\Repository\SettingsRepository;

final class OnboardingSkipStepTest extends IntegrationTestCase
{
    private Database $db;
    private DashboardController $controller;
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

        $settingsRepo = $container->get(SettingsRepository::class);
        $this->assertInstanceOf(SettingsRepository::class, $settingsRepo);
        $this->settingsRepo = $settingsRepo;
    }

    public function testSkippingPlatformSettingsStepAppliesDefaultsAndSucceeds(): void
    {
        $req = new Request([], ['skip' => '1'], ['REQUEST_METHOD' => 'POST']);
        $res = $this->controller->saveOnboardingSettings($req);
        $body = json_decode($res->getBody(), true);

        $this->assertTrue($body['success']);
        $this->assertTrue((bool) ($body['skipped'] ?? false));
        // Defaults must actually persist so later steps/resume have valid data.
        $this->assertNotSame('', $this->settingsRepo->get('general', 'currency', ''));
        $this->assertNotSame('', $this->settingsRepo->get('general', 'timezone', ''));
    }

    public function testSkippingGatewayStepSucceedsWithoutCreatingAGateway(): void
    {
        $before = (int) $this->db->fetchOne("SELECT COUNT(*) AS c FROM op_gateway_configs")['c'];

        $req = new Request([], ['skip' => '1'], ['REQUEST_METHOD' => 'POST']);
        $res = $this->controller->setupOnboardingGateway($req);
        $body = json_decode($res->getBody(), true);

        $this->assertTrue($body['success']);
        $this->assertTrue((bool) ($body['skipped'] ?? false));

        $after = (int) $this->db->fetchOne("SELECT COUNT(*) AS c FROM op_gateway_configs")['c'];
        $this->assertSame($before, $after, 'skipping the gateway step must not create any gateway config');
    }
}
