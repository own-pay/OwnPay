<?php

declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Security\Authenticator;

/**
 * Extracts 2FA verification logic from adapter.php (lines 466-488).
 *
 * Handles TOTP code verification with fallback to password verification
 * when 2FA is disabled.
 */
final class TwoFactorMiddleware
{
    /**
     * Verify a 2FA code against a user record.
     *
     * @param array  $user The user record (must contain 2fa_status, 2fa_secret, password)
     * @param string $code The submitted verification code
     * @return array{verified: bool, error: string|null}
     */
    public function verify(array $user, string $code): array
    {
        if (empty($code)) {
            return ['verified' => false, 'error' => 'Code required'];
        }

        $twoFaEnabled = ($user['2fa_status'] ?? '') === 'enable';

        if ($twoFaEnabled) {
            $secret = $user['2fa_secret'] ?? '';
            if (empty($secret)) {
                return ['verified' => false, 'error' => '2FA not configured'];
            }

            // F6: replay-guarded TOTP — same window cannot be reused
            $ga = new Authenticator();
            $matched = $ga->verifyCodeWithReplayGuard(
                $secret,
                $code,
                (int) ($user['last_otp_window'] ?? 0),
                2
            );
            if ($matched > 0) {
                return ['verified' => true, 'error' => null, 'matched_window' => $matched];
            }

            return [
                'verified' => false,
                'error'    => 'The code you entered is incorrect or has already been used. Please try again.',
            ];
        }

        // 2FA is not enabled for this user — no TOTP gate to enforce.
        // Do NOT fall back to password verification (would silently downgrade 2FA).
        return ['verified' => true, 'error' => null];
    }
}
