<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Http\JsonResponse;
use OwnPay\Middleware\BearerAuthMiddleware;
use OwnPay\Service\ApiKeyService;

/**
 * POST   /v1/api-keys        — Create an API key
 * GET    /v1/api-keys         — List API keys (hashes never exposed)
 * DELETE /v1/api-keys/{id}    — Revoke an API key
 */
final class ApiKeyController
{
    private ApiKeyService $keys;

    public function __construct()
    {
        $this->keys = new ApiKeyService();
    }

    /**
     * POST /v1/api-keys
     */
    public function create(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('manage_api_keys');

        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        $name = $body['name'] ?? null;
        $scopes = $body['scopes'] ?? [];

        if (empty($name)) {
            JsonResponse::error('MISSING_FIELD', 'The "name" field is required.', 400);
            return;
        }

        if (!is_array($scopes)) {
            JsonResponse::error('INVALID_FIELD', 'The "scopes" field must be an array.', 400);
            return;
        }

        $expiresAt = $body['expires_at'] ?? null;

        $result = $this->keys->create(
            $merchant['merchant_id'],
            $name,
            $scopes,
            $expiresAt
        );

        JsonResponse::created([
            'id' => $result['publicId'],
            'name' => $name,
            'key' => $result['rawKey'],   // Shown ONCE — never stored
            'prefix' => $result['prefix'],
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
            '_warning' => 'Store this key securely. It will not be shown again.',
        ]);
    }

    /**
     * GET /v1/api-keys
     */
    public function index(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('manage_api_keys');

        $keys = $this->keys->listByMerchant($merchant['merchant_id']);

        $items = array_map(fn(array $key) => [
            'id' => $key['public_id'],
            'name' => $key['name'],
            'prefix' => $key['key_prefix'],
            'scopes' => json_decode($key['scopes'] ?? '[]', true),
            'status' => $key['status'],
            'last_used_at' => $key['last_used_at'],
            'last_used_ip' => $key['last_used_ip'],
            'expires_at' => $key['expires_at'],
            'created_at' => $key['created_at'],
        ], $keys);

        JsonResponse::success($items);
    }

    /**
     * DELETE /v1/api-keys/{id}
     */
    public function destroy(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('manage_api_keys');

        $publicId = $params['id'] ?? '';

        // Find the key by public ID
        $keyRepo = new \OwnPay\Repository\ApiKeyRepository();
        $key = $keyRepo->findByPublicId($publicId);

        if ($key === null || (int) $key['merchant_id'] !== $merchant['merchant_id']) {
            JsonResponse::error('NOT_FOUND', 'API key not found.', 404);
            return;
        }

        if ($key['status'] === 'revoked') {
            JsonResponse::error('ALREADY_REVOKED', 'This API key has already been revoked.', 409);
            return;
        }

        $this->keys->revoke((int) $key['id'], $merchant['merchant_id']);

        JsonResponse::success(['id' => $publicId, 'status' => 'revoked']);
    }
}
