<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\IdempotencyService;

/**
 * CHK-007: Idempotency middleware for API payment endpoints.
 *
 * Intercepts Idempotency-Key header. Returns cached response for duplicate
 * requests. Scoped per merchant.
 */
final class IdempotencyMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        // Only enforce on mutating methods
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === '') {
            return $next($request);
        }

        // Validate key format (max 64 chars, alphanumeric + dashes)
        if (strlen($idempotencyKey) > 64 || !preg_match('/^[a-zA-Z0-9\-_]+$/', $idempotencyKey)) {
            return Response::json([
                'success' => false,
                'error'   => 'Invalid Idempotency-Key format. Max 64 chars, alphanumeric/dash/underscore.',
            ], 400);
        }

        $merchantId = (int) $request->getAttribute('merchant_id');
        if ($merchantId <= 0) {
            return $next($request);
        }

        $svc = $this->container->get(IdempotencyService::class);
        $scope = 'api:' . $request->path();

        $result = $svc->check($scope, $idempotencyKey, $merchantId);

        if ($result['is_duplicate']) {
            if (($result['status'] ?? '') === 'processing') {
                return Response::json([
                    'success' => false,
                    'error'   => 'Request with this Idempotency-Key is still processing.',
                ], 409);
            }

            // Return cached response
            return Response::json(
                $result['cached_response'] ?? ['success' => true],
                (int) ($result['http_status'] ?? 200)
            );
        }

        try {
            /** @var Response $response */
            $response = $next($request);

            // Cache response body
            $body = json_decode($response->getBody(), true) ?: [];
            $svc->storeResponse($scope, $idempotencyKey, $merchantId, $response->getStatusCode(), $body);

            return $response;
        } catch (\Throwable $e) {
            // Don't cache error responses — allow retry
            throw $e;
        }
    }
}
