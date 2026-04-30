<?php
declare(strict_types=1);

namespace OwnPay\Security;

use OwnPay\Repository\LoginAttemptRepository;
use OwnPay\Repository\MerchantUserRepository;

/**
 * Authenticator — handles login, password verification, 2FA check.
 *
 * Per OWASP: Argon2id hashing, timing-safe compare, brute-force protection.
 * Per pci-compliance skill: constant-time password verify.
 */
final class Authenticator
{
    private MerchantUserRepository $users;
    private LoginAttemptRepository $attempts;

    public function __construct(MerchantUserRepository $users, LoginAttemptRepository $attempts)
    {
        $this->users = $users;
        $this->attempts = $attempts;
    }

    /**
     * Attempt login.
     *
     * @return array{success: bool, user?: array, error?: string, requires_2fa?: bool}
     */
    public function attempt(string $email, string $password, string $ip, string $userAgent): array
    {
        // Check brute-force lockout
        $maxAttempts = (int) (getenv('MAX_LOGIN_ATTEMPTS') ?: 5);
        $window = (int) (getenv('LOCKOUT_DURATION') ?: 300);
        $recentFails = $this->attempts->recentFailedCount($email, $ip, $window);

        if ($recentFails >= $maxAttempts) {
            return ['success' => false, 'error' => 'Account temporarily locked. Try again later.'];
        }

        $user = $this->users->findActiveByEmail($email);

        if ($user === null) {
            // Log failed attempt (constant time — don't reveal whether email exists)
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$dummy$dummy');
            $this->logAttempt($email, $ip, $userAgent, false);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            $this->logAttempt($email, $ip, $userAgent, false);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }

        // Check if 2FA required
        if ((bool) $user['two_factor_enabled']) {
            $this->logAttempt($email, $ip, $userAgent, true);
            return [
                'success'      => true,
                'requires_2fa' => true,
                'user'         => $user,
            ];
        }

        // Login success
        $this->logAttempt($email, $ip, $userAgent, true);
        $this->users->updateLastLogin((int) $user['id'], $ip);
        $this->startSession($user);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Hash a password using Argon2id (PCI-compliant).
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);
    }

    /**
     * Start authenticated session.
     */
    public function startSession(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['auth_user_id'] = $user['id'];
            $_SESSION['auth_merchant_id'] = $user['merchant_id'];
            $_SESSION['auth_role_id'] = $user['role_id'];
            $_SESSION['auth_email'] = $user['email'];
            $_SESSION['auth_at'] = time();
        }
    }

    /**
     * Destroy session (logout).
     */
    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']
                );
            }
            session_destroy();
        }
    }

    private function logAttempt(string $email, string $ip, string $userAgent, bool $success): void
    {
        $this->attempts->create([
            'email'      => $email,
            'ip_address' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 500),
            'success'    => $success ? 1 : 0,
        ]);
    }
}
