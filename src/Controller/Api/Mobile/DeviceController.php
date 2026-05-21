<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\PairedDeviceRepository;
use OwnPay\Service\Device\DevicePairingService;
use OwnPay\Service\Auth\JwtService;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Support\DateHelper;

/**
 * Mobile Device API — pair, heartbeat, revoke, refresh JWT, status.
 * OWASP: JWT auth, device fingerprint validation.
 */
final class DeviceController
{
    /** @phpstan-ignore property.onlyWritten */
    private Container $c;
    private DevicePairingService $devices;
    private PairedDeviceRepository $deviceRepo;
    private JwtService $jwt;

    public function __construct(Container $c, DevicePairingService $devices, PairedDeviceRepository $deviceRepo, JwtService $jwt)
    {
        $this->c          = $c;
        $this->devices    = $devices;
        $this->deviceRepo = $deviceRepo;
        $this->jwt        = $jwt;
    }

    /**
     * POST /api/mobile/v1/devices/pair
     * Body: { pairing_code, device_name, platform, device_id }
     */
    public function pair(Request $req): Response
    {
        $body = $req->json();
        if (empty($body['pairing_code']) || empty($body['device_id'])) {
            return Response::json(['success' => false, 'error' => 'pairing_code and device_id required'], 422);
        }

        try {
            $result = $this->devices->pairDevice(
                InputSanitizer::string($body['pairing_code']),
                InputSanitizer::string($body['device_name'] ?? 'Unknown'),
                InputSanitizer::string($body['device_id']),
                InputSanitizer::string($body['app_version'] ?? '1.0.0'),
                InputSanitizer::string($body['platform'] ?? 'android')
            );

            if (isset($result['success']) && !$result['success']) {
                return Response::json(['success' => false, 'error' => $result['error'] ?? 'Pairing failed'], 400);
            }

            return Response::json([
                'success'       => true,
                'access_token'  => $result['access_token'],
                'device_uuid'   => $result['device_id'],
                'refresh_token' => $result['refresh_token'],
                'aes_key'       => $result['aes_key'] ?? null,
                'expires_in'    => $result['expires_in'] ?? 900,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/mobile/v1/devices/heartbeat
     */
    public function heartbeat(Request $req): Response
    {
        $deviceId = $req->getAttribute('device_id');
        /** @phpstan-ignore-next-line */ $this->devices->heartbeat((string) $deviceId);
        return Response::json(['success' => true, 'server_time' => DateHelper::iso()]);
    }

    /**
     * POST /api/mobile/v1/devices/revoke
     */
    public function revoke(Request $req): Response
    {
        $deviceId = (string) $req->getAttribute('device_id');
        $mid      = (int) $req->getAttribute('merchant_id');

        if (ctype_digit($deviceId)) {
            $device = $this->deviceRepo->forTenant($mid)->findScoped((int) $deviceId);
            if ($device !== null) {
                $deviceId = (string) $device['device_id'];
            }
        }

        $this->devices->revoke((string) $deviceId, $mid);
        return Response::json(['success' => true]);
    }

    /**
     * POST /api/mobile/v1/devices/bulk-revoke
     * Body: { device_ids: ["uuid1", "uuid2"] }
     *
     * BUG-38 FIX: Accept string UUIDs, not integers.
     * revoke() expects UUID strings; intval('uuid') = 0, filtering out all valid devices.
     */
    public function bulkRevoke(Request $req): Response
    {
        $mid  = (int) $req->getAttribute('merchant_id');
        $body = $req->json();
        $ids  = array_filter(
            array_map(fn($id) => InputSanitizer::string((string) $id), $body['device_ids'] ?? []),
            fn($id) => $id !== ''
        );
        if (empty($ids)) {
            return Response::json(['success' => false, 'error' => 'device_ids required'], 422);
        }

        $count = 0;
        foreach ($ids as $deviceUuid) {
            if ($this->devices->revoke($deviceUuid, $mid)) {
                $count++;
            }
        }
        return Response::json(['success' => true, 'revoked' => $count]);
    }

    /**
     * POST /api/mobile/v1/devices/refresh
     *
     * Re-issues a new JWT using a valid refresh token.
     * Body: { refresh_token: "<jwt_with_long_ttl>" }
     *
     * The refresh token is itself a JWT issued with 30-day TTL.
     * On success, returns a new short-lived access JWT (24h).
     */
    public function refresh(Request $req): Response
    {
        $body         = $req->json();
        $refreshToken = trim($body['refresh_token'] ?? '');

        if ($refreshToken === '') {
            return Response::json(['success' => false, 'error' => 'refresh_token required'], 422);
        }

        try {
            $claims = $this->jwt->verify($refreshToken);
        } catch (\RuntimeException $e) {
            return Response::json(['success' => false, 'error' => 'Invalid or expired refresh token'], 401);
        }

        $userId     = (int) $claims['sub'];
        $mid        = (int) $claims['mid'];
        $deviceId   = (string) $claims['did'];

        if (!$userId || !$mid || $deviceId === '') {
            return Response::json(['success' => false, 'error' => 'Malformed refresh token'], 401);
        }

        // Verify device is still active
        $device = $this->deviceRepo->forTenant($mid)->findByDeviceId($deviceId);
        if ($device === null || ($device['status'] ?? '') !== 'active') {
            return Response::json(['success' => false, 'error' => 'Device revoked or not found'], 403);
        }

        // Issue fresh access token (24h) + new refresh token (30 days)
        $newAccess  = $this->jwt->issue($userId, $mid, $deviceId);
        $newRefresh = $this->jwt->issueRefreshToken($userId, $mid, $deviceId);

        return Response::json([
            'success'       => true,
            'access_token'  => $newAccess,
            'refresh_token' => $newRefresh,
            'expires_in'    => 86400,
            'server_time'   => DateHelper::iso(),
        ]);
    }

    /**
     * GET /api/mobile/v1/devices/status
     *
     * Returns current device connection status, last heartbeat, and brand info.
     * Requires valid JWT (device must be active).
     */
    public function status(Request $req): Response
    {
        $deviceId = (string) $req->getAttribute('device_id');
        $mid      = (int)    $req->getAttribute('merchant_id');

        $device = $this->deviceRepo->forTenant($mid)->findByDeviceId($deviceId);

        if ($device === null) {
            return Response::json(['success' => false, 'error' => 'Device not found'], 404);
        }

        return Response::json([
            'success'        => true,
            'device_id'      => $device['device_id'],
            'device_name'    => $device['device_name'],
            'platform'       => $device['platform'] ?? 'unknown',
            'status'         => $device['status'],
            'last_heartbeat' => $device['last_heartbeat'] ?? null,
            'merchant_id'    => $mid,
            'server_time'    => DateHelper::iso(),
        ]);
    }
}
