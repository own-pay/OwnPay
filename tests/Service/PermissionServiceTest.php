<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Service\Auth\PermissionService;
use PHPUnit\Framework\TestCase;

final class PermissionServiceTest extends TestCase
{
    public function testPermissionSchemaReturnsExpectedTopLevelKeys(): void
    {
        $schema = PermissionService::permissionSchema();
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('resources', $schema);
        $this->assertArrayHasKey('pages', $schema);
    }

    public function testPermissionSchemaResourcesContainsTransactions(): void
    {
        $schema = PermissionService::permissionSchema();
        $this->assertArrayHasKey('transaction', $schema['resources']);
        $this->assertArrayHasKey('approve', $schema['resources']['transaction']);
        $this->assertArrayHasKey('refund', $schema['resources']['transaction']);
    }

    public function testCountPermissionsForResourcesSumsActions(): void
    {
        $tabData = [
            'customers'   => ['create' => true, 'edit' => true, 'delete' => true],
            'transaction' => ['edit' => true, 'delete' => true, 'approve' => true],
        ];
        $this->assertSame(6, PermissionService::countPermissions('resources', $tabData));
    }

    public function testCountPermissionsForPagesReturnsCount(): void
    {
        $tabData = ['dashboard', 'reports', 'settings'];
        $this->assertSame(3, PermissionService::countPermissions('pages', $tabData));
    }

    public function testCountPermissionsForUnknownTabReturnsZero(): void
    {
        $this->assertSame(0, PermissionService::countPermissions('unknown', ['foo', 'bar']));
    }

    public function testHasPermissionSystemAdminAlwaysTrue(): void
    {
        // The bypass requires the caller to confirm via a DB lookup that the
        // role is the platform system 'admin' (is_system = 1) - the name alone
        // is NOT enough, because a merchant can create a custom role named 'admin'.
        $this->assertTrue(PermissionService::hasPermission([], 'transaction', 'edit', 'admin', true));
        $this->assertTrue(PermissionService::hasPermission([], 'nonexistent', 'delete', 'admin', true));
    }

    public function testHasPermissionCustomRoleNamedAdminDoesNotBypass(): void
    {
        // A custom (non-system) role whose NAME happens to be 'admin' must NOT
        // bypass permission checks. The caller must explicitly pass isSystemRole=true
        // after verifying the role is the platform system 'admin' via DB lookup.
        $this->assertFalse(PermissionService::hasPermission([], 'transaction', 'edit', 'admin'));
        $this->assertFalse(PermissionService::hasPermission([], 'transaction', 'edit', 'admin', false));
        $this->assertFalse(PermissionService::hasPermission(['resources' => ['transaction' => ['edit' => false]]], 'transaction', 'edit', 'admin'));
    }

    public function testHasPermissionStaffWithGrantedAction(): void
    {
        $perms = [
            'resources' => [
                'transaction' => ['edit' => true, 'delete' => false],
            ],
        ];
        $this->assertTrue(PermissionService::hasPermission($perms, 'transaction', 'edit', 'staff'));
    }

    public function testHasPermissionStaffWithDeniedAction(): void
    {
        $perms = [
            'resources' => [
                'transaction' => ['edit' => true, 'delete' => false],
            ],
        ];
        $this->assertFalse(PermissionService::hasPermission($perms, 'transaction', 'delete', 'staff'));
    }

    public function testHasPermissionStaffWithMissingResource(): void
    {
        $perms = ['resources' => []];
        $this->assertFalse(PermissionService::hasPermission($perms, 'transaction', 'edit', 'staff'));
    }

    public function testHasPermissionDefaultsToView(): void
    {
        $perms = [
            'resources' => [
                'reports' => ['view' => true],
            ],
        ];
        $this->assertTrue(PermissionService::hasPermission($perms, 'reports', 'view', 'staff'));
    }

    public function testCanAccessPageSystemAdminAlwaysTrue(): void
    {
        $this->assertTrue(PermissionService::canAccessPage([], 'any-page', 'admin', true));
    }

    public function testCanAccessPageCustomRoleNamedAdminDoesNotBypass(): void
    {
        $this->assertFalse(PermissionService::canAccessPage([], 'any-page', 'admin'));
        $this->assertFalse(PermissionService::canAccessPage([], 'any-page', 'admin', false));
    }

    public function testCanAccessPageStaffWithGrantedPage(): void
    {
        $perms = ['pages' => ['dashboard' => true, 'reports' => true]];
        $this->assertTrue(PermissionService::canAccessPage($perms, 'dashboard', 'staff'));
    }

    public function testCanAccessPageStaffWithMissingPage(): void
    {
        $perms = ['pages' => ['dashboard' => true]];
        $this->assertFalse(PermissionService::canAccessPage($perms, 'reports', 'staff'));
    }

    public function testCanAccessPageStaffWithFalseyValue(): void
    {
        $perms = ['pages' => ['dashboard' => false, 'reports' => null]];
        $this->assertFalse(PermissionService::canAccessPage($perms, 'dashboard', 'staff'));
        $this->assertFalse(PermissionService::canAccessPage($perms, 'reports', 'staff'));
    }
}
