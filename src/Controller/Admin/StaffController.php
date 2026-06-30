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

        $postData = $req->post();
        $data = is_array($postData) ? $postData : [];
        $roleIdVal = $data['role_id'] ?? null;
        $roleId = is_scalar($roleIdVal) && is_numeric($roleIdVal) ? (int) $roleIdVal : null;

        // BUG-45 FIX: Validate role_id belongs to this brand.
        if ($roleId !== null) {
            $validRole = false;
            foreach ($roles as $r) {
                $rId = $r['id'] ?? null;
                if (is_scalar($rId) && is_numeric($rId) && (int) $rId === $roleId) {
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
                $rId = $r['id'] ?? null;
                if (($r['slug'] ?? null) === 'staff' && is_scalar($rId) && is_numeric($rId)) {
                    $roleId = (int) $rId;
                    break;
                }
            }
        }

        // Validate required fields + password minimum length
        $nameVal = $data['name'] ?? '';
        $name = InputSanitizer::string(is_string($nameVal) ? $nameVal : '');
        $emailVal = $data['email'] ?? '';
        $email = trim(is_string($emailVal) ? $emailVal : '');
        $passwordVal = $data['password'] ?? '';
        $password = is_string($passwordVal) ? $passwordVal : '';

        if ($name === '' || $email === '') {
            $this->session->flashError('Name and email are required.');
            return Response::redirect('/admin/staff/create');
        }
        if (strlen($password) < 8) {
            $this->session->flashError('Password must be at least 8 characters.');
            return Response::redirect('/admin/staff/create');
        }

        $usernameVal = $data['username'] ?? '';
        $username = trim(is_string($usernameVal) ? $usernameVal : '');
        if ($username === '') {
            $username = null;
        }
        $phoneVal = $data['phone'] ?? '';
        $phone = trim(is_string($phoneVal) ? $phoneVal : '');
        if ($phone === '') {
            $phone = null;
        }
        $statusVal = $data['status'] ?? 'active';
        $status = is_string($statusVal) && in_array($statusVal, ['active', 'suspended', 'pending'], true) ? $statusVal : 'active';

        $avatarPath = null;
        $avatarFile = $req->file('avatar');
        if (
            is_array($avatarFile)
            && isset($avatarFile['error'], $avatarFile['name'], $avatarFile['tmp_name'])
            && is_int($avatarFile['error'])
            && is_string($avatarFile['name'])
            && is_string($avatarFile['tmp_name'])
            && $avatarFile['error'] === UPLOAD_ERR_OK
        ) {
            try {
                $fs = new \OwnPay\Service\System\FilesystemService(dirname(__DIR__, 3) . '/public/assets');
                $storedPath = $fs->storeUpload($avatarFile, 'uploads/avatars');
                $avatarPath = '/assets/' . $storedPath;
            } catch (\Throwable $e) {
                $this->session->flashError('Invalid file for staff avatar: ' . $e->getMessage());
            }
        }

        // Pass resolved $roleId to createStaff
        $this->userRepo->createStaff(
            $mid,
            $name,
            $email,
            password_hash($password, PASSWORD_ARGON2ID),
            $roleId,
            $username,
            $phone,
            $status,
            $avatarPath
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

        $userMid = $user['merchant_id'] ?? $mid;
        $merchantId = is_scalar($userMid) && is_numeric($userMid) ? (int) $userMid : 0;
        $roles = $this->getRolesForMerchant($merchantId);

        if ($req->method() === 'GET') {
            return $this->renderAdminPage('admin/staff/edit.twig', [
                'user' => $user,
                'roles' => $roles,
                'available_permissions' => $this->getPermissions(),
                'active_page' => 'staff',
            ]);
        }

        $postData = $req->post();
        $data = is_array($postData) ? $postData : [];
        $nameVal = $data['name'] ?? '';
        $emailVal = $data['email'] ?? '';
        $usernameVal = $data['username'] ?? '';
        $username = trim(is_string($usernameVal) ? $usernameVal : '');
        $phoneVal = $data['phone'] ?? '';
        $phone = trim(is_string($phoneVal) ? $phoneVal : '');
        $statusVal = $data['status'] ?? 'active';
        $status = is_string($statusVal) && in_array($statusVal, ['active', 'suspended', 'pending'], true) ? $statusVal : 'active';

        $update = [
            'name' => InputSanitizer::string(is_string($nameVal) ? $nameVal : ''),
            'email' => is_string($emailVal) ? $emailVal : '',
            'username' => $username !== '' ? $username : null,
            'phone' => $phone !== '' ? $phone : null,
            'status' => $status,
        ];
        
        $passwordVal = $data['password'] ?? '';
        if (is_string($passwordVal) && $passwordVal !== '') {
            $update['password_hash'] = password_hash($passwordVal, PASSWORD_ARGON2ID);
        }

        $avatarFile = $req->file('avatar');
        if (
            is_array($avatarFile)
            && isset($avatarFile['error'], $avatarFile['name'], $avatarFile['tmp_name'])
            && is_int($avatarFile['error'])
            && is_string($avatarFile['name'])
            && is_string($avatarFile['tmp_name'])
            && $avatarFile['error'] === UPLOAD_ERR_OK
        ) {
            try {
                $fs = new \OwnPay\Service\System\FilesystemService(dirname(__DIR__, 3) . '/public/assets');
                $storedPath = $fs->storeUpload($avatarFile, 'uploads/avatars');
                $update['avatar_path'] = '/assets/' . $storedPath;
            } catch (\Throwable $e) {
                $this->session->flashError('Invalid file for staff avatar: ' . $e->getMessage());
            }
        }
        
        $roleIdVal = $data['role_id'] ?? null;
        if ($roleIdVal !== null && is_scalar($roleIdVal) && is_numeric($roleIdVal)) {
            $newRoleId = (int) $roleIdVal;
            $validRole = false;
            foreach ($roles as $r) {
                $rId = $r['id'] ?? null;
                if (is_scalar($rId) && is_numeric($rId) && (int) $rId === $newRoleId) {
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
        if ($db instanceof \OwnPay\Core\Database) {
            return $db->fetchAll(
                "SELECT id, name, slug FROM op_roles WHERE merchant_id = :mid ORDER BY id",
                ['mid' => $merchantId]
            );
        }
        return [];
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

    /**
     * Resets/disables 2FA for a staff member.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     */
    public function reset2fa(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();
        $id  = (int) $req->param('id');

        $merchantScope = $this->brand->isGlobalView() ? null : $mid;
        $user = $this->userRepo->findStaff($id, $merchantScope);

        if (!$user) {
            $this->session->flashError('Staff member not found.');
            return Response::redirect('/admin/staff');
        }

        $this->userRepo->disableTotp($id);
        $this->session->flashSuccess('2FA has been disabled and reset for this staff member.');
        return Response::redirect('/admin/staff/' . $id);
    }
}
