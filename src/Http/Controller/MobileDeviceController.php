<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Http\JsonResponse;
use OwnPay\Middleware\JwtAuthMiddleware;
use OwnPay\Service\DevicePairingService;

/**
 * MobileDeviceController — REST API endpoints for device pairing & auth.
 *
 * Endpoints:
 *   POST /v1/device/pair    — OTP validation + credential issuance
 *   POST /v1/device/refresh — JWT refresh via refresh token
 *   GET  /v1/device/status  — Connection health check (requires JWT)
 *
 * These endpoints are public-facing (no BearerAuth), protected by
 * OTP validation (pair) or JWT validation (refresh, status).
 */
final class MobileDeviceController
{
    private DevicePairingService $pairing;

    public function __construct()
    {
        $this->pairing = new DevicePairingService();
    }

    /**
     * POST /v1/device/pair
     *
     * Request body:
     * {
     *   "otp": "482910",
     *   "device_name": "Samsung Galaxy A54",
     *   "device_fingerprint": "<android_id>:<cert_sha256>",
     *   "app_version": "1.0.0",
     *   "platform": "android"
     * }
     */
    public function pair(array $params): void
    {
        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        $otp = trim((string) ($body['otp'] ?? ''));
        $deviceName = trim((string) ($body['device_name'] ?? ''));
        $fingerprint = trim((string) ($body['device_fingerprint'] ?? ''));
        $appVersion = trim((string) ($body['app_version'] ?? ''));
        $platform = trim((string) ($body['platform'] ?? 'android'));

        // Validation
        if ($otp === '') {
            JsonResponse::error('MISSING_OTP', 'The "otp" field is required.', 400);
            return;
        }

        if ($fingerprint === '') {
            JsonResponse::error('MISSING_FINGERPRINT', 'The "device_fingerprint" field is required.', 400);
            return;
        }

        if (!preg_match('/^\d{6}$/', $otp)) {
            JsonResponse::error('INVALID_OTP_FORMAT', 'OTP must be exactly 6 digits.', 400);
            return;
        }

        if (!in_array($platform, ['android', 'ios'], true)) {
            $platform = 'android';
        }

        // Execute pairing
        $result = $this->pairing->pairDevice($otp, $deviceName, $fingerprint, $appVersion, $platform);

        if (!$result['success']) {
            $httpStatus = match ($result['error'] ?? '') {
                'INVALID_OTP' => 401,
                default       => 400,
            };
            JsonResponse::error($result['error'], $result['message'], $httpStatus);
            return;
        }

        // Success: return credentials
        JsonResponse::success([
            'access_token'     => $result['access_token'],
            'refresh_token'    => $result['refresh_token'],
            'expires_in'       => $result['expires_in'],
            'aes_key'          => $result['aes_key'],
            'device_id'        => $result['device_id'],
            'filter_rules_url' => $result['filter_rules_url'],
        ]);
    }

    /**
     * POST /v1/device/refresh
     *
     * Request body:
     * {
     *   "refresh_token": "<opaque_token>"
     * }
     *
     * Requires: X-Device-Fingerprint header
     */
    public function refresh(array $params): void
    {
        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        $refreshToken = trim((string) ($body['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            JsonResponse::error('MISSING_REFRESH_TOKEN', 'The "refresh_token" field is required.', 400);
            return;
        }

        // Extract fingerprint
        $fingerprint = $_SERVER['HTTP_X_DEVICE_FINGERPRINT'] ?? '';
        if ($fingerprint === '') {
            JsonResponse::error('MISSING_FINGERPRINT', 'X-Device-Fingerprint header is required.', 400);
            return;
        }

        $result = $this->pairing->refreshAccessToken($refreshToken, $fingerprint);

        if (!$result['success']) {
            $httpStatus = match ($result['error'] ?? '') {
                'FINGERPRINT_MISMATCH'    => 403,
                'INVALID_REFRESH_TOKEN'   => 401,
                default                   => 401,
            };
            JsonResponse::error($result['error'], $result['message'], $httpStatus);
            return;
        }

        JsonResponse::success([
            'access_token'  => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'expires_in'    => $result['expires_in'],
        ]);
    }

    /**
     * GET /v1/device/status
     *
     * Protected by JWT + fingerprint. Returns device health info.
     */
    public function status(array $params): void
    {
        $device = (new JwtAuthMiddleware())->guard();

        JsonResponse::success([
            'device_id'  => $device['device_uuid'],
            'brand_id'   => $device['brand_id'],
            'scopes'     => $device['scopes'],
            'server_time' => date('c'),
            'status'     => 'connected',
        ]);
    }
}
