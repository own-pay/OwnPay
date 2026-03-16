<?php

declare(strict_types=1);

namespace AnirbanPay\Middleware;

/**
 * Extracts CSRF and HMAC token validation from adapter.php.
 *
 * Supports two modes:
 * 1. Standard CSRF double-submit (session-based)
 * 2. External API HMAC-SHA256 signature (ap-token / pp-token)
 */
final class CsrfMiddleware
{
    /**
     * Validate the request token.
     *
     * @return array{valid: bool, newToken: string|null, error: string|null}
     */
    public function validate(string $appToken): array
    {
        if ($appToken !== '') {
            return $this->validateHmac($appToken);
        }

        return $this->validateCsrf();
    }

    private function validateCsrf(): array
    {
        $postToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if (empty($postToken) || empty($sessionToken) || !hash_equals($sessionToken, $postToken)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            return [
                'valid' => false,
                'newToken' => $_SESSION['csrf_token'],
                'error' => 'Invalid request token',
            ];
        }

        return ['valid' => true, 'newToken' => $_SESSION['csrf_token'], 'error' => null];
    }

    private function validateHmac(string $appToken): array
    {
        $appId = sanitize_html($_POST['ap-app-id'] ?? $_POST['pp-app-id'] ?? '');
        $appTimestamp = sanitize_html($_POST['ap-app-timestamp'] ?? $_POST['pp-app-timestamp'] ?? '');

        // Timestamp freshness (±5 minutes)
        if (!ctype_digit($appTimestamp) || abs(time() - (int) $appTimestamp) > 300) {
            return [
                'valid' => false,
                'newToken' => null,
                'error' => 'Request expired. Please try again.',
            ];
        }

        $hmacSecret = get_env('app-hmac-secret', 'both');
        if (empty($hmacSecret) || $hmacSecret === '--') {
            $hmacSecret = '698b7520-c604-8323-a04d-dc519bb3e1d3';
            error_log('[AnirbanPay] WARNING: app-hmac-secret not found in env, using hardcoded fallback');
        }

        $data = $appId . '|' . $appTimestamp;
        $expected = hash_hmac('sha256', $data, $hmacSecret);

        if (!hash_equals($expected, $appToken)) {
            return [
                'valid' => false,
                'newToken' => '',
                'error' => 'Invalid request token',
            ];
        }

        return ['valid' => true, 'newToken' => '', 'error' => null];
    }
}
