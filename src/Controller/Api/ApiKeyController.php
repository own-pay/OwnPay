<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Customer\ApiKeyService;

/**
 * Controller for brand API keys management via REST API endpoints.
 */
final class ApiKeyController
{
    /**
     * The dependency injection container.
     *
     * @phpstan-ignore property.onlyWritten
     */
    private Container $c;

    /**
     * The API key service.
     */
    private ApiKeyService $keys;

    /**
     * ApiKeyController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param ApiKeyService $keys The API key service.
     */
    public function __construct(Container $c, ApiKeyService $keys)
    {
        $this->c = $c;
        $this->keys = $keys;
    }

    /**
     * List all API keys for the active brand.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response with safe API key metadata.
     * @throws \Exception If lookup fails.
     */
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

    /**
     * Generate a new API key for the active brand.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response containing the plain API key (displayed only once).
     * @throws \Exception If key generation fails.
     */
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

    /**
     * Revoke an API key by ID.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON success response.
     * @throws \Exception If revocation fails.
     */
    public function revoke(Request $req): Response
    {
        $id = (int) $req->param('id');
        $mid = (int) $req->getAttribute('merchant_id');
        $this->keys->revoke($mid, $id);
        return Response::json(['success' => true, 'message' => 'Key revoked']);
    }
}
