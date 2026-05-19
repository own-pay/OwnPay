<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Http\Request;

/**
 * Idempotency bridge — middleware helper to extract and enforce idempotency.
 */
final class IdempotencyBridge
{
    private IdempotencyService $service;

    public function __construct(IdempotencyService $service)
    {
        $this->service = $service;
    }

    /**
     * Extract idempotency key from request.
     */
    public function extractKey(Request $request): string
    {
        return $request->header('Idempotency-Key') ?: $request->header('X-Idempotency-Key') ?: '';
    }

    /**
     * Check idempotency for API request.
     * @return array{is_duplicate: bool, cached_response?: array}|null Null if no key provided
     */
    public function checkRequest(Request $request, string $scope = 'api'): ?array
    {
        $key = $this->extractKey($request);
        /** @phpstan-ignore-next-line */
        if ($key === null || $key === '') {
            return null; // No idempotency requested
        }

        $merchantId = $request->getAttribute('merchant_id');
        /** @phpstan-ignore-next-line */
        if ($merchantId === null) {
            return null;
        }

        return $this->service->check($scope, $key, (int) $merchantId);
    }

    /**
     * Store response for future duplicate requests.
     */
    public function storeResponse(Request $request, int $statusCode, array $response, string $scope = 'api'): void
    {
        $key = $this->extractKey($request);
        $merchantId = $request->getAttribute('merchant_id');

        /** @phpstan-ignore-next-line */
        if ($key !== null && $merchantId !== null) {
            $this->service->storeResponse($scope, $key, (int) $merchantId, $statusCode, $response);
        }
    }
}
