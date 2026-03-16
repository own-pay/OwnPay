<?php

declare(strict_types=1);

namespace AnirbanPay\Middleware;

use AnirbanPay\Security\Authenticator;

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
            if (empty($secret) || $secret === '--' || $secret === null) {
                return ['verified' => false, 'error' => '2FA not configured'];
            }

            $ga = new Authenticator();
            if ($ga->verifyCode($secret, $code, 2)) {
                return ['verified' => true, 'error' => null];
            }

            return [
                'verified' => false,
                'error' => 'The code you entered is incorrect. Please try again.',
            ];
        }

        // Fallback: password verification when 2FA is disabled
        $hash = $user['password'] ?? '';
        if (password_verify($code, $hash)) {
            return ['verified' => true, 'error' => null];
        }

        return [
            'verified' => false,
            'error' => 'The password you entered is incorrect. Please try again.',
        ];
    }
}
