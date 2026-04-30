<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use OwnPay\Repository\RoleRepository;

/**
 * Permission guard — checks if user has specific permission.
 */
final class PermissionGuard
{
    private RoleRepository $roles;

    /** @var array<int, string[]> Cached permissions by role_id */
    private array $cache = [];

    public function __construct(RoleRepository $roles)
    {
        $this->roles = $roles;
    }

    /**
     * Check if role has permission.
     */
    public function can(int $roleId, string $permission): bool
    {
        $perms = $this->permissionsFor($roleId);
        return in_array($permission, $perms, true) || in_array('*', $perms, true);
    }

    /**
     * Check if role has ANY of the given permissions.
     */
    public function canAny(int $roleId, array $permissions): bool
    {
        $perms = $this->permissionsFor($roleId);
        if (in_array('*', $perms, true)) {
            return true;
        }
        return !empty(array_intersect($permissions, $perms));
    }

    /**
     * Check if role has ALL of the given permissions.
     */
    public function canAll(int $roleId, array $permissions): bool
    {
        $perms = $this->permissionsFor($roleId);
        if (in_array('*', $perms, true)) {
            return true;
        }
        return empty(array_diff($permissions, $perms));
    }

    /**
     * @return string[]
     */
    public function permissionsFor(int $roleId): array
    {
        if (!isset($this->cache[$roleId])) {
            $this->cache[$roleId] = $this->roles->getPermissions($roleId);
        }
        return $this->cache[$roleId];
    }
}
