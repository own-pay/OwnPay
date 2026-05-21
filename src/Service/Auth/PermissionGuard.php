<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use OwnPay\Repository\RoleRepository;

/**
 * OwnPay RBAC Permission Guard Service.
 *
 * Provides granular evaluation of role capabilities against requested permission keys,
 * utilizing a localized in-memory runtime cache to reduce duplicate query iterations.
 *
 * @package OwnPay\Service\Auth
 */
final class PermissionGuard
{
    /**
     * @var RoleRepository The repository managing user role assignments and permissions.
     */
    private RoleRepository $roles;

    /**
     * @var array<int, string[]> Cached permissions index mapped by role identifier.
     */
    private array $cache = [];

    /**
     * PermissionGuard constructor.
     *
     * @param RoleRepository $roles Role repository data gateway.
     */
    public function __construct(RoleRepository $roles)
    {
        $this->roles = $roles;
    }

    /**
     * Asserts whether a role is authorized for a specific permission slug.
     *
     * Supports global wildcard capability validation ('*').
     *
     * @param int $roleId The primary identifier of the role.
     * @param string $permission The permission slug to evaluate.
     * @return bool True if authorized; false otherwise.
     */
    public function can(int $roleId, string $permission): bool
    {
        $perms = $this->permissionsFor($roleId);
        return in_array($permission, $perms, true) || in_array('*', $perms, true);
    }

    /**
     * Asserts whether a role is authorized for at least one of the provided permission slugs.
     *
     * @param int $roleId The primary identifier of the role.
     * @param string[] $permissions The list of target permission slugs.
     * @return bool True if authorized for any; false otherwise.
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
     * Asserts whether a role is authorized for all of the provided permission slugs.
     *
     * @param int $roleId The primary identifier of the role.
     * @param string[] $permissions The list of target permission slugs.
     * @return bool True if authorized for all; false otherwise.
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
     * Resolves and caches the list of permission slugs configured for a specific role.
     *
     * @param int $roleId The primary identifier of the role.
     * @return string[] List of authorization slugs.
     */
    public function permissionsFor(int $roleId): array
    {
        if (!isset($this->cache[$roleId])) {
            $this->cache[$roleId] = $this->roles->getPermissions($roleId);
        }
        return $this->cache[$roleId];
    }
}
