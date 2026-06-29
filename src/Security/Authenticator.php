<?php
declare(strict_types=1);

namespace OwnPay\Security;

use OwnPay\Repository\LoginAttemptRepository;
use OwnPay\Repository\MerchantUserRepository;
use OwnPay\Event\EventManager;

/**
 * Class Authenticator
 *
 * Provides enterprise-grade authentication services including Argon2id password verification,
 * timing-safe comparisons, brute-force protection (lockout), and multi-factor authentication (TOTP)
 * logic compliant with OWASP and PCI DSS guidelines.
 *
 * @package OwnPay\Security
 */
final class Authenticator
{
    /**
     * @var \OwnPay\Repository\MerchantUserRepository|null
     */
    private ?MerchantUserRepository $users;

    /**
     * @var \OwnPay\Repository\LoginAttemptRepository|null
     */
    private ?LoginAttemptRepository $attempts;

    /**
     * @var \OwnPay\Event\EventManager|null
     */
    private ?EventManager $events;

    /**
     * Authenticator constructor.
     *
     * Resolves dependencies for user records, login auditing, and system events.
     *
     * @param \OwnPay\Repository\MerchantUserRepository|null $users The user repository.
     * @param \OwnPay\Repository\LoginAttemptRepository|null $attempts The login attempts repository.
     * @param \OwnPay\Event\EventManager|null $events The event manager.
     */
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
     * Attempts to authenticate a user using credentials and log the results.
     *
     * Evaluates brute-force lockout duration rules, checks Argon2id password hashes,
     * handles two-factor authentication redirection triggers, and updates the last login log.
     * Implements constant-time fallback checks for non-existent users.
     *
     * @param string $email The email address of the user.
     * @param string $password The raw password to verify.
     * @param string $ip The client's IP address.
     * @param string $userAgent The client's user agent.
     * @return array{success: bool, user?: array<string, mixed>, error?: string, requires_2fa?: bool} The authentication outcome status and metadata.
     */
    public function attempt(string $email, string $password, string $ip, string $userAgent): array
    {
        $attempts = $this->attempts;
        $users = $this->users;
        $events = $this->events;

        if ($attempts === null || $events === null || $users === null) {
            throw new \RuntimeException('Authenticator dependencies not fully initialized.');
        }

        // Verify active lockout window to prevent brute-force attacks.
        $maxAttempts = (int) (getenv('MAX_LOGIN_ATTEMPTS') ?: 5);
        $window = (int) (getenv('LOCKOUT_DURATION') ?: 300);
        $lockRemaining = $attempts->lockoutSecondsRemaining($email, $ip, $window, $maxAttempts);

        if ($lockRemaining > 0) {
            $events->doAction('auth.login.failed', $email, $ip);
            $minutes = (int) ceil($lockRemaining / 60);
            return [
                'success' => false,
                'error'   => "Account temporarily locked due to repeated failed attempts. Try again in about {$minutes} minute(s).",
            ];
        }

        $user = $users->findActiveByLogin($email);

        if ($user === null) {
            // Log failed attempt (constant time - don't reveal whether account exists)
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
            $this->logAttempt($email, $ip, $userAgent, false);
            $events->doAction('auth.login.failed', $email, $ip);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }

        $passwordHash = is_string($user['password_hash'] ?? null) ? $user['password_hash'] : '';
        if (!password_verify($password, $passwordHash)) {
            $this->logAttempt($email, $ip, $userAgent, false);
            $events->doAction('auth.login.failed', $email, $ip);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }

        // Check if multi-factor authentication (2FA) is enabled for the account.
        if ((bool) $user['two_factor_enabled']) {
            $this->logAttempt($email, $ip, $userAgent, false);
            return [
                'success'      => true,
                'requires_2fa' => true,
                'user'         => $user,
            ];
        }

        // Record successful login auditing and initialize session.
        $this->logAttempt($email, $ip, $userAgent, true);
        $userId = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
        $users->updateLastLogin($userId, $ip);
        $this->startSession($user);
        $events->doAction('auth.login.success', $user, $ip);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Hashes a password using the secure Argon2id algorithm (PCI-DSS compliant).
     *
     * @param string $password The raw password string.
     * @return string The hashed password string.
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
     * Starts and populates the authenticated PHP session context.
     *
     * Regenerates the session ID to mitigate session fixation attacks and assigns
     * authenticated user details, permissions, and active brand boundaries.
     *
     * @param array<string, mixed> $user The authenticated user data.
     * @return void
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
     * Destroys the authenticated session and invalidates session cookies.
     *
     * Resets the active session variables and destroys the session state.
     *
     * @return void
     */
    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                $sessionName = session_name();
                if (is_string($sessionName)) {
                    setcookie($sessionName, '', time() - 42000,
                        $params['path'], $params['domain'],
                        $params['secure'], $params['httponly']
                    );
                }
            }
            session_destroy();
        }
    }

    /**
     * Logs the login attempt status for auditing and rate-limiting purposes.
     *
     * @param string $email The target login email.
     * @param string $ip The request IP address.
     * @param string $userAgent The client's HTTP User-Agent string.
     * @param bool $success Indicating if the attempt was successful.
     * @return void
     */
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
     * Generates a secure base32-encoded secret key for TOTP 2FA.
     *
     * @param int $bits The size of the secret in bits. Defaults to 128.
     * @return string The base32-encoded secret.
     */
    public function createSecret(int $bits = 128): string
    {
        return \OwnPay\Middleware\TwoFactorMiddleware::generateSecret((int) ($bits / 8));
    }

    /**
     * Generates a 6-digit TOTP code based on a base32 secret and time slice.
     *
     * @param string $secret The base32-encoded secret key.
     * @param int|null $timeSlice The optional time slice. Defaults to 30-second steps.
     * @return string The 6-digit TOTP code padded with leading zeroes.
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
     * Verifies a TOTP code while checking for clock drift and guarding against replay attacks.
     *
     * @param string $secret The base32-encoded secret key.
     * @param string $code The 6-digit TOTP code to verify.
     * @param int $lastUsedWindow The last verified time slice window to prevent replay.
     * @param int $discrepancy Allowed window drift offset (e.g. 2 steps = 60s).
     * @param int|null $currentTimeSlice The optional time slice context.
     * @return int The verified time slice window if valid, or -1 if invalid/replayed.
     */
    public function verifyCodeWithReplayGuard(
        string $secret,
        string $code,
        int $lastUsedWindow = 0,
        int $discrepancy = 1,
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
