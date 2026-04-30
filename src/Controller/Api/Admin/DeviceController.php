<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Device\DevicePairingService;

final class DeviceController
{
    private Container $c;
    private DevicePairingService $devices;
    public function __construct(Container $c, DevicePairingService $devices) { $this->c = $c; $this->devices = $devices; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $list = $this->devices->listForMerchant($mid);
        return Response::json(['success' => true, 'data' => $list]);
    }

    public function revoke(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $this->devices->revoke($mid, $id);
        return Response::json(['success' => true]);
    }
}
