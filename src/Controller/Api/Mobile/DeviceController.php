<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\PairedDeviceRepository;
use OwnPay\Service\Device\DevicePairingService;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Support\DateHelper;

/**
 * Class DeviceController
 *
 * Mobile Device API - pair, heartbeat, revoke, refresh JWT, status.
 * OWASP: JWT auth, device fingerprint validation.
 *
 * @package OwnPay\Controller\Api\Mobile
 */
final class DeviceController
{
    /**
     * @var Container The dependency injection container.
     */
    /** @phpstan-ignore property.onlyWritten */
    private Container $c;

    /**
     * @var DevicePairingService The device pairing service.
     */
    private DevicePairingService $devices;

    /**
     * @var PairedDeviceRepository The repository for paired devices.
     */
    private PairedDeviceRepository $deviceRepo;

    /**
     * DeviceController constructor.
     *
     * @param Container              $c          The DI container.
     * @param DevicePairingService   $devices    The device pairing service.
     * @param PairedDeviceRepository $deviceRepo The paired device repository.
     */
    public function __construct(Container $c, DevicePairingService $devices, PairedDeviceRepository $deviceRepo)
    {
        $this->c          = $c;
        $this->devices    = $devices;
        $this->deviceRepo = $deviceRepo;
    }

    /**
     * Pairs a mobile companion device.
     *
     * POST /api/mobile/v1/devices
     * Input Body: { pairing_code, device_name, platform, device_id }
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with access token, refresh token, and device UUID.
     */
    public function pair(Request $req): Response
    {
        $body = $req->json();
        $bodyArr = is_array($body) ? $body : [];
        $pairingCodeVal = $bodyArr['pairing_code'] ?? null;
        $pairingCode = is_string($pairingCodeVal) ? $pairingCodeVal : '';
        $deviceIdVal = $bodyArr['device_id'] ?? null;
        $deviceId = is_string($deviceIdVal) ? $deviceIdVal : '';

        if ($pairingCode === '' || $deviceId === '') {
            return Response::apiError('PAIRING_PARAMETERS_REQUIRED', 'pairing_code and device_id required', 'pairing_code', 422);
        }

        try {
            $deviceNameVal = $bodyArr['device_name'] ?? 'Unknown';
            $appVersionVal = $bodyArr['app_version'] ?? '1.0.0';
            $platformVal = $bodyArr['platform'] ?? 'android';
            
            $result = $this->devices->pairDevice(
                InputSanitizer::string($pairingCode),
                InputSanitizer::string(is_string($deviceNameVal) ? $deviceNameVal : 'Unknown'),
                InputSanitizer::string($deviceId),
                InputSanitizer::string(is_string($appVersionVal) ? $appVersionVal : '1.0.0'),
                InputSanitizer::string(is_string($platformVal) ? $platformVal : 'android')
            );

            if (!$result['success']) {
                $errStr = $result['error'];
                return Response::apiError('DEVICE_PAIRING_FAILED', $errStr, null, 400);
            }

            $data = [
                'access_token'  => $result['access_token'],
                'device_uuid'   => $result['device_id'],
                'refresh_token' => $result['refresh_token'],
                'aes_key'       => $result['aes_key'],
                'expires_in'    => $result['expires_in'],
            ];

            return Response::apiSuccess($data, null, 201);
        } catch (\InvalidArgumentException $e) {
            return Response::apiError('INVALID_PAIRING_CODE', $e->getMessage(), 'pairing_code', 400);
        }
    }

    /**
     * Heartbeat endpoint for active mobile companion devices.
     *
     * POST /api/mobile/v1/devices/heartbeat
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with server time.
     */
    public function heartbeat(Request $req): Response
    {
        $deviceIdVal = $req->getAttribute('device_id');
        $deviceId = is_string($deviceIdVal) ? $deviceIdVal : '';
        $this->devices->heartbeat($deviceId);
        return Response::apiSuccess(['server_time' => DateHelper::iso()]);
    }

    /**
     * Revokes a specific mobile device.
     *
     * POST /api/mobile/v1/devices/revoke
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response indicating success.
     */
    public function revoke(Request $req): Response
    {
        $deviceIdVal = $req->getAttribute('device_id');
        $deviceId = is_string($deviceIdVal) ? $deviceIdVal : '';
        $midVal      = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;

        if (ctype_digit($deviceId)) {
            $device = $this->deviceRepo->forTenant($mid)->findScoped((int) $deviceId);
            if ($device !== null) {
                $deviceUuidVal = $device['device_id'] ?? '';
                $deviceId = is_string($deviceUuidVal) ? $deviceUuidVal : '';
            }
        }

        $this->devices->revoke($deviceId, $mid);
        return Response::apiSuccess(['message' => 'Device revoked']);
    }

    /**
     * Revokes multiple mobile devices in bulk.
     *
     * POST /api/mobile/v1/devices/bulk-revoke
     * Input Body: { device_ids: ["uuid1", "uuid2"] }
     *
     * SECURITY (DEV-1): a paired mobile device may only revoke its OWN
     * device UUID. Previously this endpoint iterated `device_ids` and
     * called revoke() on each without comparing against the caller's
     * `device_id` (resolved from the JWT by JwtAuthMiddleware), so any
     * paired device holding a valid JWT for merchant X could revoke any
     * other paired device UUID belonging to merchant X. A single
     * compromised device could DoS the merchant's entire mobile fleet.
     *
     * Each successful revocation is audit-logged so an admin can
     * attribute the action later.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with the count of revoked devices.
     */
    public function bulkRevoke(Request $req): Response
    {
        $midVal  = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $callerDeviceIdVal = $req->getAttribute('device_id');
        $callerDeviceId = is_string($callerDeviceIdVal) ? $callerDeviceIdVal : '';
        $body = $req->json();
        $bodyArr = is_array($body) ? $body : [];

        $deviceIds = $bodyArr['device_ids'] ?? [];
        if (!is_array($deviceIds)) {
            return Response::apiError('INVALID_PARAMETER_TYPE', 'device_ids must be an array', 'device_ids', 422);
        }

        $ids  = array_filter(
            array_map(function($id) {
                $idStr = is_string($id) ? $id : (is_scalar($id) ? (string) $id : '');
                return InputSanitizer::string($idStr);
            }, $deviceIds),
            fn($id) => $id !== ''
        );
        if (empty($ids)) {
            return Response::apiError('DEVICE_IDS_REQUIRED', 'device_ids required', 'device_ids', 422);
        }

        // SECURITY (DEV-1): enforce device-level ownership. A paired device
        // can only bulk-revoke its own UUID; any other UUID is rejected with
        // 403. If a future admin-scoped caller needs to revoke other
        // devices, that should go through a separate admin API endpoint
        // (Api/Admin/DeviceController) with explicit `devices.manage`
        // permission checks.
        if ($callerDeviceId === '') {
            return Response::apiError(
                'CALLER_DEVICE_REQUIRED',
                'Caller device_id missing from JWT',
                'device_id',
                403
            );
        }
        $unauthorized = array_values(array_filter(
            $ids,
            fn($id) => $id !== $callerDeviceId
        ));
        if ($unauthorized !== []) {
            return Response::apiError(
                'DEVICE_OWNERSHIP_REQUIRED',
                'A paired device may only revoke its own UUID; revoking other devices requires admin API access',
                'device_ids',
                403
            );
        }

        // Resolve AuditService up-front so a missing service does not silently
        // skip audit logging for any individual revocation.
        $audit = null;
        try {
            $resolved = $this->c->get(\OwnPay\Service\System\AuditService::class);
            if ($resolved instanceof \OwnPay\Service\System\AuditService) {
                $audit = $resolved;
            }
        } catch (\Throwable) {
            // AuditService unavailable - revocation still proceeds, but we
            // log to error_log so the omission is visible to operators.
            error_log('[DeviceController] AuditService unavailable; bulk-revoke will skip audit logging');
        }

        $count = 0;
        foreach ($ids as $deviceUuid) {
            if ($this->devices->revoke($deviceUuid, $mid)) {
                $count++;
                if ($audit instanceof \OwnPay\Service\System\AuditService) {
                    $audit->log(
                        'mobile.device.revoked',
                        'devices',
                        $mid,
                        null,
                        ['device_uuid' => $deviceUuid, 'revoked_by' => $callerDeviceId]
                    );
                }
            }
        }
        return Response::apiSuccess(['revoked' => $count]);
    }

    /**
     * Refreshes JWT using a valid refresh token.
     *
     * POST /api/mobile/v1/devices/token-refreshes
     * Input Body: { refresh_token: "<jwt_with_long_ttl>" }
     *
     * The refresh token is itself a JWT issued with 30-day TTL.
     * On success, returns a new short-lived access JWT (24h).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with the new access token and refresh token.
     */
    public function refresh(Request $req): Response
    {
        $body         = $req->json();
        $bodyArr      = is_array($body) ? $body : [];
        $refreshTokenVal = $bodyArr['refresh_token'] ?? '';
        $refreshToken = trim(is_string($refreshTokenVal) ? $refreshTokenVal : '');

        if ($refreshToken === '') {
            return Response::apiError('REFRESH_TOKEN_REQUIRED', 'refresh_token required', 'refresh_token', 422);
        }

        $fingerprintVal = $req->header('X-Device-Fingerprint') ?: $req->input('fingerprint') ?: '';
        $fingerprint = is_string($fingerprintVal) ? $fingerprintVal : '';
        if ($fingerprint === '') {
            return Response::apiError('FINGERPRINT_REQUIRED', 'Device fingerprint required', 'fingerprint', 422);
        }

        $res = $this->devices->refreshAccessToken($refreshToken, $fingerprint);
        if (!$res['success']) {
            $err = match ($res['error']) {
                'DEVICE_REVOKED' => 'Device revoked or not found',
                'FINGERPRINT_MISMATCH' => 'Device fingerprint mismatch',
                default => 'Invalid or expired refresh token'
            };
            return Response::apiError('REFRESH_FAILED', $err, null, 401);
        }

        $data = [
            'access_token'  => $res['access_token'],
            'refresh_token' => $res['refresh_token'],
            'expires_in'    => $res['expires_in'],
            'server_time'   => DateHelper::iso(),
        ];

        return Response::apiSuccess($data);
    }

    /**
     * Retrieves current device connection status, last heartbeat, and brand details.
     *
     * GET /api/mobile/v1/devices/statuses
     * Requires valid JWT (device must be active).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response containing status details.
     */
    public function status(Request $req): Response
    {
        $deviceIdVal = $req->getAttribute('device_id');
        $deviceId = is_string($deviceIdVal) ? $deviceIdVal : '';
        $midVal      = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;

        $device = $this->deviceRepo->forTenant($mid)->findByDeviceId($deviceId);

        if ($device === null) {
            return Response::apiError('DEVICE_NOT_FOUND', 'Device not found', null, 404);
        }

        $data = [
            'device_id'      => $device['device_id'],
            'device_name'    => $device['device_name'],
            'platform'       => $device['platform'] ?? 'unknown',
            'status'         => $device['status'],
            'last_heartbeat' => $device['last_heartbeat'] ?? null,
            'merchant_id'    => $mid,
            'server_time'    => DateHelper::iso(),
        ];

        return Response::apiSuccess($data);
    }
}
