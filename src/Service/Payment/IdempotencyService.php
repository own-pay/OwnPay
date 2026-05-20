<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\IdempotencyRepository;

/**
 * Idempotency service — prevents duplicate transaction processing.
 *
 * Uses idempotency key (client-provided) to deduplicate API requests.
 */
final class IdempotencyService
{
    private IdempotencyRepository $repo;

    public function __construct(IdempotencyRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Check if request is duplicate. If not, lock the key.
     *
     * @return array{is_duplicate: bool, cached_response?: array, status?: string, http_status?: int}
     */
    public function check(string $scope, string $key, int $merchantId): array
    {
        $repo = $this->repo->forTenant($merchantId);
        $existing = $repo->findByKey($scope, $key);

        if ($existing !== null) {
            return [
                'is_duplicate' => true,
                'cached_response' => json_decode($existing['response_payload'] ?? '{}', true),
                'status' => (string) ($existing['status'] ?? ''),
                'http_status' => isset($existing['http_status']) ? (int) $existing['http_status'] : 200,
            ];
        }

        // Lock the key
        $repo->createScoped([
            'scope'           => $scope,
            'idempotency_key' => $key,
            'status'          => 'processing',
        ]);

        return ['is_duplicate' => false];
    }

    /**
     * Store response for idempotency key.
     */
    public function storeResponse(string $scope, string $key, int $merchantId, int $statusCode, array $response): void
    {
        $repo = $this->repo->forTenant($merchantId);
        $existing = $repo->findByKey($scope, $key);
        if ($existing !== null) {
            $repo->complete((int) $existing['id'], json_encode($response), $statusCode);
        }
    }

    /**
     * Cleanup expired idempotency records (cron).
     */
    public function cleanup(int $hoursOld = 24): int
    {
        return $this->repo->cleanup($hoursOld);
    }
}
