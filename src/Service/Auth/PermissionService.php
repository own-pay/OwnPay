<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use OwnPay\Repository\RoleRepository;

/**
 * OwnPay Permission Service.
 *
 * Implements administrative role CRUD management, permission mapping schemas,
 * tenant-scoped validation safeguards, and role permission synchronization.
 *
 * @package OwnPay\Service\Auth
 */
final class PermissionService
{
    /**
     * @var RoleRepository|null Role repository connection, nullable for stateless validation.
     */
    private ?RoleRepository $roles;

    /**
     * PermissionService constructor.
     *
     * @param RoleRepository|null $roles Role database interface repository.
     */
    public function __construct(?RoleRepository $roles = null)
    {
        $this->roles = $roles;
    }

    /**
     * Returns the structured default permission schema map.
     *
     * Defines accessible dashboard tabs, resource actions, and pages.
     *
     * @return array{resources: array<string, array<string, bool>>, pages: array<string, bool>}
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
     * Counts the total number of permission flags contained within a specific schema tab group.
     *
     * @param string $tab The tab section identifier ('resources' or 'pages').
     * @param array<string, mixed> $tabData The schema subset array.
     * @return int Computed count of permission capabilities.
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
     * Evaluates if a specific system role possesses capability on a given resource action.
     *
     * Superadmin ('admin') role bypasses all checks dynamically.
     *
     * @param array{resources?: array<string, array<string, bool>>} $perms Loaded permission map.
     * @param string $resource Target resource key.
     * @param string $action Target resource action.
     * @param string $role User's system role name.
     * @return bool True if authorized; false otherwise.
     */
    public static function hasPermission(array $perms, string $resource, string $action, string $role): bool
    {
        if ($role === 'admin') {
            return true;
        }
        return (bool) ($perms['resources'][$resource][$action] ?? false);
    }

    /**
     * Evaluates if a role has structural navigation access to a specific dashboard page.
     *
     * @param array{pages?: array<string, bool>} $perms Loaded permission map.
     * @param string $page Target page route slug.
     * @param string $role User's system role name.
     * @return bool True if authorized; false otherwise.
     */
    public static function canAccessPage(array $perms, string $page, string $role): bool
    {
        if ($role === 'admin') {
            return true;
        }
        return (bool) ($perms['pages'][$page] ?? false);
    }

    /**
     * Registers a new merchant/brand custom role and associates permission links.
     *
     * Enforces strict merchant segregation scoping.
     *
     * @param int $merchantId The owning merchant/brand context identifier.
     * @param string $name Human readable display name.
     * @param string $slug Unique route role identifier.
     * @param int[] $permissionIds List of permission primary IDs to assign.
     * @return string Generated role ID string.
     * @throws \RuntimeException If the role repository dependency has not been instantiated.
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
            $this->roles->syncPermissions((int) $id, array_values($permissionIds));
        }

        return $id;
    }

    /**
     * Synchronizes a role's target authorization permissions list.
     *
     * @param int $roleId Target role ID.
     * @param int[] $permissionIds Array of permission IDs.
     * @return void
     * @throws \RuntimeException If the role repository has not been initialized.
     */
    public function updatePermissions(int $roleId, array $permissionIds): void
    {
        if ($this->roles === null) {
            throw new \RuntimeException('Role repository not configured');
        }
        $this->roles->syncPermissions($roleId, array_values($permissionIds));
    }

    /**
     * Removes a custom merchant role.
     *
     * Safe-guarded against deleting core system roles or crossing tenant boundaries.
     *
     * @param int $roleId Target role ID.
     * @param int $merchantId The owning merchant/brand context identifier.
     * @return bool True if deleted successfully; false otherwise.
     * @throws \RuntimeException If the role repository has not been configured.
     */
    public function deleteRole(int $roleId, int $merchantId): bool
    {
        if ($this->roles === null) {
            throw new \RuntimeException('Role repository not configured');
        }
        $repo = $this->roles->forTenant($merchantId);
        $role = $repo->findScoped($roleId);
        if ($role === null || (bool) $role['is_system']) {
            return false;
        }
        return $repo->deleteScoped($roleId) > 0;
    }
}
