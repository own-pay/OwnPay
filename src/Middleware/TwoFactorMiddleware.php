<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Two-factor auth middleware — enforces 2FA verification when required.
 *
 * Fires 'auth.2fa.required' filter for plugin override.
 * Per OWASP: TOTP (RFC 6238) verification.
 */
final class TwoFactorMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        // M-02 FIX: Prefer Request attributes over raw $_SESSION.
        $userId = $request->getAttribute('auth_user_id') ?? ($_SESSION['auth_user_id'] ?? null);
        if ($userId === null) {
            return $next($request);
        }

        $user = $request->getAttribute('auth_user');
        if ($user === null) {
            $db = $this->container->get(\OwnPay\Core\Database::class);
            $user = $db->fetchOne("SELECT * FROM op_merchant_users WHERE id = :id AND status = 'active'", ['id' => $userId]);
            if (!$user) {
                // User deleted/deactivated but session persists — destroy entire session.
                // AUD-B6 fix: partial unset left stale keys (auth_role_id, auth_email, etc.)
                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                if ($request->expectsJson()) {
                    return Response::json(['success' => false, 'message' => 'Session expired'], 401);
                }
                return Response::redirect('/login');
            }
            $request->setAttribute('auth_user', $user);
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

        // Plugin filter — allow override
        // C-02 FIX: Removed plugin filter 'auth.2fa.required'
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
     * Verify TOTP code (RFC 6238).
     * Window of ±1 period (30 sec each side).
     *
     * BUG-021 FIX: Tracks last used time slice in $_SESSION to prevent
     * TOTP replay within the ±1 period window.
     */
    public static function verifyTotp(string $secret, string $code, int $window = 1): bool
    {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $timeSlice = intdiv(time(), 30);
        // BUG-021 FIX: Get last used window to prevent replay
        $lastUsedWindow = (int) ($_SESSION['totp_last_used_window'] ?? 0);

        for ($i = -$window; $i <= $window; $i++) {
            $checkSlice = $timeSlice + $i;
            // BUG-021 FIX: Skip already-used time slices
            if ($checkSlice <= $lastUsedWindow) {
                continue;
            }
            $expectedCode = self::generateTotp($secret, $checkSlice);
            if (hash_equals($expectedCode, $code)) {
                // BUG-021 FIX: Record this time slice as used
                $_SESSION['totp_last_used_window'] = $checkSlice;
                return true;
            }
        }

        return false;
    }

    /**
     * Generate TOTP for a given time slice.
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
     * Generate new TOTP secret (base32 encoded).
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
}
