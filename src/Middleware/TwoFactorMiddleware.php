<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware responsible for enforcing Two-Factor Authentication (2FA) via RFC 6238 TOTP tokens.
 *
 * Checks if the user has 2FA enabled, verifies their active session authorization status,
 * and intercepts requests that are unverified by redirecting them to the challenge form.
 */
final class TwoFactorMiddleware
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $container;

    /**
     * Constructs a new TwoFactorMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Enforces the two-factor authentication challenge check.
     *
     * Bypasses checks if 2FA is disabled for the user, if the user is already verified,
     * or if the request is destined for the verification/logout endpoints.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        // Prefer Request attributes over raw $_SESSION.
        $userId = $request->getAttribute('auth_user_id') ?? ($_SESSION['auth_user_id'] ?? null);
        if ($userId === null) {
            return $next($request);
        }

        $user = $request->getAttribute('auth_user');
        if ($user === null) {
            $db = $this->container->get(\OwnPay\Core\Database::class);
            $userVal = null;
            if ($db instanceof \OwnPay\Core\Database) {
                $userVal = $db->fetchOne("SELECT * FROM op_merchant_users WHERE id = :id AND status = 'active'", ['id' => $userId]);
            }
            if (!is_array($userVal)) {
                // User deleted/deactivated but session persists - destroy entire session.
                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                if ($request->expectsJson()) {
                    return Response::json(['success' => false, 'message' => 'Session expired'], 401);
                }
                // Use dynamic login slug.
                $loginSlug = $this->resolveLoginSlug();
                return Response::redirect("/{$loginSlug}");
            }
            $user = $userVal;
            $request->setAttribute('auth_user', $user);
        }

        if (!is_array($user)) {
            $user = [];
        }

        // Skip if 2FA not enabled for user
        if (!(bool) ($user['two_factor_enabled'] ?? false)) {
            return $next($request);
        }

        // Already verified this session
        $verified = $request->getAttribute('2fa_verified') ?? ($_SESSION['2fa_verified'] ?? false);
        if ($verified === true) {
            return $next($request);
        }

        // Allow 2FA verification routes through
        $path = $request->path();
        if ($path === '/2fa' || $path === '/logout') {
            return $next($request);
        }

        // 2FA enforcement must NEVER be overridable by plugins (PCI-DSS 8.4.2).

        // Redirect to 2FA challenge
        if ($request->expectsJson()) {
            return Response::json([
                'success' => false,
                'message' => 'Two-factor authentication required',
                'requires_2fa' => true,
            ], 403);
        }

        return Response::redirect('/2fa');
    }

    /**
     * Verifies a given TOTP code against a shared secret using a time window check.
     *
     * Tracks the last used time slice to prevent token replay attacks within the window tolerance.
     *
     * @param string $secret The shared base32 encoded secret.
     * @param string $code The 6-digit verification code.
     * @param int $window The tolerance window (representing multiples of 30-second steps).
     * @return bool True if valid and not previously replayed; false otherwise.
     */
    public static function verifyTotp(string $secret, string $code, int $window = 1, ?int &$lastUsedWindow = null): bool
    {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $timeSlice = intdiv(time(), 30);
        $useSession = ($lastUsedWindow === null);
        if ($useSession) {
            $lastUsedVal = $_SESSION['totp_last_used_window'] ?? 0;
            $lastUsedWindow = (is_int($lastUsedVal) || is_numeric($lastUsedVal)) ? (int) $lastUsedVal : 0;
        }

        for ($i = -$window; $i <= $window; $i++) {
            $checkSlice = $timeSlice + $i;
            // Skip already-used time slices
            if ($checkSlice <= $lastUsedWindow) {
                continue;
            }
            $expectedCode = self::generateTotp($secret, $checkSlice);
            if (hash_equals($expectedCode, $code)) {
                // Record this time slice as used. The updated window is returned to the
                // caller via reference so it can be persisted durably (e.g. in the cache).
                $lastUsedWindow = $checkSlice;
                if ($useSession) {
                    $_SESSION['totp_last_used_window'] = $checkSlice;
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Generates a TOTP code for a specific base32 secret and time slice.
     *
     * @param string $secret The shared base32 secret.
     * @param int $timeSlice The target time slice integer.
     * @return string The generated 6-digit TOTP code.
     */
    private static function generateTotp(string $secret, int $timeSlice): string
    {
        $secretKey = self::base32Decode($secret);
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
     * Generates a random base32 encoded 2FA secret key.
     *
     * @param int $length The character length of the generated secret (default 16).
     * @return string The base32 secret string.
     */
    public static function generateSecret(int $length = 16): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Decodes a base32 encoded string back to binary.
     *
     * @param string $b32 The base32 string to decode.
     * @return string The decoded binary byte string.
     */
    private static function base32Decode(string $b32): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(trim($b32));
        $output = '';
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
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }

    /**
     * Resolves the dynamic login slug from configuration settings.
     *
     * @return string The dynamic login URI segment.
     */
    private function resolveLoginSlug(): string
    {
        $cacheFile = dirname(__DIR__, 2) . '/storage/cache/login_slug.cache';
        if (file_exists($cacheFile)) {
            $slug = @file_get_contents($cacheFile);
            if ($slug !== false && $slug !== '') {
                $slug = trim($slug);
                if (preg_match('/^[a-z0-9\-]+$/', $slug)) {
                    return $slug;
                }
            }
        }

        try {
            $settings = $this->container->get(\OwnPay\Repository\SettingsRepository::class);
            if ($settings instanceof \OwnPay\Repository\SettingsRepository) {
                $slug = $settings->get('landing', 'admin_login_slug', 'login');
                if (is_string($slug)) {
                    return $slug;
                }
            }
            return 'login';
        } catch (\Throwable) {
            return 'login';
        }
    }
}
