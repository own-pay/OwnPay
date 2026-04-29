<?php

declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Http\RequestContext;
use OwnPay\Service\Auth\PermissionGuard;

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

        if (!PermissionGuard::canAccess($ctx, $page)) {
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

        if (!PermissionGuard::has($ctx, $module, $action)) {
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
