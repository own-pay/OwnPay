<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Device\DevicePairingService;

final class DeviceController
{
    /** @phpstan-ignore property.onlyWritten */
    private Container $c;
    private DevicePairingService $devices;

    public function __construct(Container $c, DevicePairingService $devices)
    {
        $this->c = $c;
        $this->devices = $devices;
    }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $list = $this->devices->listDevices($mid);
        return Response::json(['success' => true, 'data' => $list]);
    }

    public function revoke(Request $req): Response
    {
        // BUG-47 FIX: Params were swapped — revoke(string $deviceUuid, int $merchantId)
        $deviceUuid = (string) $req->param('id');
        $mid = (int) $req->getAttribute('merchant_id');
        $this->devices->revoke($deviceUuid, $mid);
        return Response::json(['success' => true]);
    }
}
