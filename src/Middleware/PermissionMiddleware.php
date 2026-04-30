<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Permission middleware — checks user has required permission for route.
 *
 * Fires 'auth.permission.check' filter for plugin override.
 */
final class PermissionMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $request->getAttribute('auth_user');

        if ($user === null) {
            // No auth user set — redirect to login
            if ($request->expectsJson()) {
                return Response::json(['success' => false, 'message' => 'Authentication required'], 401);
            }
            return Response::redirect('/login');
        }

        // Superadmin bypass
        if (($user['is_superadmin'] ?? false) === true) {
            return $next($request);
        }

        // Get required permission from route path
        $requiredPermission = $this->resolvePermission($request->path(), $request->method());

        if ($requiredPermission === null) {
            // No permission required for this route
            return $next($request);
        }

        $userPermissions = $request->getAttribute('user_permissions', []);

        // Allow plugins to override permission check
        /** @var EventManager $events */
        $events = $this->container->get(EventManager::class);
        $allowed = $events->applyFilter('auth.permission.check', 
            in_array($requiredPermission, $userPermissions, true),
            $requiredPermission,
            $user
        );

        if (!$allowed) {
            if ($request->expectsJson()) {
                return Response::json(['success' => false, 'message' => 'Insufficient permissions'], 403);
            }
            return Response::html('<h1>403 Forbidden</h1><p>You do not have permission to access this resource.</p>', 403);
        }

        return $next($request);
    }

    /**
     * Map route path to required permission slug.
     */
    private function resolvePermission(string $path, string $method): ?string
    {
        $map = [
            '/admin/transactions' => 'transactions.view',
            '/admin/invoices'     => 'invoices.view',
            '/admin/payment-links' => 'payment_links.view',
            '/admin/customers'    => 'customers.view',
            '/admin/gateways'     => 'gateways.view',
            '/admin/staff'        => 'staff.view',
            '/admin/merchants'    => 'merchants.view',
            '/admin/settings'     => 'settings.view',
            '/admin/api-keys'     => 'api_keys.view',
            '/admin/sms-center'   => 'sms.view',
            '/admin/sms-data'     => 'sms.view',
            '/admin/devices'      => 'devices.view',
            '/admin/plugins'      => 'plugins.view',
            '/admin/themes'       => 'plugins.view',
            '/admin/system-update' => 'system.update',
            '/admin/activities'   => 'system.audit',
            '/admin/reports'      => 'system.reports',
            '/admin/domains'      => 'domains.view',
            '/admin/balance-verification' => 'system.balance',
        ];

        // Check exact match first
        if (isset($map[$path])) {
            $perm = $map[$path];
            // POST = manage/update permission
            if ($method === 'POST') {
                $perm = str_replace('.view', '.manage', $perm);
                $perm = str_replace('.manage', '.update', $perm); // Fallback
            }
            return $perm;
        }

        // Check prefix match
        foreach ($map as $prefix => $perm) {
            if (str_starts_with($path, $prefix)) {
                if ($method === 'POST') {
                    return str_replace('.view', '.update', $perm);
                }
                return $perm;
            }
        }

        return null;
    }
}
