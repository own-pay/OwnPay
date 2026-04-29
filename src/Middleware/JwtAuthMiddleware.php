<?php

declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Http\JsonResponse;
use OwnPay\Service\Device\DevicePairingService;

/**
 * JwtAuthMiddleware — Authenticates mobile companion API requests.
 *
 * Pipeline: Extract Bearer JWT → Validate device fingerprint → Return device context.
 *
 * Required headers:
 *   - Authorization: Bearer <JWT>
 *   - X-Device-Fingerprint: <android_id>:<cert_sha256>
 *
 * On failure: Sends JSON error response and exits.
 * On success: Returns device context array.
 */
final class JwtAuthMiddleware
{
    private DevicePairingService $pairingService;

    public function __construct(?DevicePairingService $pairingService = null)
    {
        $this->pairingService = $pairingService ?? new DevicePairingService();
    }

    /**
     * Guard: Authenticate the request and return device context.
     *
     * @param string|null $requiredScope Optional scope check (e.g. 'sms:submit')
     * @return array{device_uuid: string, brand_id: int, scopes: array}
     */
    public function guard(?string $requiredScope = null): array
    {
        // 1. Extract JWT from Authorization header
        $jwt = $this->extractBearerToken();
        if ($jwt === null) {
            JsonResponse::error(
                'MISSING_AUTHORIZATION',
                'Authorization header is missing or invalid. Expected: Authorization: Bearer <JWT>',
                401
            );
            exit;
        }

        // 2. Extract device fingerprint
        $fingerprint = $this->extractFingerprint();
        if ($fingerprint === null) {
            JsonResponse::error(
                'MISSING_FINGERPRINT',
                'X-Device-Fingerprint header is required.',
                400
            );
            exit;
        }

        // 3. Validate JWT + fingerprint
        $result = $this->pairingService->validateRequest($jwt, $fingerprint);

        if (!$result['valid']) {
            $httpStatus = match ($result['error']) {
                'TOKEN_EXPIRED'         => 401,
                'DEVICE_REVOKED'        => 403,
                'FINGERPRINT_MISMATCH'  => 403,
                default                 => 401,
            };

            $message = match ($result['error']) {
                'TOKEN_EXPIRED'         => 'Access token has expired. Use your refresh token to obtain a new one.',
                'DEVICE_REVOKED'        => 'This device has been revoked. Please re-pair from the admin panel.',
                'FINGERPRINT_MISMATCH'  => 'Device fingerprint does not match. This request has been blocked.',
                'INVALID_SIGNATURE'     => 'Token signature is invalid.',
                default                 => 'Authentication failed.',
            };

            JsonResponse::error($result['error'], $message, $httpStatus);
            exit;
        }

        // 4. Scope check (optional)
        if ($requiredScope !== null) {
            $scopes = $result['device']['scopes'] ?? [];
            if (!in_array($requiredScope, $scopes, true)) {
                JsonResponse::error(
                    'INSUFFICIENT_SCOPE',
                    "This endpoint requires the '{$requiredScope}' scope.",
                    403
                );
                exit;
            }
        }

        return $result['device'];
    }

    /**
     * Extract Bearer token from Authorization header.
     */
    private function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract device fingerprint from X-Device-Fingerprint header.
     */
    private function extractFingerprint(): ?string
    {
        $fingerprint = $_SERVER['HTTP_X_DEVICE_FINGERPRINT'] ?? '';
        return $fingerprint !== '' ? $fingerprint : null;
    }
}
