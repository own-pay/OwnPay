<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Trait AdminScopeAwareTrait
 *
 * Provides a reusable scope-check helper for admin API controllers.
 *
 * Background: BearerAuthMiddleware enforces only a coarse read-for-GET /
 * write-for-non-GET split. Admin state-changing endpoints (revoke devices,
 * verify domains, retry SMS queue items, update SMS templates) must require
 * the `admin` scope on top of that, otherwise a default `['read','write']`
 * API key (the most common type) could tamper with SMS parsing templates,
 * revoke paired devices, or trigger SMS retries. This trait centralizes the
 * check so the four admin controllers share one canonical implementation.
 *
 * @package OwnPay\Controller\Api\Admin
 */
trait AdminScopeAwareTrait
{
    /**
     * Rejects the request unless the authenticated API key carries the `admin` scope.
     *
     * The `api_key` attribute is populated by BearerAuthMiddleware as the
     * authenticated API key row (associative array). Its `scopes` column is a
     * JSON-encoded array of strings (e.g. ["read","write","admin"]).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response|null A 401/403 error response if the key lacks the admin scope, otherwise null.
     */
    private function requireAdminScope(Request $req): ?Response
    {
        $apiKey = $req->getAttribute('api_key');
        if (!is_array($apiKey)) {
            return Response::apiError('UNAUTHORIZED', 'API key metadata missing.', null, 401);
        }

        $scopesRaw = $apiKey['scopes'] ?? null;
        $scopes = [];
        if (is_string($scopesRaw)) {
            $decoded = json_decode($scopesRaw, true);
            $scopes = is_array($decoded) ? $decoded : [];
        } elseif (is_array($scopesRaw)) {
            $scopes = $scopesRaw;
        }

        if (!in_array('admin', $scopes, true)) {
            return Response::apiError(
                'INSUFFICIENT_PRIVILEGE',
                'Insufficient API key privilege. Admin scope is required for this action.',
                null,
                403
            );
        }

        return null;
    }
}
