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

    protected function setUp(): void
    {
        $this->landingPath = new ReflectionMethod(AuthController::class, 'resolvePostLoginPath');
        $this->landingPath->setAccessible(true);
    }

    public function testDashboardPermissionUsesDashboardAsLandingPage(): void
    {
        $controller = (new ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();

        $path = $this->landingPath->invoke($controller, ['dashboard.view', 'staff.view']);

        self::assertSame('/admin', $path);
    }

    public function testStaffWithoutDashboardPermissionUsesStaffPage(): void
    {
        $controller = (new ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();

        $path = $this->landingPath->invoke($controller, ['staff.view']);

        self::assertSame('/admin/staff', $path);
    }

    public function testRoleWithoutAdminPermissionHasNoLandingPage(): void
    {
        $controller = (new ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();

        $path = $this->landingPath->invoke($controller, []);

        self::assertNull($path);
    }
}
