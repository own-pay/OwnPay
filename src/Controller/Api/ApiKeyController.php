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
     */
    /** @phpstan-ignore property.onlyWritten */
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
        $secureErr = $this->enforceSecureAccess($req);
        if ($secureErr !== null) {
            return $secureErr;
        }

        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $list = $this->keys->list($mid);

        // Never expose full key or hash, only prefix
        $safe = array_map(fn(array $k) => [
            'id'         => $k['id'],
            'name'       => $k['name'] ?? 'Unnamed',
            'prefix'     => $k['key_prefix'] ?? null,
            'status'     => $k['status'] ?? 'active',
            'last_used'  => $k['last_used_at'] ?? null,
            'expires_at' => $k['expires_at'] ?? null,
            'created_at' => $k['created_at'],
        ], $list);

        return Response::apiSuccess($safe);
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
        $secureErr = $this->enforceSecureAccess($req);
        if ($secureErr !== null) {
            return $secureErr;
        }

        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $body = $req->json();
        $bodyArr = is_array($body) ? $body : [];
        $labelVal = $bodyArr['name'] ?? $bodyArr['label'] ?? 'Default';
        $label = is_string($labelVal) ? $labelVal : 'Default';

        $requestedScopes = $bodyArr['scopes'] ?? null;
        if ($requestedScopes !== null) {
            if (!is_array($requestedScopes)) {
                return Response::apiError('INVALID_SCOPES', 'Scopes must be an array of privileges.', 'scopes', 422);
            }
            $allowed = ['read', 'write', 'admin'];
            /** @var array<string> $scopesToGenerate */
            $scopesToGenerate = [];
            foreach ($requestedScopes as $s) {
                if (!is_string($s) || !in_array($s, $allowed, true)) {
                    return Response::apiError('INVALID_SCOPES', 'Invalid scope value. Allowed: read, write, admin', 'scopes', 422);
                }
                $scopesToGenerate[] = $s;
            }
            if (empty($scopesToGenerate)) {
                return Response::apiError('INVALID_SCOPES', 'At least one privilege scope must be provided.', 'scopes', 422);
            }
            $scopesToGenerate = array_values(array_unique($scopesToGenerate));
        } else {
            $scopesToGenerate = ['read', 'write'];
        }

        $result = $this->keys->generate($mid, $label, $scopesToGenerate);

        $data = [
            'key'     => $result['key'],
            'prefix'  => $result['prefix'],
            'warning' => 'Store this key securely. It cannot be retrieved again.',
        ];

        return Response::apiSuccess($data, null, 201);
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
        $secureErr = $this->enforceSecureAccess($req);
        if ($secureErr !== null) {
            return $secureErr;
        }

        $id = (int) $req->param('id');
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        
        try {
            $revoked = $this->keys->revoke($mid, $id);
            if ($revoked === 0) {
                return Response::apiError('KEY_NOT_FOUND', 'API key not found', 'id', 404);
            }
            return Response::apiSuccess(['message' => 'Key revoked']);
        } catch (\Throwable $e) {
            return Response::apiError('KEY_REVOCATION_FAILED', $e->getMessage(), 'id', 400);
        }
    }

    /**
     * Enforce write and admin scopes and active super admin email header validation.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response|null Response error if unauthorized, otherwise null.
     */
    private function enforceSecureAccess(Request $req): ?Response
    {
        $apiKey = $req->getAttribute('api_key');
        if (!is_array($apiKey)) {
            return Response::apiError('UNAUTHORIZED', 'API key metadata missing.', null, 401);
        }

        $scopesRaw = $apiKey['scopes'] ?? null;
        $scopes = [];
        if (is_string($scopesRaw)) {
            $scopes = json_decode($scopesRaw, true);
        } elseif (is_array($scopesRaw)) {
            $scopes = $scopesRaw;
        }

        if (!is_array($scopes)) {
            $scopes = [];
        }

        if (!in_array('write', $scopes, true) || !in_array('admin', $scopes, true)) {
            return Response::apiError('INSUFFICIENT_PRIVILEGE', 'Insufficient API key privilege. Key must have both write and admin scopes.', null, 403);
        }

        $email = trim($req->header('X-Super-Admin-Email'));
        if ($email === '') {
            return Response::apiError('SUPER_ADMIN_EMAIL_REQUIRED', 'Super admin email is required in the X-Super-Admin-Email header.', 'X-Super-Admin-Email', 400);
        }

        $db = \OwnPay\Core\Database::getInstance();
        $user = $db->fetchOne(
            "SELECT 1 FROM op_merchant_users WHERE email = :email AND is_superadmin = 1 AND status = 'active' LIMIT 1",
            ['email' => $email]
        );

        if (!$user) {
            return Response::apiError('INVALID_SUPER_ADMIN', 'Invalid or inactive super admin email in header.', 'X-Super-Admin-Email', 403);
        }

        return null;
    }
}
