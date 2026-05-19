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

final class StaffController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private BrandContext $brand;
    private MerchantUserRepository $userRepo;

    public function __construct(Container $c, AdminSession $session, BrandContext $brand, MerchantUserRepository $userRepo)
    {
        $this->c        = $c;
        $this->session  = $session;
        $this->brand    = $brand;
        $this->userRepo = $userRepo;
    }

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
            : $this->userRepo->listStaffForMerchant($mid);

        return $this->renderAdminPage('admin/staff/index.twig', ['staff' => $staff, 'active_page' => 'staff']);
    }

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
            $update['role_id'] = (int) $data['role_id'];
        }

        $this->userRepo->updateStaff($id, $update, $merchantScope);
        $this->session->flashSuccess('Staff updated');
        return Response::redirect('/admin/staff');
    }

    public function store(Request $req): Response { return $this->create($req); }
    public function show(Request $req): Response { return $this->edit($req); }
    public function update(Request $req): Response { return $this->edit($req); }

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

    private function getRolesForMerchant(int $merchantId): array
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        return $db->fetchAll(
            "SELECT id, name, slug FROM op_roles WHERE merchant_id = :mid ORDER BY id",
            ['mid' => $merchantId]
        );
    }

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
