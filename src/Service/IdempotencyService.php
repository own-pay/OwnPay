<?php

declare(strict_types=1);

namespace OwnPay\Service;

use OwnPay\Repository\IdempotencyRepository;

/**
 * IdempotencyService — API replay prevention.
 *
 * Flow:
 *   1. Client sends `Idempotency-Key: <uuid>` header
 *   2. acquire() checks if key exists
 *      - If exists + completed → return cached response (replay)
 *      - If exists + processing → return 409 Conflict
 *      - If new → insert + return null (proceed with request)
 *   3. After request completes, call complete() to store response
 *   4. TTL cleanup removes keys older than 24 hours
 */
final class IdempotencyService
{
    private IdempotencyRepository $repo;

    public function __construct(?IdempotencyRepository $repo = null)
    {
        $this->repo = $repo ?? new IdempotencyRepository();
    }

    /**
     * Acquire an idempotency lock.
     *
     * @param string $scope       e.g. 'checkout', 'refund'
     * @param string $key         Client-provided idempotency key
     * @param string $requestHash SHA-256 of the request body
     * @return array{isReplay: bool, isConflict: bool, cachedResponse: ?string, httpStatus: ?int, keyId: int}
     */
    public function acquire(string $scope, string $key, string $requestHash): array
    {
        $existing = $this->repo->findByKey($scope, $key);

        if ($existing !== null) {
            // Key exists — check status
            if ($existing['status'] === 'completed') {
                // Replay: return cached response
                return [
                    'isReplay' => true,
                    'isConflict' => false,
                    'cachedResponse' => $existing['response_payload'],
                    'httpStatus' => (int) $existing['http_status'],
                    'keyId' => (int) $existing['id'],
                ];
            }

            // Still processing — conflict
            return [
                'isReplay' => false,
                'isConflict' => true,
                'cachedResponse' => null,
                'httpStatus' => null,
                'keyId' => (int) $existing['id'],
            ];
        }

        // New key — insert in 'processing' state
        $id = $this->repo->insert([
            'scope' => $scope,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
            'status' => 'processing',
        ]);

        return [
            'isReplay' => false,
            'isConflict' => false,
            'cachedResponse' => null,
            'httpStatus' => null,
            'keyId' => $id,
        ];
    }

    /**
     * Complete an idempotency entry — stores the response for future replays.
     */
    public function complete(int $keyId, string $responsePayload, int $httpStatus): void
    {
        $this->repo->complete($keyId, $responsePayload, $httpStatus);
    }

    /**
     * Clean up expired idempotency keys.
     *
     * @param int $hours Keys older than this many hours are deleted
     * @return int Number of deleted keys
     */
    public function cleanup(int $hours = 24): int
    {
        return $this->repo->cleanup($hours);
    }
}
