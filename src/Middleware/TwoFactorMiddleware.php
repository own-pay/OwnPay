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
        $user = $request->getAttribute('auth_user');

        if ($user === null) {
            return $next($request);
        }

        // Skip if 2FA not enabled for user
        if (!(bool) ($user['two_factor_enabled'] ?? false)) {
            return $next($request);
        }

        // Already verified this session
        if (!empty($_SESSION['2fa_verified']) && $_SESSION['2fa_verified'] === true) {
            return $next($request);
        }

        // Allow 2FA verification routes through
        $path = $request->path();
        if ($path === '/admin/2fa/verify' || $path === '/admin/2fa/challenge' || $path === '/logout') {
            return $next($request);
        }

        // Plugin filter — allow override
        /** @var EventManager $events */
        $events = $this->container->get(EventManager::class);
        $required = $events->applyFilter('auth.2fa.required', true, $user, $request);

        if (!$required) {
            return $next($request);
        }

        // Redirect to 2FA challenge
        if ($request->expectsJson()) {
            return Response::json([
                'success' => false,
                'message' => 'Two-factor authentication required',
                'requires_2fa' => true,
            ], 403);
        }

        return Response::redirect('/admin/2fa/challenge');
    }

    /**
     * Verify TOTP code (RFC 6238).
     * Window of ±1 period (30 sec each side).
     */
    public static function verifyTotp(string $secret, string $code, int $window = 1): bool
    {
        $timeSlice = intdiv(time(), 30);

        for ($i = -$window; $i <= $window; $i++) {
            $expectedCode = self::generateTotp($secret, $timeSlice + $i);
            if (hash_equals($expectedCode, $code)) {
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
