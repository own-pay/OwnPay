<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use OwnPay\Event\EventManager;
use OwnPay\Repository\MerchantUserRepository;
use OwnPay\Repository\RoleRepository;
use OwnPay\Security\Authenticator;

/**
 * Auth session service — wraps Authenticator + fires auth hooks.
 */
final class AuthSessionService
{
    private Authenticator $auth;
    private MerchantUserRepository $users;
    private RoleRepository $roles;
    private EventManager $events;

    public function __construct(
        Authenticator $auth,
        MerchantUserRepository $users,
        RoleRepository $roles,
        EventManager $events
    ) {
        $this->auth = $auth;
        $this->users = $users;
        $this->roles = $roles;
        $this->events = $events;
    }

    /**
     * @return array{success: bool, user?: array, error?: string, requires_2fa?: bool}
     */
    public function login(string $email, string $password, string $ip, string $userAgent): array
    {
        // Pre-login filter — plugins can block
        $allowed = $this->events->applyFilter('auth.login.before', true, $email, $ip);
        if ($allowed === false) {
            return ['success' => false, 'error' => 'Login blocked by policy'];
        }

        $result = $this->auth->attempt($email, $password, $ip, $userAgent);

        if (!$result['success']) {
            $this->events->doAction('auth.login.failed', $email, $ip);
            return $result;
        }

        $this->events->doAction('auth.login.success', $result['user'], $ip);
        return $result;
    }

    public function logout(): void
    {
        $userId = $_SESSION['auth_user_id'] ?? null;
        Authenticator::logout();
        $this->events->doAction('auth.logout', $userId);
    }

    /**
     * Get current authenticated user from session.
     */
    public function currentUser(): ?array
    {
        $userId = $_SESSION['auth_user_id'] ?? null;
        if ($userId === null) {
            return null;
        }
        return $this->users->find($userId);
    }

    /**
     * Get permissions for current user.
     * @return string[]
     */
    public function currentPermissions(): array
    {
        $roleId = $_SESSION['auth_role_id'] ?? null;
        if ($roleId === null) {
            return [];
        }
        return $this->roles->getPermissions((int) $roleId);
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION['auth_user_id']);
    }
}
