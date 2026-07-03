<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Admin\DashboardController;
use OwnPay\Http\Request;

final class OnboardingLandingDefaultTest extends IntegrationTestCase
{
    private DashboardController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }
        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, Database::getInstance());

        $controller = $container->get(DashboardController::class);
        $this->assertInstanceOf(DashboardController::class, $controller);
        $this->controller = $controller;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['brand_view_mode'] = 'single';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['brand_view_mode']);
        parent::tearDown();
    }

    public function testCompleteOnboardingSetsGlobalBrandView(): void
    {
        $req = new Request([], [], ['REQUEST_METHOD' => 'POST']);
        $res = $this->controller->completeOnboarding($req);
        $body = json_decode($res->getBody(), true);

        $this->assertTrue($body['success']);
        $this->assertSame('global', $_SESSION['brand_view_mode']);
    }

    public function testDismissOnboardingSetsGlobalBrandView(): void
    {
        $req = new Request([], [], ['REQUEST_METHOD' => 'POST']);
        $res = $this->controller->dismissOnboarding($req);
        $body = json_decode($res->getBody(), true);

        $this->assertTrue($body['success']);
        $this->assertSame('global', $_SESSION['brand_view_mode']);
    }
}
