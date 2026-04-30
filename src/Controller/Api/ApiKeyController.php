<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

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
        // OWASP: Never expose full key, only prefix
        $safe = array_map(fn($k) => ['id' => $k['id'], 'label' => $k['label'], 'prefix' => $k['prefix'] ?? substr($k['key'] ?? '', 0, 8) . '...', 'created_at' => $k['created_at']], $list);
        return Response::json(['success' => true, 'data' => $safe]);
    }

    public function generate(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $label = $req->jsonBody()['label'] ?? 'Default';
        $key = $this->keys->generate($mid, $label);
        // PCI: Show key only once
        return Response::json(['success' => true, 'key' => $key['key'], 'id' => $key['id'], 'warning' => 'Store this key securely. It cannot be retrieved again.'], 201);
    }

    public function revoke(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $this->keys->revoke($mid, $id);
        return Response::json(['success' => true, 'message' => 'Key revoked']);
    }
}
