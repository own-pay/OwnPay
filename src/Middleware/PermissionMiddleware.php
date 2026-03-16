<?php

declare(strict_types=1);

namespace AnirbanPay\Middleware;

use AnirbanPay\Http\RequestContext;

/**
 * Extracts the auth-guard pattern from adapter.php controller dispatch.
 *
 * Provides reusable login, page-access, and action-permission gates
 * that send a JSON error response and exit on failure.
 */
final class PermissionMiddleware
{
    public function requireLogin(RequestContext $ctx): void
    {
        if (!$ctx->isLoggedIn) {
            echo json_encode([
                'status' => 'false',
                'title' => 'Session expired',
                'message' => 'Please log in again.',
                'csrf_token' => $ctx->csrfToken,
            ]);
            exit;
        }
    }

    public function requirePage(RequestContext $ctx, string $page): void
    {
        $this->requireLogin($ctx);

        $perms = json_decode(
            $GLOBALS['global_response_permission']['response'][0]['permission'] ?? '{}',
            true
        ) ?: [];

        if (!canAccessPage($perms, $page, $ctx->role)) {
            echo json_encode([
                'status' => 'false',
                'title' => 'Access denied',
                'message' => 'You do not have permission to access this page.',
                'csrf_token' => $ctx->csrfToken,
            ]);
            exit;
        }
    }

    public function requirePermission(RequestContext $ctx, string $module, string $action): void
    {
        $this->requireLogin($ctx);

        $perms = json_decode(
            $GLOBALS['global_response_permission']['response'][0]['permission'] ?? '{}',
            true
        ) ?: [];

        if (!hasPermission($perms, $module, $action, $ctx->role)) {
            echo json_encode([
                'status' => 'false',
                'title' => 'Permission denied',
                'message' => 'You do not have permission for this action.',
                'csrf_token' => $ctx->csrfToken,
            ]);
            exit;
        }
    }
}
