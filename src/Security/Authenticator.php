<?php
declare(strict_types=1);

namespace OwnPay\Security;

use OwnPay\Repository\LoginAttemptRepository;
use OwnPay\Repository\MerchantUserRepository;
use OwnPay\Event\EventManager;

/**
 * Authenticator — handles login, password verification, 2FA check.
 *
 * Per OWASP: Argon2id hashing, timing-safe compare, brute-force protection.
 * Per pci-compliance skill: constant-time password verify.
 */
final class Authenticator
{
    private ?MerchantUserRepository $users;
    private ?LoginAttemptRepository $attempts;
    private ?EventManager $events;

    public function __construct(
        ?MerchantUserRepository $users = null,
        ?LoginAttemptRepository $attempts = null,
        ?EventManager $events = null
    ) {
        $this->users = $users;
        $this->attempts = $attempts;
        $this->events = $events;
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
            $this->events->doAction('auth.login.failed', $email, $ip);
            return ['success' => false, 'error' => 'Account temporarily locked. Try again later.'];
        }

        $user = $this->users->findActiveByLogin($email);

        if ($user === null) {
            // Log failed attempt (constant time — don't reveal whether account exists)
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$dummy$dummy');
            $this->logAttempt($email, $ip, $userAgent, false);
            $this->events->doAction('auth.login.failed', $email, $ip);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            $this->logAttempt($email, $ip, $userAgent, false);
            $this->events->doAction('auth.login.failed', $email, $ip);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }

        // Check if 2FA required
        if ((bool) $user['two_factor_enabled']) {
            // BUG-26 FIX: Do NOT log as success before 2FA verification.
            // This falsified login logs and bypassed brute-force detection.
            // Log as false (pending) — the actual success log happens after TOTP verify.
            $this->logAttempt($email, $ip, $userAgent, false);
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
        $this->events->doAction('auth.login.success', $user, $ip);

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
            $_SESSION['active_brand_id'] = $user['merchant_id'];
            $_SESSION['auth_role_id'] = $user['role_id'];
            $_SESSION['auth_email'] = $user['email'];
            $_SESSION['auth_name'] = $user['name'];
            $_SESSION['is_superadmin'] = (bool) ($user['is_superadmin'] ?? false);
            $_SESSION['two_fa_enabled'] = (bool) ($user['two_factor_enabled'] ?? false);
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
        if ($this->attempts !== null) {
            $this->attempts->create([
                'email'      => $email,
                'ip_address' => $ip,
                'user_agent' => mb_substr($userAgent, 0, 500),
                'success'    => $success ? 1 : 0,
            ]);
        }
    }

    /**
     * Create base32 TOTP secret.
     */
    public function createSecret(int $bits = 128): string
    {
        return \OwnPay\Middleware\TwoFactorMiddleware::generateSecret((int) ($bits / 8));
    }

    /**
     * Generate TOTP code for secret and time slice.
     */
    public function getCode(string $secret, ?int $timeSlice = null): string
    {
        if ($timeSlice === null) {
            $timeSlice = intdiv(time(), 30);
        }

        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(trim($secret));
        $secretKey = '';
        $buffer = 0;
        $bitsLeft = 0;
        for ($i = 0, $len = strlen($b32); $i < $len; $i++) {
            $val = strpos($chars, $b32[$i]);
            if ($val === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $secretKey .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hmac = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hmac, -1)) & 0x0F;

        $otp = (
            ((ord($hmac[$offset]) & 0x7F) << 24) |
            ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
            ((ord($hmac[$offset + 2]) & 0xFF) << 8) |
            (ord($hmac[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify TOTP code with replay attack protection.
     */
    public function verifyCodeWithReplayGuard(
        string $secret,
        string $code,
        int $lastUsedWindow = 0,
        int $discrepancy = 2,
        ?int $currentTimeSlice = null
    ): int {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return -1;
        }
        $timeSlice = $currentTimeSlice ?? intdiv(time(), 30);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $checkSlice = $timeSlice + $i;
            if ($checkSlice <= $lastUsedWindow) {
                continue;
            }
            $expectedCode = $this->getCode($secret, $checkSlice);
            if (hash_equals($expectedCode, $code)) {
                return $checkSlice;
            }
        }

        return -1;
    }
}
