<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\IdempotencyRepository;

/**
 * Manages request idempotency to prevent duplicate transaction processing.
 *
 * Employs client-provided unique idempotency keys combined with request body hashing
 * to lock, track, and cache API response payloads.
 */
final class IdempotencyService
{
    /**
     * @var IdempotencyRepository Repository for managing idempotency database keys.
     */
    private IdempotencyRepository $repo;

    /**
     * IdempotencyService constructor.
     *
     * @param IdempotencyRepository $repo The repository for storing idempotency state.
     */
    public function __construct(IdempotencyRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Assesses whether an incoming request is a duplicate using its idempotency key.
     *
     * If the key already exists, verifies the payload hash to prevent collision/tampering.
     * If the key is new, registers a lock in a 'processing' state.
     *
     * @param string $key The client-supplied idempotency token.
     * @param int $merchantId The identifier of the merchant/brand.
     * @param string $requestHash A SHA-256 hash representing the request payload.
     * @param int $ttl Time to live in seconds (default is 86400 / 24 hours).
     * @return array{is_duplicate: bool, cached_response?: array<string, mixed>, status?: string, http_status?: int, error?: string} Execution outcome state.
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
     * Stores a finalized HTTP response payload for an active idempotency key lock.
     *
     * @param string $key The client-supplied idempotency token.
     * @param int $merchantId The identifier of the merchant/brand.
     * @param int $statusCode The HTTP response status code to cache.
     * @param array<string, mixed> $response The structured array response payload to serialize.
     * @return void
     */
    public function storeResponse(string $key, int $merchantId, int $statusCode, array $response): void
    {
        $repo = $this->repo->forTenant($merchantId);
        $existing = $repo->findByKey($key);
        if ($existing !== null) {
            $json = json_encode($response);
            if (is_string($json)) {
                $repo->complete((int) $existing['id'], $json, $statusCode);
            }
        }
    }

    /**
     * Deletes an idempotency key lock.
     *
     * Should be invoked when a request fails prior to final execution, permitting retries.
     *
     * @param string $key The client-supplied idempotency token.
     * @param int $merchantId The identifier of the merchant/brand.
     * @return void
     */
    public function deleteLock(string $key, int $merchantId): void
    {
        $this->repo->forTenant($merchantId)->deleteKey($key);
    }

    /**
     * Deletes expired idempotency keys from the database.
     *
     * @param int $hoursOld Age threshold in hours.
     * @return int The total number of rows removed.
     */
    public function cleanup(int $hoursOld = 24): int
    {
        return $this->repo->cleanup($hoursOld);
    }
}
