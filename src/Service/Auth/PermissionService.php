<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use OwnPay\Repository\RoleRepository;

/**
 * Permission service — CRUD for roles/permissions, sync operations, and schema helpers.
 */
final class PermissionService
{
    private ?RoleRepository $roles;

    public function __construct(?RoleRepository $roles = null)
    {
        $this->roles = $roles;
    }

    /**
     * Get available permission schema.
     */
    public static function permissionSchema(): array
    {
        return [
            'resources' => [
                'transaction' => ['approve' => true, 'refund' => true],
                'customers'   => ['create' => true, 'edit' => true, 'delete' => true],
                'reports'     => ['view' => true],
            ],
            'pages' => [
                'dashboard' => true,
                'reports'   => true,
                'settings'  => true,
            ],
        ];
    }

    /**
     * Count permissions for a specific tab.
     */
    public static function countPermissions(string $tab, array $tabData): int
    {
        if ($tab === 'resources') {
            $count = 0;
            foreach ($tabData as $resource => $actions) {
                if (is_array($actions)) {
                    foreach ($actions as $act => $val) {
                        if ($val) {
                            $count++;
                        }
                    }
                }
            }
            return $count;
        }

        if ($tab === 'pages') {
            return count($tabData);
        }

        return 0;
    }

    /**
     * Check if a role has permission for a resource and action.
     */
    public static function hasPermission(array $perms, string $resource, string $action, string $role): bool
    {
        if ($role === 'admin') {
            return true;
        }
        return (bool) ($perms['resources'][$resource][$action] ?? false);
    }

    /**
     * Check if a role can access a page.
     */
    public static function canAccessPage(array $perms, string $page, string $role): bool
    {
        if ($role === 'admin') {
            return true;
        }
        return (bool) ($perms['pages'][$page] ?? false);
    }

    /**
     * Create custom role for merchant.
     */
    public function createRole(int $merchantId, string $name, string $slug, array $permissionIds): string
    {
        if ($this->roles === null) {
            throw new \RuntimeException('Role repository not configured');
        }
        $repo = $this->roles->forTenant($merchantId);
        $id = $repo->createScoped([
            'name'        => $name,
            'slug'        => $slug,
            'description' => '',
            'is_system'   => 0,
        ]);

        if (!empty($permissionIds)) {
            $this->roles->syncPermissions((int) $id, $permissionIds);
        }

        return $id;
    }

    /**
     * Update role permissions.
     * @param int[] $permissionIds
     */
    public function updatePermissions(int $roleId, array $permissionIds): void
    {
        if ($this->roles === null) {
            throw new \RuntimeException('Role repository not configured');
        }
        $this->roles->syncPermissions($roleId, $permissionIds);
    }

    /**
     * Delete custom role (prevent system role deletion).
     */
    public function deleteRole(int $roleId): bool
    {
        if ($this->roles === null) {
            throw new \RuntimeException('Role repository not configured');
        }
        $role = $this->roles->find($roleId);
        if ($role === null || (bool) $role['is_system']) {
            return false;
        }
        return $this->roles->delete($roleId) > 0;
    }
}
