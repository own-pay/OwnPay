<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\RoleRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Support\DateHelper;

/**
 * Class RolesController
 *
 * Administrative controller managing roles and permissions (RBAC) scoped strictly to
 * the active merchant brand context. Handles roles creation, editing, syncing permissions,
 * and deleting custom roles.
 *
 * @package OwnPay\Controller\Admin
 */
final class RolesController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session service.
     */
    private AdminSession $session;

    /**
     * @var RoleRepository The roles and permissions repository.
     */
    private RoleRepository $roles;

    /**
     * RolesController constructor.
     *
     * @param Container    $c       The dependency injection container.
     * @param AdminSession $session The administrative session service.
     * @param RoleRepository $roles The roles and permissions repository.
     */
    public function __construct(Container $c, AdminSession $session, RoleRepository $roles)
    {
        $this->c       = $c;
        $this->session = $session;
        $this->roles   = $roles;
    }

    /**
     * Displays all roles along with permission counts and permission configuration matrix.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The roles listing page response.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        $mid = 0;
        if ($brand instanceof BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }

        $rolesData = $this->roles->forTenant($mid)->paginateScoped(1, 100);
        $roles = isset($rolesData['items']) && is_array($rolesData['items']) ? $rolesData['items'] : [];

        // Enrich with permission count
        foreach ($roles as &$r) {
            if (is_array($r)) {
                $rId = $r['id'] ?? null;
                $perms = is_scalar($rId) && is_numeric($rId) ? $this->roles->getPermissions((int) $rId) : [];
                $r['permission_count'] = count($perms);
                $r['permissions']      = $perms;
            }
        }
        unset($r);

        // Load all available permissions (grouped)
        $allPerms = $this->loadAllPermissions();

        return $this->renderAdminPage('admin/roles/index.twig', [
            'roles'       => $roles,
            'permissions' => $allPerms,
            'active_page' => 'roles',
        ]);
    }

    /**
     * Creates a new brand-scoped role.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function store(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        $mid = 0;
        if ($brand instanceof BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }

        if ($guard = $this->requireActiveBrand($mid, '/admin/roles')) {
            return $guard;
        }

        $nameRaw = $req->post('name') ?? '';
        $name = trim(is_string($nameRaw) ? $nameRaw : '');
        $descRaw = $req->post('description') ?? '';
        $desc = trim(is_string($descRaw) ? $descRaw : '');

        if ($name === '') {
            $this->session->flashError('Role name is required');
            return Response::redirect('/admin/roles');
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?: 'role');

        // Check duplicate
        $existing = $this->roles->forTenant($mid)->findBySlug($slug);
        if ($existing !== null) {
            $this->session->flashError("Role '{$name}' already exists");
            return Response::redirect('/admin/roles');
        }

        $this->roles->forTenant($mid)->createScoped([
            'name'        => $name,
            'slug'        => $slug,
            'description' => $desc,
            'is_system'   => 0,
            'created_at'  => DateHelper::now(),
        ]);

        $this->session->flashSuccess("Role '{$name}' created");
        return Response::redirect('/admin/roles');
    }

    /**
     * Updates an existing role and synchronizes its permissions, preventing privilege escalation.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function update(Request $req): Response
    {
        $id    = (int) $req->param('id');
        $brand = $this->c->get(BrandContext::class);
        $mid = 0;
        if ($brand instanceof BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }

        $role = $this->roles->forTenant($mid)->findScoped($id);
        if ($role === null) {
            $this->session->flashError('Role not found');
            return Response::redirect('/admin/roles');
        }

        // Update name/description
        $roleName = is_string($role['name'] ?? null) ? $role['name'] : '';
        $roleDesc = is_string($role['description'] ?? null) ? $role['description'] : '';
        $nameRaw = $req->post('name') ?? $roleName;
        $name = trim(is_string($nameRaw) ? $nameRaw : '');
        $descRaw = $req->post('description') ?? $roleDesc;
        $desc = trim(is_string($descRaw) ? $descRaw : '');
        $this->roles->forTenant($mid)->updateScoped($id, [
            'name'        => $name,
            'description' => $desc,
        ]);

        // Sync permissions
        $permsInput = $req->post('permissions');
        $permsArray = is_array($permsInput) ? $permsInput : [];
        $permIds = [];
        foreach ($permsArray as $p) {
            if (is_scalar($p) && is_numeric($p)) {
                $permIds[] = (int) $p;
            }
        }

        // Prevent privilege escalation - non-superadmins can only
        // assign permissions they themselves hold.
        $isSuperadmin = !empty($_SESSION['is_superadmin']);
        if (!$isSuperadmin && !empty($permIds)) {
            $authRoleId = $_SESSION['auth_role_id'] ?? 0;
            $userRoleId = is_scalar($authRoleId) && is_numeric($authRoleId) ? (int) $authRoleId : 0;
            $db = $this->c->get(\OwnPay\Core\Database::class);
            if ($db instanceof \OwnPay\Core\Database) {
                $rows = $db->fetchAll(
                    "SELECT permission_id FROM op_role_permissions WHERE role_id = :rid",
                    ['rid' => $userRoleId]
                );
                $userPermIds = array_map(fn($r) => isset($r['permission_id']) && is_numeric($r['permission_id']) ? (int) $r['permission_id'] : 0, $rows);
                $unauthorized = array_diff($permIds, $userPermIds);
                if (!empty($unauthorized)) {
                    $this->session->flashError('Cannot assign permissions you do not hold');
                    return Response::redirect('/admin/roles');
                }
            }
        }

        $this->roles->syncPermissions($id, $permIds);

        $this->session->flashSuccess("Role '{$name}' updated");
        return Response::redirect('/admin/roles');
    }

    /**
     * Deletes a custom role.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function delete(Request $req): Response
    {
        $id    = (int) $req->param('id');
        $brand = $this->c->get(BrandContext::class);
        $mid = 0;
        if ($brand instanceof BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }

        $role = $this->roles->forTenant($mid)->findScoped($id);
        if ($role === null) {
            $this->session->flashError('Role not found');
            return Response::redirect('/admin/roles');
        }

        if (!empty($role['is_system'])) {
            $this->session->flashError('Cannot delete system roles');
            return Response::redirect('/admin/roles');
        }

        $roleName = is_string($role['name'] ?? null) ? $role['name'] : 'Unknown';
        $this->roles->forTenant($mid)->deleteScoped($id);
        $this->session->flashSuccess("Role '{$roleName}' deleted");
        return Response::redirect('/admin/roles');
    }

    /**
     * Loads all system permissions, grouped by category/group name.
     *
     * @return array<string, array<int, array<string, mixed>>> Grouped permissions mapping.
     */
    private function loadAllPermissions(): array
    {
        $db   = $this->roles->getDatabase();
        $rows = $db->fetchAll("SELECT * FROM op_permissions ORDER BY group_name, slug");
        $grouped = [];
        foreach ($rows as $r) {
            $groupName = is_string($r['group_name'] ?? null) ? $r['group_name'] : 'general';
            $grouped[$groupName][] = $r;
        }
        return $grouped;
    }
}
