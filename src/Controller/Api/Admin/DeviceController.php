<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Device\DevicePairingService;

/**
 * Controller for managing brand mobile companion devices via REST API endpoints.
 */
final class DeviceController
{
    /**
     * The dependency injection container.
     *
     * @phpstan-ignore property.onlyWritten
     */
    private Container $c;

    /**
     * The device pairing service.
     */
    private DevicePairingService $devices;

    /**
     * DeviceController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param DevicePairingService $devices The device pairing service.
     */
    public function __construct(Container $c, DevicePairingService $devices)
    {
        $this->c = $c;
        $this->devices = $devices;
    }

    /**
     * List all paired devices for the active brand.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response listing devices.
     * @throws \Exception If lookup fails.
     */
    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $list = $this->devices->listDevices($mid);
        return Response::json(['success' => true, 'data' => $list]);
    }

    /**
     * Revoke a paired device by UUID.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON success response.
     * @throws \Exception If revocation fails.
     */
    public function revoke(Request $req): Response
    {
        // BUG-47 FIX: Params were swapped — revoke(string $deviceUuid, int $merchantId)
        $deviceUuid = (string) $req->param('id');
        $mid = (int) $req->getAttribute('merchant_id');
        $this->devices->revoke($deviceUuid, $mid);
        return Response::json(['success' => true]);
    }
}
