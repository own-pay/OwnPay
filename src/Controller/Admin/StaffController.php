<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Repository\MerchantUserRepository;

/**
 * Controller for managing brand-specific staff members and their roles.
 */
final class StaffController
{
    use AdminPageTrait;

    /**
     * The dependency injection container.
     */
    private Container $c;

    /**
     * The admin session manager.
     */
    private AdminSession $session;

    /**
     * The brand context service.
     */
    private BrandContext $brand;

    /**
     * The merchant user repository.
     */
    private MerchantUserRepository $userRepo;

    /**
     * StaffController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param AdminSession $session The admin session manager.
     * @param BrandContext $brand The brand context service.
     * @param MerchantUserRepository $userRepo The merchant user repository.
     */
    public function __construct(Container $c, AdminSession $session, BrandContext $brand, MerchantUserRepository $userRepo)
    {
        $this->c        = $c;
        $this->session  = $session;
        $this->brand    = $brand;
        $this->userRepo = $userRepo;
    }

    /**
     * List all staff members for the active brand or globally for superadmins.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with the rendered staff index page.
     * @throws \Exception If database queries fail.
     */
    public function index(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();

        if ($mid === null && !$this->brand->isGlobalView()) {
            $this->session->flashError('Select a brand first.');
            return Response::redirect('/admin');
        }

        $staff = $this->brand->isGlobalView()
            ? $this->userRepo->listAllStaff()
            : $this->userRepo->listStaffForMerchant((int) $mid);

        return $this->renderAdminPage('admin/staff/index.twig', ['staff' => $staff, 'active_page' => 'staff']);
    }

    /**
     * Handle staff creation flow (both GET form and POST submissions).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with form or redirect.
     * @throws \Exception If validation or creation fails.
     */
    public function create(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();

        if ($mid === null) {
            $this->session->flashError('Please select a specific brand to add staff to.');
            return Response::redirect('/admin/staff');
        }

        $roles = $this->getRolesForMerchant($mid);

        if ($req->method() === 'GET') {
            return $this->renderAdminPage('admin/staff/edit.twig', [
                'user' => null,
                'roles' => $roles,
                'available_permissions' => $this->getPermissions(),
                'active_page' => 'staff',
            ]);
        }

        $data = $req->post();
        $roleId = !empty($data['role_id']) ? (int) $data['role_id'] : null;

        // BUG-45 FIX: Validate role_id belongs to this brand.
        if ($roleId !== null) {
            $validRole = false;
            foreach ($roles as $r) {
                if ((int) $r['id'] === $roleId) {
                    $validRole = true;
                    break;
                }
            }
            if (!$validRole) {
                $this->session->flashError('Invalid role for this brand.');
                return Response::redirect('/admin/staff/create');
            }
        }

        // If no role selected, use default Staff role
        if ($roleId === null) {
            foreach ($roles as $r) {
                if ($r['slug'] === 'staff') {
                    $roleId = (int) $r['id'];
                    break;
                }
            }
        }

        // AUD-06 FIX: Validate required fields + password minimum length
        $name     = InputSanitizer::string($data['name'] ?? '');
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if ($name === '' || $email === '') {
            $this->session->flashError('Name and email are required.');
            return Response::redirect('/admin/staff/create');
        }
        if (strlen($password) < 8) {
            $this->session->flashError('Password must be at least 8 characters.');
            return Response::redirect('/admin/staff/create');
        }

        // AUD-05 FIX: Pass resolved $roleId to createStaff
        $this->userRepo->createStaff(
            $mid,
            $name,
            $email,
            password_hash($password, PASSWORD_ARGON2ID),
            $roleId
        );

        $this->session->flashSuccess('Staff created');
        return Response::redirect('/admin/staff');
    }

    /**
     * Handle staff editing flow (both GET form and POST update submissions).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with form or redirect.
     * @throws \Exception If lookup or update fails.
     */
    public function edit(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();
        $id  = (int) $req->param('id');

        $merchantScope = $this->brand->isGlobalView() ? null : $mid;
        $user = $this->userRepo->findStaff($id, $merchantScope);

        if (!$user) {
            $this->session->flashError('Not found');
            return Response::redirect('/admin/staff');
        }

        $roles = $this->getRolesForMerchant($user['merchant_id'] ?? $mid);

        if ($req->method() === 'GET') {
            return $this->renderAdminPage('admin/staff/edit.twig', [
                'user' => $user,
                'roles' => $roles,
                'available_permissions' => $this->getPermissions(),
                'active_page' => 'staff',
            ]);
        }

        $data   = $req->post();
        $update = ['name' => InputSanitizer::string($data['name'] ?? ''), 'email' => $data['email'] ?? ''];
        if (!empty($data['password'])) {
            $update['password_hash'] = password_hash($data['password'], PASSWORD_ARGON2ID);
        }
        if (!empty($data['role_id'])) {
            // BUG-45 FIX: Validate role_id belongs to this user's brand.
            $newRoleId = (int) $data['role_id'];
            $validRole = false;
            foreach ($roles as $r) {
                if ((int) $r['id'] === $newRoleId) {
                    $validRole = true;
                    break;
                }
            }
            if ($validRole) {
                $update['role_id'] = $newRoleId;
            }
        }

        $this->userRepo->updateStaff($id, $update, $merchantScope);
        $this->session->flashSuccess('Staff updated');
        return Response::redirect('/admin/staff');
    }

    /**
     * Alias endpoint to handle POST store actions.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If staff creation fails.
     */
    public function store(Request $req): Response { return $this->create($req); }

    /**
     * Alias endpoint to handle GET show actions.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with the edit form.
     * @throws \Exception If staff lookup fails.
     */
    public function show(Request $req): Response { return $this->edit($req); }

    /**
     * Alias endpoint to handle POST update actions.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If staff update fails.
     */
    public function update(Request $req): Response { return $this->edit($req); }

    /**
     * Delete a staff member.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If deletion fails.
     */
    public function delete(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();
        $id  = (int) $req->param('id');

        $merchantScope = $this->brand->isGlobalView() ? null : $mid;
        $this->userRepo->deleteStaff($id, $merchantScope);

        $this->session->flashSuccess('Staff deleted');
        return Response::redirect('/admin/staff');
    }

    /**
     * Resolve roles configured under a specific merchant.
     *
     * @param int $merchantId The merchant ID.
     * @return array<int, array<string, mixed>> The list of roles.
     * @throws \Exception If DB query fails.
     */
    private function getRolesForMerchant(int $merchantId): array
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        return $db->fetchAll(
            "SELECT id, name, slug FROM op_roles WHERE merchant_id = :mid ORDER BY id",
            ['mid' => $merchantId]
        );
    }

    /**
     * Resolve global static permissions whitelist.
     *
     * @return string[] The list of permissions.
     */
    private function getPermissions(): array
    {
        return [
            'transactions.view', 'transactions.manage', 'invoices.view', 'invoices.manage',
            'payment_links.view', 'payment_links.manage', 'customers.view', 'customers.manage',
            'gateways.view', 'gateways.manage', 'staff.view', 'staff.manage',
            'settings.view', 'settings.manage', 'reports.view', 'sms.view',
            'devices.view', 'devices.manage', 'domains.view', 'domains.manage',
        ];
    }
}
