<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Service\Auth\PermissionService;
use PHPUnit\Framework\TestCase;

class PermissionServiceTest extends TestCase
{
    // â”€â”€ permissionSchema() â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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

    // â”€â”€ countPermissions() â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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

    // â”€â”€ hasPermission() â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testHasPermissionAdminAlwaysTrue(): void
    {
        $this->assertTrue(PermissionService::hasPermission([], 'transaction', 'edit', 'admin'));
        $this->assertTrue(PermissionService::hasPermission([], 'nonexistent', 'delete', 'admin'));
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

    // â”€â”€ canAccessPage() â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testCanAccessPageAdminAlwaysTrue(): void
    {
        $this->assertTrue(PermissionService::canAccessPage([], 'any-page', 'admin'));
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

