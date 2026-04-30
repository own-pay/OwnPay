<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Customer\ApiKeyService;

final class ApiKeyController
{
    private Container $c;
    private ApiKeyService $keys;

    public function __construct(Container $c, ApiKeyService $keys) { $this->c = $c; $this->keys = $keys; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $list = $this->keys->listForMerchant($mid);
        return $this->render('admin/settings/index.twig', ['api_keys' => $list, 'active_page' => 'settings']);
    }

    public function generate(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $label = $req->post('label', 'Default');
        $key = $this->keys->generate($mid, $label);
        $_SESSION['flash_success'] = "API Key generated: {$key['key']}. Copy it now — it won't be shown again.";
        return Response::redirect('/admin/settings#tab-api');
    }

    public function revoke(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $this->keys->revoke($mid, $id);
        $_SESSION['flash_success'] = 'API key revoked';
        return Response::redirect('/admin/settings#tab-api');
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? ''; $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay'; $data['current_user'] = $_SESSION['user'] ?? [];
        return Response::html($twig->render($tpl, $data));
    }
}
