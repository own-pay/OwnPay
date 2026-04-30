<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use OwnPay\Repository\RoleRepository;

/**
 * Permission service — CRUD for roles/permissions, sync operations.
 */
final class PermissionService
{
    private RoleRepository $roles;

    public function __construct(RoleRepository $roles)
    {
        $this->roles = $roles;
    }

    /**
     * Create custom role for merchant.
     */
    public function createRole(int $merchantId, string $name, string $slug, array $permissionIds): string
    {
        $repo = $this->roles->forTenant($merchantId);
        $id = $repo->createScoped([
            'name' => $name,
            'slug' => $slug,
            'description' => '',
            'is_system' => 0,
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
        $this->roles->syncPermissions($roleId, $permissionIds);
    }

    /**
     * Delete custom role (prevent system role deletion).
     */
    public function deleteRole(int $roleId): bool
    {
        $role = $this->roles->find($roleId);
        if ($role === null || (bool) $role['is_system']) {
            return false;
        }
        return $this->roles->delete($roleId) > 0;
    }
}
