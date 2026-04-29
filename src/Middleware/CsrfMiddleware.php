<?php

declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Service\System\EnvironmentService;
use OwnPay\Service\System\InputSanitizer;

/**
 * CSRF and HMAC token validation middleware.
 *
 * Supports two modes:
 * 1. Standard CSRF double-submit (session-based)
 * 2. External API HMAC-SHA256 signature (op-token)
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
        $appId = InputSanitizer::html($_POST['op-app-id'] ?? '');
        $appTimestamp = InputSanitizer::html($_POST['op-app-timestamp'] ?? '');

        // Timestamp freshness (±5 minutes)
        if (!ctype_digit($appTimestamp) || abs(time() - (int) $appTimestamp) > 300) {
            return [
                'valid' => false,
                'newToken' => null,
                'error' => 'Request expired. Please try again.',
            ];
        }

        // HMAC secret: prefer .env file, fall back to DB, never auto-generate to DB
        $hmacSecret = $_ENV['APP_HMAC_SECRET'] ?? '';
        if (empty($hmacSecret)) {
            // Fallback: read from DB (legacy installs)
            $hmacSecret = EnvironmentService::get('app-hmac-secret', 'both');
        }
        if (empty($hmacSecret)) {
            error_log('[OwnPay] CRITICAL: APP_HMAC_SECRET not configured in .env file');
            return [
                'valid' => false,
                'newToken' => '',
                'error' => 'Server configuration error. Contact administrator.',
            ];
        }

        // Bind HMAC to specific action to prevent cross-action replay
        $action = InputSanitizer::trim($_POST['action'] ?? $_POST['action-v2'] ?? $_POST['action-companion'] ?? '');
        $data = $appId . '|' . $appTimestamp . '|' . $action;
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
