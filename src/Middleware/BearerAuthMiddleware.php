<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\ApiKeyRepository;
use OwnPay\Support\DateHelper;

/**
 * Bearer auth middleware — authenticates API requests via API key.
 *
 * Flow: Extract prefix → lookup by prefix → timing-safe hash compare.
 * Per security skill: never log raw keys, use constant-time comparison.
 */
final class BearerAuthMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

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
        if (!hash_equals($apiKey['key_hash'], $keyHash)) {
            return Response::json(['success' => false, 'message' => 'Invalid API key'], 401);
        }

        // H-05 FIX: Check API key status — revoked/inactive keys must be rejected.
        if (($apiKey['status'] ?? 'active') !== 'active') {
            return Response::json(['success' => false, 'message' => 'API key has been revoked'], 401);
        }

        // Check expiry
        if ($apiKey['expires_at'] !== null && DateHelper::isPast($apiKey['expires_at'])) {
            return Response::json(['success' => false, 'message' => 'API key expired'], 401);
        }

        // Inject merchant context into request
        $request->setAttribute('api_key', $apiKey);
        $request->setAttribute('merchant_id', (int) $apiKey['merchant_id']);

        // Touch last_used (fire-and-forget)
        $repo->touchLastUsed((int) $apiKey['id']);

        return $next($request);
    }
}
