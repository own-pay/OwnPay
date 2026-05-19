<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Customer\ApiKeyService;

final class ApiKeyController
{
    /** @phpstan-ignore property.onlyWritten */
    private Container $c;
    private ApiKeyService $keys;

    public function __construct(Container $c, ApiKeyService $keys)
    {
        $this->c = $c;
        $this->keys = $keys;
    }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $list = $this->keys->list($mid);

        // OWASP: Never expose full key or hash, only prefix
        $safe = array_map(fn(array $k) => [
            'id'         => $k['id'],
            'name'       => $k['name'] ?? 'Unnamed',
            'prefix'     => $k['key_prefix'] ?? null,
            'status'     => $k['status'] ?? 'active',
            'last_used'  => $k['last_used_at'] ?? null,
            'expires_at' => $k['expires_at'] ?? null,
            'created_at' => $k['created_at'],
        ], $list);

        return Response::json(['success' => true, 'data' => $safe]);
    }

    public function generate(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->json();
        $label = $body['name'] ?? $body['label'] ?? 'Default';
        $result = $this->keys->generate($mid, $label);

        // PCI: Show key only once
        return Response::json([
            'success' => true,
            'key'     => $result['key'],
            'prefix'  => $result['prefix'],
            'warning' => 'Store this key securely. It cannot be retrieved again.',
        ], 201);
    }

    public function revoke(Request $req): Response
    {
        $id = (int) $req->param('id');
        $mid = (int) $req->getAttribute('merchant_id');
        $this->keys->revoke($mid, $id);
        return Response::json(['success' => true, 'message' => 'Key revoked']);
    }
}
