<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Device\DevicePairingService;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Support\DateHelper;

/**
 * Mobile Device API â€” pair, heartbeat, revoke.
 * OWASP: JWT auth, device fingerprint validation.
 */
final class DeviceController
{
    private Container $c;
    private DevicePairingService $devices;

    public function __construct(Container $c, DevicePairingService $devices)
    {
        $this->c = $c;
        $this->devices = $devices;
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
            $result = $this->devices->pair([
                'pairing_code' => InputSanitizer::string($body['pairing_code']),
                'device_id'    => InputSanitizer::string($body['device_id']),
                'device_name'  => InputSanitizer::string($body['device_name'] ?? 'Unknown'),
                'platform'     => InputSanitizer::string($body['platform'] ?? 'android'),
            ]);
            return Response::json([
                'success' => true,
                'jwt'     => $result['jwt'],
                'expires_at' => $result['expires_at'],
                'merchant_id' => $result['merchant_id'],
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
        $this->devices->heartbeat((int) $deviceId);
        return Response::json(['success' => true, 'server_time' => DateHelper::iso()]);
    }

    /**
     * POST /api/mobile/v1/devices/revoke
     */
    public function revoke(Request $req): Response
    {
        $deviceId = (int) $req->getAttribute('device_id');
        $mid = (int) $req->getAttribute('merchant_id');
        $this->devices->revoke($mid, $deviceId);
        return Response::json(['success' => true]);
    }

    /**
     * POST /api/mobile/v1/devices/bulk-revoke
     * Body: { device_ids: [1, 2, 3] }
     */
    public function bulkRevoke(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->json();
        $ids = array_filter(array_map('intval', $body['device_ids'] ?? []));
        if (empty($ids)) return Response::json(['success' => false, 'error' => 'device_ids required'], 422);

        $count = 0;
        foreach ($ids as $id) {
            $this->devices->revoke($mid, $id);
            $count++;
        }
        return Response::json(['success' => true, 'revoked' => $count]);
    }
}
