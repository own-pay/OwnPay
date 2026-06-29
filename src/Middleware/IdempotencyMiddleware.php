<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\IdempotencyService;

/**
 * Middleware handling idempotency checks for mutating API endpoints.
 *
 * Ensures that identical requests containing an `Idempotency-Key` are processed exactly once,
 * returning cached responses for duplicates and managing request locks.
 */
final class IdempotencyMiddleware
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $container;

    /**
     * Constructs a new IdempotencyMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Handles idempotency checking on incoming HTTP requests.
     *
     * Scopes requests based on the `Idempotency-Key` header and the active merchant.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     * @throws \Throwable If downstream request handling fails.
     */
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

        $midVal = $request->getAttribute('merchant_id');
        $merchantId = is_scalar($midVal) ? (int) $midVal : 0;
        if ($merchantId <= 0) {
            return $next($request);
        }

        $svc = $this->container->get(IdempotencyService::class);
        if (!$svc instanceof IdempotencyService) {
            throw new \RuntimeException("IdempotencyService not found in container");
        }
        
        // Compute request signature to prevent false replay collisions
        $requestHash = hash('sha256', $request->method() . "\n" . $request->uri() . "\n" . ($request->rawBody() ?? ''));

        $result = $svc->check($idempotencyKey, $merchantId, $requestHash);

        if ($result['is_duplicate']) {
            if (($result['status'] ?? '') === 'processing') {
                return Response::json([
                    'success' => false,
                    'error'   => 'Request with this Idempotency-Key is still processing.',
                ], 409);
            }

            if (($result['status'] ?? '') === 'error') {
                return Response::json([
                    'success' => false,
                    'error'   => $result['error'] ?? 'Idempotency key collision.',
                ], 400);
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

            // Only cache successful responses (2xx) - don't cache transient server errors
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $bodyDecoded = json_decode($response->getBody(), true);
                $body = is_array($bodyDecoded) ? $bodyDecoded : [];
                $svc->storeResponse($idempotencyKey, $merchantId, $response->getStatusCode(), $body);
            } else {
                // Delete lock on non-2xx response status to allow retry
                $svc->deleteLock($idempotencyKey, $merchantId);
            }

            return $response;
        } catch (\Throwable $e) {
            // Delete lock on exception to allow retry
            $svc->deleteLock($idempotencyKey, $merchantId);
            throw $e;
        }
    }
}
