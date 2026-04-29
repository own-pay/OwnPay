<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use OwnPay\Http\RequestContext;

/**
 * Context-aware permission checking.
 *
 * Wraps PermissionService with RequestContext extraction, providing a
 * cleaner API for controllers:
 *
 *   // Before (legacy):
 *   canAccessPage(json_decode($perms['response'][0]['permission'], true), 'dashboard', $user['role']);
 *
 *   // After:
 *   PermissionGuard::canAccess($ctx, 'dashboard');
 */
final class PermissionGuard
{
    /**
     * Check if the current user can access a page.
     *
     * Replaces: canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), $page, $role)
     *
     * @param RequestContext $ctx  Current request context
     * @param string         $page Page slug to check
     * @return bool True if access is allowed
     */
    public static function canAccess(RequestContext $ctx, string $page): bool
    {
        return PermissionService::canAccessPage(
            $ctx->permissions,
            $page,
            $ctx->user['role'] ?? 'staff',
        );
    }

    /**
     * Check if the current user has a specific permission.
     *
     * Replaces: hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), $module, $action, $role)
     *
     * @param RequestContext $ctx    Current request context
     * @param string         $module Permission module (e.g. 'gateways')
     * @param string         $action Permission action ('view', 'create', 'edit', 'delete')
     * @return bool True if the user has the permission
     */
    public static function has(RequestContext $ctx, string $module, string $action = 'view'): bool
    {
        return PermissionService::hasPermission(
            $ctx->permissions,
            $module,
            $action,
            $ctx->user['role'] ?? 'staff',
        );
    }

    /**
     * Deny access with a JSON error response if the user lacks page access.
     *
     * Common pattern extracted from controllers — returns true if denied
     * (so the caller can `return` early), false if access is granted.
     *
     * @param RequestContext $ctx   Current request context
     * @param string         $page  Page slug to check
     * @return bool True if access was DENIED (caller should return)
     */
    public static function denyUnlessCanAccess(RequestContext $ctx, string $page): bool
    {
        if (!self::canAccess($ctx, $page)) {
            echo json_encode([
                'status'     => 'false',
                'title'      => 'Access denied',
                'message'    => 'You need permission to perform this action. Please contact the admin.',
                'csrf_token' => $ctx->csrfToken,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Deny access with a JSON error response if the user lacks a specific permission.
     *
     * @param RequestContext $ctx    Current request context
     * @param string         $module Permission module
     * @param string         $action Permission action
     * @return bool True if access was DENIED (caller should return)
     */
    public static function denyUnlessHas(RequestContext $ctx, string $module, string $action = 'view'): bool
    {
        if (!self::has($ctx, $module, $action)) {
            echo json_encode([
                'status'     => 'false',
                'title'      => 'Access denied',
                'message'    => 'You need permission to perform this action. Please contact the admin.',
                'csrf_token' => $ctx->csrfToken,
            ]);
            return true;
        }

        return false;
    }
}
