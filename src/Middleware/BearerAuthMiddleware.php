<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\ApiKeyRepository;
use OwnPay\Support\DateHelper;

/**
 * Middleware responsible for authenticating REST API requests using Bearer tokens (API keys).
 *
 * Extracting token prefix is used to locate the key metadata, followed by a sha256 hashing
 * and constant-time string comparison to defend against timing attacks. This matches OWASP
 * standards and enforces key state checks (active vs revoked) and expiration times, before
 * injecting the matched merchant/brand context into request attributes.
 */
final class BearerAuthMiddleware
{
    /**
     * The PSR-11 dependency injection container instance.
     *
     * @var Container
     */
    private Container $container;

    /**
     * Constructs a new instance of BearerAuthMiddleware.
     *
     * @param Container $container Dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Handles the incoming HTTP request authentication.
     *
     * @param Request $request The incoming HTTP request instance.
     * @param callable(Request): Response $next Next middleware/handler in the execution stack.
     * @return Response The HTTP response instance.
     */
    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return Response::json([
                'success' => false,
                'message' => 'Authentication required. Provide Bearer token.',
            ], 401)->withHeader('WWW-Authenticate', 'Bearer');
        }

        // API key format: op_XXXXXXXX.YYYYYYYY...
        // Prefix = first 8 chars after "op_"
        if (!str_starts_with($token, 'op_') || strlen($token) < 12) {
            return Response::json([
                'success' => false,
                'message' => 'Invalid API key format',
            ], 401);
        }

        $prefix = substr($token, 3, 8);
        $keyHash = hash('sha256', $token);

        /** @var ApiKeyRepository $repo */
        $repo = $this->container->get(ApiKeyRepository::class);
        $apiKey = $repo->findByPrefix($prefix);

        if ($apiKey === null) {
            return Response::json(['success' => false, 'message' => 'Invalid API key'], 401);
        }

        // Timing-safe comparison (per OWASP)
        if (!isset($apiKey['key_hash']) || !is_string($apiKey['key_hash']) || !hash_equals($apiKey['key_hash'], $keyHash)) {
            return Response::json(['success' => false, 'message' => 'Invalid API key'], 401);
        }

        // Check API key status - revoked/inactive keys must be rejected.
        if (($apiKey['status'] ?? 'active') !== 'active') {
            return Response::json(['success' => false, 'message' => 'API key has been revoked'], 401);
        }

        // Check expiry
        $expiresAt = $apiKey['expires_at'] ?? null;
        if ($expiresAt !== null) {
            if (!is_string($expiresAt)) {
                return Response::json(['success' => false, 'message' => 'Invalid API key expiration'], 401);
            }
            if (DateHelper::isPast($expiresAt)) {
                return Response::json(['success' => false, 'message' => 'API key expired'], 401);
            }
        }

        if (!isset($apiKey['merchant_id']) || !is_scalar($apiKey['merchant_id']) ||
            !isset($apiKey['id']) || !is_scalar($apiKey['id'])) {
            return Response::json(['success' => false, 'message' => 'Invalid API key metadata'], 401);
        }

        $merchantId = (int) $apiKey['merchant_id'];
        $apiKeyId = (int) $apiKey['id'];

        // Enforce API key scope verification (read vs write)
        $scopesRaw = $apiKey['scopes'] ?? null;
        $scopes = [];
        if (is_string($scopesRaw)) {
            $scopes = json_decode($scopesRaw, true);
        } elseif (is_array($scopesRaw)) {
            $scopes = $scopesRaw;
        }
        if (!is_array($scopes)) {
            // Fail closed: an API key whose scopes cannot be parsed is granted nothing.
            $scopes = [];
        }

        $method = $request->method();
        $requiredScope = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) ? 'read' : 'write';

        if (!in_array($requiredScope, $scopes, true)) {
            return Response::json([
                'success' => false,
                'message' => "Insufficient scope. Required: {$requiredScope}",
            ], 403);
        }

        // Inject merchant context into request
        $request->setAttribute('api_key', $apiKey);
        $request->setAttribute('merchant_id', $merchantId);

        // Check active merchant status
        $merchantRepo = $this->container->get(\OwnPay\Repository\MerchantRepository::class);
        if (!$merchantRepo instanceof \OwnPay\Repository\MerchantRepository) {
            throw new \RuntimeException("MerchantRepository not found in container");
        }
        $merchant = $merchantRepo->find($merchantId);
        if ($merchant === null || ($merchant['status'] ?? 'active') !== 'active') {
            return Response::json([
                'success' => false,
                'message' => 'Merchant account is suspended or inactive',
            ], 403);
        }

        // Touch last_used (fire-and-forget)
        $repo->touchLastUsed($apiKeyId);

        return $next($request);
    }
}
