<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Admin\DashboardController;
use OwnPay\Http\Request;
use OwnPay\Repository\SettingsRepository;

final class OnboardingRouteRedirectTest extends IntegrationTestCase
{
    private DashboardController $controller;
    private SettingsRepository $settingsRepo;

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }
        $_SESSION = ['is_superadmin' => true];
        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, Database::getInstance());

        $controller = $container->get(DashboardController::class);
        $this->assertInstanceOf(DashboardController::class, $controller);
        $this->controller = $controller;

        $settingsRepo = $container->get(SettingsRepository::class);
        $this->assertInstanceOf(SettingsRepository::class, $settingsRepo);
        $this->settingsRepo = $settingsRepo;
    }

    protected function tearDown(): void
    {
        if (!static::$dbAvailable) {
            parent::tearDown();
            return;
        }
        $this->settingsRepo->set('system', 'onboarding_completed', '1');
        $_SESSION = [];
        parent::tearDown();
    }

    public function testDashboardRedirectsToWizardWhenOnboardingIncomplete(): void
    {
        $this->settingsRepo->set('system', 'onboarding_completed', '0');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $res = $this->controller->index($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/setup-wizard', $res->getHeaders()['Location'] ?? null);
    }

    public function testStaffDashboardDoesNotRedirectToForbiddenWizard(): void
    {
        $_SESSION = ['is_superadmin' => false];
        $this->settingsRepo->set('system', 'onboarding_completed', '0');

        $res = $this->controller->index(new Request([], [], ['REQUEST_METHOD' => 'GET']));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertArrayNotHasKey('Location', $res->getHeaders());
    }

    public function testWizardRedirectsToDashboardWhenOnboardingComplete(): void
    {
        $this->settingsRepo->set('system', 'onboarding_completed', '1');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $res = $this->controller->setupWizard($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin', $res->getHeaders()['Location'] ?? null);
    }

    public function testWizardRendersWhenOnboardingIncomplete(): void
    {
        $this->settingsRepo->set('system', 'onboarding_completed', '0');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $res = $this->controller->setupWizard($req);

        $this->assertSame(200, $res->getStatusCode());
    }
}
