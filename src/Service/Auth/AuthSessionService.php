<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use OwnPay\Event\EventManager;
use OwnPay\Repository\MerchantUserRepository;
use OwnPay\Repository\RoleRepository;
use OwnPay\Security\Authenticator;

/**
 * OwnPay Authentication Session Service.
 *
 * Provides a high-level wrapper around the Authenticator, orchestrating
 * session validation, user registration lookups, permission resolution,
 * and dispatching authentication-related hook actions and filters.
 *
 * @package OwnPay\Service\Auth
 */
final class AuthSessionService
{
    /**
     * @var Authenticator The core authentication engine.
     */
    private Authenticator $auth;

    /**
     * @var MerchantUserRepository The repository handling merchant user records.
     */
    private MerchantUserRepository $users;

    /**
     * @var RoleRepository The repository managing user role assignments and permissions.
     */
    private RoleRepository $roles;

    /**
     * @var EventManager The system-wide event manager hook/filter dispatcher.
     */
    private EventManager $events;

    /**
     * AuthSessionService constructor.
     *
     * @param Authenticator $auth Core authentication security wrapper.
     * @param MerchantUserRepository $users Data repository for merchant users.
     * @param RoleRepository $roles RBAC roles and permissions repository.
     * @param EventManager $events Central application event dispatcher.
     */
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
     * Attempts to authenticate a user by credentials and client context.
     *
     * Fires pre-login filters ('auth.login.before') allowing third-party plugins
     * to block the authentication request, and registers success/failure event hooks
     * for auditing and security loggers.
     *
     * @param string $email The user's login email address.
     * @param string $password The plain-text password argument.
     * @param string $ip The client IP address triggering the request.
     * @param string $userAgent The client user agent header.
     * @return array{success: bool, user?: array<string, mixed>, error?: string, requires_2fa?: bool}
     */
    public function login(string $email, string $password, string $ip, string $userAgent): array
    {
        // Pre-login filter allows external policies or plugins to block login requests
        $allowed = $this->events->applyFilter('auth.login.before', true, $email, $ip);
        if ($allowed === false) {
            return ['success' => false, 'error' => 'Login blocked by policy'];
        }

        $result = $this->auth->attempt($email, $password, $ip, $userAgent);

        if (!$result['success']) {
            $this->events->doAction('auth.login.failed', $email, $ip);
            return $result;
        }

        if (isset($result['user'])) {
            $this->events->doAction('auth.login.success', $result['user'], $ip);
        }
        return $result;
    }

    /**
     * Terminates the current authenticated session.
     *
     * Invalidates all session data, clears authenticating cookie records,
     * and triggers 'auth.logout' events for auditing.
     *
     * @return void
     */
    public function logout(): void
    {
        $userId = $_SESSION['auth_user_id'] ?? null;
        Authenticator::logout();
        $this->events->doAction('auth.logout', $userId);
    }

    /**
     * Resolves the current authenticated user's profile details.
     *
     * @return array<string, mixed>|null The user record array, or null if unauthenticated.
     */
    public function currentUser(): ?array
    {
        $userId = $_SESSION['auth_user_id'] ?? null;
        if (!is_int($userId) && !is_string($userId)) {
            return null;
        }
        return $this->users->find($userId);
    }

    /**
     * Resolves the active permission list associated with the user's role.
     *
     * @return string[] The list of authorized permission keys.
     */
    public function currentPermissions(): array
    {
        $roleId = $_SESSION['auth_role_id'] ?? null;
        if (!is_scalar($roleId)) {
            return [];
        }
        return $this->roles->getPermissions((int) $roleId);
    }

    /**
     * Checks if a user is currently authenticated within the active session.
     *
     * @return bool True if authenticated; false otherwise.
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['auth_user_id']);
    }

    /**
     * Checks if the active user possesses superadmin bypass capabilities.
     *
     * @return bool True if the user is a superadmin; false otherwise.
     */
    public function isSuperadmin(): bool
    {
        return (bool) ($_SESSION['is_superadmin'] ?? false);
    }
}
