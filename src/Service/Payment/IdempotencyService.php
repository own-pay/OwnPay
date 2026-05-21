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
     * @return array{is_duplicate: bool, cached_response?: array, status?: string, http_status?: int, error?: string}
     */
    public function check(string $key, int $merchantId, string $requestHash, int $ttl = 86400): array
    {
        $repo = $this->repo->forTenant($merchantId);
        $existing = $repo->findByKey($key);

        if ($existing !== null) {
            // Check request hash to prevent collision/tampering
            if ($existing['request_hash'] !== $requestHash) {
                return [
                    'is_duplicate' => true,
                    'status' => 'error',
                    'error' => 'Idempotency key collision detected (different request payload).'
                ];
            }
            
            // Interpret NULL response_code as 'processing' status
            if ($existing['response_code'] === null) {
                return [
                    'is_duplicate' => true,
                    'status' => 'processing',
                ];
            }

            return [
                'is_duplicate' => true,
                'status' => 'completed',
                'cached_response' => json_decode($existing['response_body'] ?? '{}', true),
                'http_status' => (int) $existing['response_code'],
            ];
        }

        // Lock the key (insert with null response_code and response_body)
        $expiresAt = date('Y-m-d H:i:s.u', time() + $ttl);
        $repo->createScoped([
            'idempotency_key' => $key,
            'request_hash'    => $requestHash,
            'expires_at'      => $expiresAt,
        ]);

        return ['is_duplicate' => false];
    }

    /**
     * Store response for idempotency key.
     */
    public function storeResponse(string $key, int $merchantId, int $statusCode, array $response): void
    {
        $repo = $this->repo->forTenant($merchantId);
        $existing = $repo->findByKey($key);
        if ($existing !== null) {
            $repo->complete((int) $existing['id'], json_encode($response), $statusCode);
        }
    }

    /**
     * Delete an idempotency key lock (called on request failure to allow retry).
     */
    public function deleteLock(string $key, int $merchantId): void
    {
        $this->repo->forTenant($merchantId)->deleteKey($key);
    }

    /**
     * Cleanup expired idempotency records (cron).
     */
    public function cleanup(int $hoursOld = 24): int
    {
        return $this->repo->cleanup($hoursOld);
    }
}
