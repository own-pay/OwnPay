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
     * @return array{is_duplicate: bool, cached_response?: array}
     */
    public function check(string $key, int $merchantId): array
    {
        $existing = $this->repo->findByKey($key, $merchantId);

        if ($existing !== null) {
            return [
                'is_duplicate' => true,
                'cached_response' => json_decode($existing['response_body'] ?? '{}', true),
            ];
        }

        // Lock the key
        $this->repo->create([
            'merchant_id'     => $merchantId,
            'idempotency_key' => $key,
            'status'          => 'processing',
        ]);

        return ['is_duplicate' => false];
    }

    /**
     * Store response for idempotency key.
     */
    public function storeResponse(string $key, int $merchantId, int $statusCode, array $response): void
    {
        $existing = $this->repo->findByKey($key, $merchantId);
        if ($existing !== null) {
            $this->repo->update((int) $existing['id'], [
                'response_code' => $statusCode,
                'response_body' => json_encode($response),
                'status'        => 'completed',
            ]);
        }
    }

    /**
     * Cleanup expired idempotency records (cron).
     */
    public function cleanup(int $hoursOld = 24): int
    {
        return $this->repo->cleanOlderThan($hoursOld);
    }
}
