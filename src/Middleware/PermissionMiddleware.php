<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware handling Role-Based Access Control (RBAC) checks.
 *
 * Verifies that the authenticated user possesses the permission token associated
 * with the target route path and HTTP request method. Bypasses checks for superadmins.
 */
final class PermissionMiddleware
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $container;

    /**
     * Constructs a new PermissionMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Handles authorization verification for incoming HTTP requests to admin routes.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        $userId = $request->getAttribute('auth_user_id') ?? ($_SESSION['auth_user_id'] ?? null);

        if ($userId === null) {
            if ($request->expectsJson()) {
                return Response::json(['success' => false, 'message' => 'Authentication required'], 401);
            }
            // Use dynamic login slug instead of hardcoded /login.
            $loginSlug = $this->resolveLoginSlug();
            return Response::redirect("/{$loginSlug}");
        }

        // Lazy load user from DB if not passed in attributes
        $user = $request->getAttribute('auth_user');
        if ($user === null) {
            $db = $this->container->get(\OwnPay\Core\Database::class);
            $user = $db->fetchOne("SELECT * FROM op_merchant_users WHERE id = :id AND status = 'active'", ['id' => $userId]);
            if (!$user) {
                // Wipe the full session on invalid user
                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                $loginSlug = $this->resolveLoginSlug();
                return Response::redirect("/{$loginSlug}");
            }
            $request->setAttribute('auth_user', $user);
        }

        // Superadmin bypass — MySQL returns "1" (string), not true (bool)
        if (!empty($user['is_superadmin'])) {
            return $next($request);
        }

        // Get required permission from route path
        $requiredPermission = $this->resolvePermission($request->path(), $request->method());

        if ($requiredPermission === null) {
            // No permission required for this route
            return $next($request);
        }

        // Load user permissions from DB via role → role_permissions → permissions.
        // Previously $userPermissions was always [] because no upstream middleware populated it.
        $userPermissions = $request->getAttribute('user_permissions');
        if ($userPermissions === null) {
            $userPermissions = $this->loadPermissions((int) ($user['role_id'] ?? 0));
            $request->setAttribute('user_permissions', $userPermissions);
        }

        $allowed = in_array($requiredPermission, $userPermissions, true);

        if (!$allowed) {
            if ($request->expectsJson()) {
                return Response::json(['success' => false, 'message' => 'Insufficient permissions'], 403);
            }
            return Response::html('<h1>403 Forbidden</h1><p>You do not have permission to access this resource.</p>', 403);
        }

        return $next($request);
    }

    /**
     * Load permission slugs for a given role ID.
     *
     * @param int $roleId The role identifier.
     * @return string[] Array of permission slugs.
     */
    private function loadPermissions(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }

        try {
            $db = $this->container->get(\OwnPay\Core\Database::class);
            $rows = $db->fetchAll(
                "SELECT p.slug FROM op_role_permissions rp
                 JOIN op_permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = :rid",
                ['rid' => $roleId]
            );
            return array_column($rows, 'slug');
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Map route path to required permission slug.
     *
     * All POST operations consistently use '.manage' suffix.
     *
     * @param string $path The request path to evaluate.
     * @param string $method The HTTP request method.
     * @return string|null The required permission token slug or null if public.
     */
    private function resolvePermission(string $path, string $method): ?string
    {
        $map = [
            // Dashboard routes mapped to permission map
            '/admin/transactions'         => 'transactions.view',
            '/admin/invoices'             => 'invoices.view',
            '/admin/payment-links'        => 'payment_links.view',
            '/admin/customers'            => 'customers.view',
            '/admin/gateways'             => 'gateways.view',
            '/admin/staff'                => 'staff.view',
            '/admin/brands'               => 'brands.view',
            '/admin/settings'             => 'settings.view',
            '/admin/api-keys'             => 'api_keys.view',
            '/admin/sms-center'           => 'sms.view',
            '/admin/sms-data'             => 'sms.view',
            '/admin/devices'              => 'devices.view',
            '/admin/plugins'              => 'plugins.view',
            '/admin/themes'               => 'plugins.view',
            '/admin/addons'               => 'plugins.view',
            '/admin/system-update'        => 'system.update',
            '/admin/activities'           => 'system.audit',
            '/admin/audit-log'            => 'system.audit',
            '/admin/reports'              => 'system.reports',
            '/admin/domains'              => 'domains.view',
            '/admin/balance-verification' => 'system.balance',
            '/admin/roles'                => 'staff.view',
            '/admin/developer'            => 'api_keys.view',
            '/admin/faq'                  => 'settings.view',
            '/admin/ledger'               => 'system.reports',
            '/admin/currencies'           => 'settings.view',
            '/admin/my-account'           => 'admin.access',
            '/admin/fragment'             => 'dashboard.view',
            '/admin'                      => 'dashboard.view',
        ];

        // Brand switching is read-only, not brand management
        if ($path === '/admin/brands/switch') {
            return 'brands.view';
        }

        // Check exact match first
        if (isset($map[$path])) {
            $perm = $map[$path];
            if ($method === 'POST') {
                $perm = str_replace('.view', '.manage', $perm);
            }
            return $perm;
        }

        // Check prefix match — POST uses .manage
        foreach ($map as $prefix => $perm) {
            if (str_starts_with($path, $prefix)) {
                if ($method === 'POST') {
                    return str_replace('.view', '.manage', $perm);
                }
                return $perm;
            }
        }

        // Default-deny for unmapped /admin/* routes
        if (str_starts_with($path, '/admin')) {
            return 'system.unmapped';
        }

        return null;
    }

    /**
     * Resolve the dynamic login slug from settings database.
     *
     * Avoids hardcoded '/login' which fails when slug is customized.
     *
     * @return string The resolved login URI component.
     */
    private function resolveLoginSlug(): string
    {
        try {
            $settings = $this->container->get(\OwnPay\Repository\SettingsRepository::class);
            return $settings->get('landing', 'admin_login_slug', 'login');
        } catch (\Throwable) {
            return 'login';
        }
    }
}
