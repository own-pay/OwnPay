<?php
declare(strict_types=1);

namespace Tests\Unit\Admin;

use OwnPay\Controller\Admin\AuthController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AuthControllerTest extends TestCase
{
    private ReflectionMethod $landingPath;

    /**
     * Global "All Brands" view marker; brand view is any id above 0.
     */
    private const GLOBAL_VIEW = 0;

    protected function setUp(): void
    {
        $this->landingPath = new ReflectionMethod(AuthController::class, 'resolvePostLoginPath');
        $this->landingPath->setAccessible(true);
    }

    public function testDashboardPermissionUsesDashboardAsLandingPage(): void
    {
        $controller = (new ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();

        $path = $this->landingPath->invoke($controller, ['dashboard.view', 'staff.view'], self::GLOBAL_VIEW);

        self::assertSame('/admin', $path);
    }

    public function testStaffWithoutDashboardPermissionUsesStaffPage(): void
    {
        $controller = (new ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();

        $path = $this->landingPath->invoke($controller, ['staff.view'], self::GLOBAL_VIEW);

        self::assertSame('/admin/staff', $path);
    }

    public function testRoleWithoutAdminPermissionHasNoLandingPage(): void
    {
        $controller = (new ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();

        $path = $this->landingPath->invoke($controller, [], self::GLOBAL_VIEW);

        self::assertNull($path);
    }

    public function testWebhookAndSystemPermissionsUseTheirOwnLandingPage(): void
    {
        $controller = (new ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();

        self::assertSame(
            '/admin/webhooks/events',
            $this->landingPath->invoke($controller, ['webhooks.view'], self::GLOBAL_VIEW)
        );
        self::assertSame(
            '/admin/system-update',
            $this->landingPath->invoke($controller, ['system.update'], self::GLOBAL_VIEW)
        );
        self::assertSame(
            '/admin/balance-verification',
            $this->landingPath->invoke($controller, ['system.balance'], self::GLOBAL_VIEW)
        );
    }

    public function testBrandViewSkipsGlobalOnlyLandingPages(): void
    {
        $controller = (new ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();

        // PermissionMiddleware redirects brand-view sessions off these routes to
        // /admin, which needs dashboard.view — fall through to the account page
        // the role can actually open instead.
        self::assertSame(
            '/admin/my-account',
            $this->landingPath->invoke($controller, ['system.update', 'admin.access'], 5)
        );
        self::assertSame(
            '/admin/my-account',
            $this->landingPath->invoke($controller, ['system.balance', 'admin.access'], 5)
        );
        self::assertSame(
            '/admin/my-account',
            $this->landingPath->invoke($controller, ['brands.view', 'admin.access'], 5)
        );
        // Routes that are open in brand view keep their own landing.
        self::assertSame(
            '/admin/webhooks/events',
            $this->landingPath->invoke($controller, ['webhooks.view'], 5)
        );
        self::assertSame(
            '/admin',
            $this->landingPath->invoke($controller, ['dashboard.view'], 5)
        );
        // No reachable page at all: the caller logs the account out.
        self::assertNull($this->landingPath->invoke($controller, ['system.update'], 5));
    }
}
