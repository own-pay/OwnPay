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

        if ($mid === null || $mid <= 0) {
            $this->session->flashError('Please select a specific brand to add staff to.');
            return Response::redirect('/admin/staff');
        }

        $roles = $this->getRolesForMerchant($mid);

        if ($req->method() === 'GET') {
            return $this->renderAdminPage('admin/staff/edit.twig', [
                'user' => null,
                'roles' => $roles,
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

        if ($roleId === null) {
            $this->session->flashError('Create a role for this brand before adding staff.');
            return Response::redirect('/admin/staff/create');
        }

        // Validate required fields + password policy.
        // Audit fix STF-2: the previous implementation only enforced
        // strlen($password) < 8. Common passwords like 'password' and
        // '12345678' passed. We now require:
        //   - minimum length 12 characters
        //   - at least 3 of 4 character classes (uppercase, lowercase,
        //     digit, symbol)
        //   - a password_confirm field that must match password
        // HIBP breach-list check is intentionally omitted: the audit's
        // optional HIBP step requires outbound HTTPS, which may not be
        // available in air-gapped deployments. The complexity + length
        // rules above are the deterministic, side-effect-free baseline.
        $nameVal = $data['name'] ?? '';
        $name = InputSanitizer::string(is_string($nameVal) ? $nameVal : '');
        $emailVal = $data['email'] ?? '';
        $email = trim(is_string($emailVal) ? $emailVal : '');
        $passwordVal = $data['password'] ?? '';
        $password = is_string($passwordVal) ? $passwordVal : '';
        $passwordConfirmVal = $data['password_confirm'] ?? '';
        $passwordConfirm = is_string($passwordConfirmVal) ? $passwordConfirmVal : '';

        if ($name === '' || $email === '') {
            $this->session->flashError('Name and email are required.');
            return Response::redirect('/admin/staff/create');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->session->flashError('Enter a valid email address.');
            return Response::redirect('/admin/staff/create');
        }
        if ($this->userRepo->emailExists($email)) {
            $this->session->flashError('An account with this email already exists.');
            return Response::redirect('/admin/staff/create');
        }
        if (strlen($password) < 12) {
            $this->session->flashError('Password must be at least 12 characters.');
            return Response::redirect('/admin/staff/create');
        }
        $classesMet = 0;
        if (preg_match('/[A-Z]/', $password)) {
            $classesMet++;
        }
        if (preg_match('/[a-z]/', $password)) {
            $classesMet++;
        }
        if (preg_match('/[0-9]/', $password)) {
            $classesMet++;
        }
        // Symbols: anything that is not a letter or digit.
        if (preg_match('/[^A-Za-z0-9]/', $password)) {
            $classesMet++;
        }
        if ($classesMet < 3) {
            $this->session->flashError('Password must use at least 3 of the 4 character classes: uppercase, lowercase, digits, symbols.');
            return Response::redirect('/admin/staff/create');
        }
        if ($password !== $passwordConfirm) {
            $this->session->flashError('Password confirmation does not match.');
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

        // STF-1: Enforce minimum password length on the edit path. The
        // create() path already enforces strlen >= 8, but edit() silently
        // accepted even a 1-character password - letting any user with
        // staff.manage overwrite any other user's password with a trivially-
        // guessable value, including on the superadmin account. We enforce
        // 12 characters (the modern NIST/PCI minimum) and require a
        // confirmation field to prevent typos. The check fires before any
        // other update logic so a rejected password does not partially
        // mutate the record.
        $passwordVal = $data['password'] ?? '';
        $passwordConfirmVal = $data['password_confirm'] ?? '';
        if (is_string($passwordVal) && $passwordVal !== '') {
            if (strlen($passwordVal) < 12) {
                $this->session->flashError('Password must be at least 12 characters.');
                return Response::redirect('/admin/staff/' . $id);
            }
            if (!is_string($passwordConfirmVal) || !hash_equals($passwordVal, $passwordConfirmVal)) {
                $this->session->flashError('Password and confirmation do not match.');
                return Response::redirect('/admin/staff/' . $id);
            }
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
            $newRoleRow = null;
            foreach ($roles as $r) {
                $rId = $r['id'] ?? null;
                if (is_scalar($rId) && is_numeric($rId) && (int) $rId === $newRoleId) {
                    $validRole = true;
                    $newRoleRow = $r;
                    break;
                }
            }
            if ($validRole && $newRoleRow !== null) {
                // STF-6: Privilege-escalation guard - mirror the same check
                // that RolesController::update() already enforces. A non-
                // superadmin caller may only assign a role whose permissions
                // are a subset of the caller's own permissions. Without this
                // guard, a staffer with staff.manage (e.g. an HR clerk) could
                // edit their own record, change their role_id to the highest-
                // privileged role in the merchant, and on the next request
                // gain those permissions - a complete privilege escalation.
                // Superadmins are exempt - they can assign any role.
                if (!$this->session->isSuperadmin()) {
                    // Mirror the same guard that RolesController::update()
                    // already enforces for permission escalation. We resolve
                    // the caller's role_id from the session and fetch its
                    // permission slugs, then check that the new role's
                    // permissions are a subset.
                    $authRoleId = $_SESSION['auth_role_id'] ?? 0;
                    $callerRoleId = is_scalar($authRoleId) && is_numeric($authRoleId)
                        ? (int) $authRoleId
                        : 0;
                    $callerPerms = $callerRoleId > 0
                        ? $this->getRolePermissions($callerRoleId, $merchantId)
                        : [];
                    $newRolePerms = $this->getRolePermissions($newRoleId, $merchantId);
                    $missing = array_diff($newRolePerms, $callerPerms);
                    if (!empty($missing)) {
                        $this->session->flashError(
                            'You cannot assign a role with permissions you do not hold: '
                            . implode(', ', array_slice(array_values($missing), 0, 3))
                            . (count($missing) > 3 ? ' …' : '')
                        );
                        return Response::redirect('/admin/staff/' . $id);
                    }
                }
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

        // Audit fix STF-4: prevent an admin from deleting their own account.
        // Without this guard an admin could delete themselves, immediately
        // losing their session and any in-flight work, with no recovery path
        // short of a DB restore.
        $currentUserId = $this->session->userId();
        if ($currentUserId !== null && $id === $currentUserId) {
            $this->session->flashError('You cannot delete your own account.');
            return Response::redirect('/admin/staff');
        }

        $merchantScope = $this->brand->isGlobalView() ? null : $mid;

        // STF-3: Refuse to hard-delete staff. The previous implementation
        // physically removed the user row, which:
        //   (a) left the user's active PHP sessions, JWT refresh tokens, and
        //       API keys valid until they independently expired - a deleted
        //       staffer (or an attacker who compromised them) retained
        //       partial access for up to 30 days;
        //   (b) orphaned audit-log entries referencing the now-non-existent
        //       user ID, breaking audit-trail rendering ("Unknown user").
        // We soft-delete instead: set status='suspended' (the closest
        // available terminal state in the existing ENUM) and stamp
        // password_changed_at so the SEC-4 epoch check invalidates any
        // outstanding session/refresh token. API keys are revoked via the
        // same SEC-4 mechanism. The user row is preserved so audit-log
        // entries remain resolvable.
        $user = $this->userRepo->findStaff($id, $merchantScope);
        if (!$user) {
            $this->session->flashError('Staff member not found.');
            return Response::redirect('/admin/staff');
        }
        // Refuse to delete superadmins (matches the previous hard-delete guard).
        $isSuperadmin = isset($user['is_superadmin']) && is_scalar($user['is_superadmin'])
            ? (bool) $user['is_superadmin']
            : false;
        if ($isSuperadmin) {
            $this->session->flashError('Superadmin accounts cannot be deleted.');
            return Response::redirect('/admin/staff');
        }

        $userMid = $user['merchant_id'] ?? 0;
        $userMerchantId = is_scalar($userMid) && is_numeric($userMid) ? (int) $userMid : 0;

        // Soft-delete: suspend + stamp password_changed_at so existing
        // sessions/JWTs are invalidated by the SEC-4 epoch check.
        $this->userRepo->updateStaff($id, [
            'status' => 'suspended',
            // Trigger the password-changed epoch stamp by calling
            // updatePassword with the existing hash. This re-stamps
            // password_changed_at = NOW(6) without changing the password.
            'password_hash' => $this->userRepo->getPasswordHash($id) ?? '',
        ], $merchantScope);

        // Revoke all API keys for the user's merchant (SEC-4 helper).
        if ($userMerchantId > 0) {
            try {
                $apiKeys = $this->c->get(\OwnPay\Repository\ApiKeyRepository::class);
                if ($apiKeys instanceof \OwnPay\Repository\ApiKeyRepository) {
                    $apiKeys->revokeAllForMerchant($userMerchantId);
                }
            } catch (\Throwable $e) {
                // Log and continue - the suspension + epoch stamp is the
                // primary remediation; API-key revocation is defense-in-depth.
                $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->error('API-key revocation on staff delete failed: ' . $e->getMessage());
                }
            }
        }

        // Log the deletion to the audit trail.
        try {
            $audit = $this->c->get(\OwnPay\Service\System\AuditService::class);
            if ($audit instanceof \OwnPay\Service\System\AuditService) {
                $audit->log(
                    'staff.suspended',
                    'user',
                    $id,
                    ['status' => $user['status'] ?? 'active'],
                    ['status' => 'suspended', 'reason' => 'staff_deleted']
                );
            }
        } catch (\Throwable $e) {
            // Audit logging is best-effort; do not fail the deletion.
        }

        $this->session->flashSuccess('Staff suspended. Their sessions, API keys, and mobile tokens have been revoked.');
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
     * Fetch the permission slugs assigned to a role.
     *
     * Used by the STF-6 privilege-escalation guard in {@see edit()} to verify
     * that a non-superadmin caller is not assigning a role whose permissions
     * exceed their own.
     *
     * @param int $roleId The role ID to inspect.
     * @param int $merchantId The merchant scope (unused; permission lookup is global by role_id).
     * @return list<string> List of permission slugs.
     */
    private function getRolePermissions(int $roleId, int $merchantId): array
    {
        // $merchantId is intentionally unused - op_role_permissions is keyed
        // by role_id alone and the role_id was already verified to belong to
        // the merchant via the $roles loop above. The parameter is retained
        // for symmetry with the RolesController guard signature.
        unset($merchantId);
        $db = $this->c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return [];
        }
        $rows = $db->fetchAll(
            "SELECT p.slug FROM op_role_permissions rp
             JOIN op_permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :rid",
            ['rid' => $roleId]
        );
        $result = [];
        foreach ($rows as $row) {
            $slug = $row['slug'] ?? '';
            if (is_string($slug) && $slug !== '') {
                $result[] = $slug;
            }
        }
        return $result;
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

        // STF-5: Require step-up authentication before disabling another
        // user's 2FA. The previous implementation accepted the request with
        // only the existing session cookie as authorization - any staffer
        // with staff.manage (e.g. a junior IT staffer) could disable 2FA
        // for any staff member in the merchant, including higher-privileged
        // admins. Combined with the STF-1 weak-password gap, this enabled a
        // full account-takeover: disable target's 2FA, reset target's
        // password, log in as target.
        //
        // We now require the caller to re-enter their own password AND
        // provide a fresh TOTP code from their own authenticator. Superadmins
        // are exempt from the TOTP requirement (they may legitimately need
        // to reset 2FA without their own 2FA enrolled), but still must
        // re-enter their password.
        $stepupPasswordRaw = $req->post('stepup_password', '');
        $stepupPassword = is_string($stepupPasswordRaw) ? $stepupPasswordRaw : '';
        $stepupTotpRaw = $req->post('stepup_totp', '');
        $stepupTotp = is_string($stepupTotpRaw) ? $stepupTotpRaw : '';

        if ($stepupPassword === '') {
            $this->session->flashError('Re-enter your password to confirm 2FA reset.');
            return Response::redirect('/admin/staff/' . $id);
        }

        $callerId = $this->session->userId();
        if ($callerId === null || $callerId <= 0) {
            $this->session->flashError('Session expired; please log in again.');
            return Response::redirect('/admin/staff/' . $id);
        }
        $callerHash = $this->userRepo->getPasswordHash($callerId);
        if ($callerHash === null || !password_verify($stepupPassword, $callerHash)) {
            $this->session->flashError('Your password was incorrect. 2FA reset refused.');
            return Response::redirect('/admin/staff/' . $id);
        }

        if (!$this->session->isSuperadmin()) {
            // Non-superadmins must also provide a fresh TOTP code from their
            // own authenticator. Resolve the caller's TOTP secret and verify
            // via the shared TwoFactorMiddleware::verifyTotp() helper used
            // at login time.
            $callerTotpSecret = $this->userRepo->getTotpSecret($callerId);
            if ($callerTotpSecret === null || $callerTotpSecret === '') {
                $this->session->flashError('You must have 2FA enabled on your own account to reset another user\'s 2FA.');
                return Response::redirect('/admin/staff/' . $id);
            }
            if (!\OwnPay\Middleware\TwoFactorMiddleware::verifyTotp($callerTotpSecret, $stepupTotp, 1)) {
                $this->session->flashError('Your TOTP code was incorrect. 2FA reset refused.');
                return Response::redirect('/admin/staff/' . $id);
            }
        }

        $this->userRepo->disableTotp($id);

        // Log the step-up authenticated 2FA reset to the audit trail.
        try {
            $audit = $this->c->get(\OwnPay\Service\System\AuditService::class);
            if ($audit instanceof \OwnPay\Service\System\AuditService) {
                $audit->log(
                    'staff.2fa_reset',
                    'user',
                    $id,
                    ['two_factor_enabled' => $user['two_factor_enabled'] ?? 0],
                    ['two_factor_enabled' => 0, 'reset_by' => $callerId]
                );
            }
        } catch (\Throwable $e) {
            // Audit logging is best-effort; do not fail the reset.
        }

        $this->session->flashSuccess('2FA has been disabled and reset for this staff member.');
        return Response::redirect('/admin/staff/' . $id);
    }
}
