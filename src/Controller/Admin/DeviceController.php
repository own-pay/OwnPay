<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

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
        return $this->render('admin/devices/index.twig', ['devices' => $list, 'active_page' => 'devices']);
    }

    public function revoke(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $this->devices->revoke($mid, $id);
        $_SESSION['flash_success'] = 'Device revoked';
        return Response::redirect('/admin/devices');
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? ''; $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay'; $data['current_user'] = $_SESSION['user'] ?? [];
        $data['flash_success'] = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);
        return Response::html($twig->render($tpl, $data));
    }
}
