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
            if ($existing['request_hash'] !== $requestHash) {
                return [
                    'is_duplicate' => true,
                    'status' => 'error',
                    'error' => 'Idempotency key collision detected (different request payload).'
                ];
            }

            if ($existing['response_code'] === null) {
                return [
                    'is_duplicate' => true,
                    'status' => 'processing',
                ];
            }

            $responseBody = $existing['response_body'] ?? '{}';
            $decoded = json_decode(is_string($responseBody) ? $responseBody : '{}', true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $cachedResponse = [];
            foreach ($decoded as $k => $v) {
                $cachedResponse[(string)$k] = $v;
            }

            return [
                'is_duplicate' => true,
                'status' => 'completed',
                'cached_response' => $cachedResponse,
                'http_status' => is_scalar($existing['response_code']) ? (int) $existing['response_code'] : 200,
            ];
        }
        $expiresAt = date('Y-m-d H:i:s.u', time() + $ttl);
        try {
            $repo->createScoped([
                'idempotency_key' => $key,
                'request_hash'    => $requestHash,
                'expires_at'      => $expiresAt,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), '1062')) {
                return [
                    'is_duplicate' => true,
                    'status' => 'processing',
                ];
            }
            throw $e;
        }

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
                $idVal = $existing['id'] ?? 0;
                $id = is_scalar($idVal) ? (int) $idVal : 0;
                $repo->complete($id, $json, $statusCode);
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
