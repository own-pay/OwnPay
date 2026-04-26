<?php

declare(strict_types=1);

namespace OwnPay\Service;

/**
 * LegacyIdempotencyBridge — Replay prevention for legacy API endpoints.
 *
 * This bridge uses CrudService to interact with the existing
 * `op_idempotency_keys` table (from master_install.sql).
 *
 * Schema (op_idempotency_keys):
 *   id, scope, merchant_id, idempotency_key, request_hash,
 *   response_code, response_body, locked_at, locked_until,
 *   created_at, expires_at
 *
 * Usage from legacy code:
 *   $idem = new \OwnPay\Service\LegacyIdempotencyBridge($db_prefix);
 *   $result = $idem->acquire('checkout', $brandId, $key, $requestHash);
 *   if ($result['replay']) { echo $result['cached_response']; exit; }
 *   if ($result['conflict']) { http_response_code(409); exit; }
 *   // ... process request ...
 *   $idem->complete($result['row_id'], $responseBody, $httpStatus);
 */
final class LegacyIdempotencyBridge
{
    private string $table;

    public function __construct(string $dbPrefix)
    {
        $this->table = $dbPrefix . 'idempotency_keys';
    }

    /**
     * Attempt to acquire an idempotency lock.
     *
     * @param string $scope     e.g. 'checkout', 'refund'
     * @param string $merchantId Brand/merchant ID from the legacy system
     * @param string $key       Client-provided idempotency key
     * @param string $requestHash SHA-256 of the request body
     * @param int    $ttlHours  Key expiry in hours (default: 24)
     * @return array{replay: bool, conflict: bool, cached_response: ?string, cached_status: ?int, row_id: ?int}
     */
    public function acquire(
        string $scope,
        string $merchantId,
        string $key,
        string $requestHash,
        int $ttlHours = 24
    ): array {
        // Check for existing key
        $params = [
            ':scope' => $scope,
            ':merchant_id' => $merchantId,
            ':idempotency_key' => $key,
        ];

        $existing = CrudService::select(
            $this->table,
            'WHERE scope = :scope AND merchant_id = :merchant_id AND idempotency_key = :idempotency_key',
            '* FROM',
            $params
        );

        if ($existing['status'] === true && !empty($existing['response'])) {
            $row = $existing['response'][0];

            // Key exists with a cached response — replay
            if ($row['response_code'] !== null && $row['response_code'] !== '') {
                return [
                    'replay' => true,
                    'conflict' => false,
                    'cached_response' => $row['response_body'],
                    'cached_status' => (int) $row['response_code'],
                    'row_id' => (int) $row['id'],
                ];
            }

            // Key exists but no response yet — check lock
            if ($row['locked_until'] !== null) {
                $lockExpiry = strtotime($row['locked_until']);
                if ($lockExpiry !== false && $lockExpiry > time()) {
                    // Lock is still active — conflict
                    return [
                        'replay' => false,
                        'conflict' => true,
                        'cached_response' => null,
                        'cached_status' => null,
                        'row_id' => (int) $row['id'],
                    ];
                }
            }

            // Lock expired — re-acquire the lock
            $now = gmdate('Y-m-d H:i:s');
            $lockUntil = gmdate('Y-m-d H:i:s', time() + 30); // 30-second lock
            CrudService::update(
                $this->table,
                ['locked_at', 'locked_until', 'request_hash'],
                [$now, $lockUntil, $requestHash],
                "id = :id",
                [':id' => $row['id']]
            );

            return [
                'replay' => false,
                'conflict' => false,
                'cached_response' => null,
                'cached_status' => null,
                'row_id' => (int) $row['id'],
            ];
        }

        // New key — insert with lock
        $now = gmdate('Y-m-d H:i:s');
        $lockUntil = gmdate('Y-m-d H:i:s', time() + 30);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlHours * 3600));

        $columns = [
            'scope',
            'merchant_id',
            'idempotency_key',
            'request_hash',
            'locked_at',
            'locked_until',
            'created_at',
            'expires_at',
        ];
        $values = [
            $scope,
            $merchantId,
            $key,
            $requestHash,
            $now,
            $lockUntil,
            $now,
            $expiresAt,
        ];

        CrudService::insert($this->table, $columns, $values);

        // Retrieve the inserted row to get the ID
        $inserted = CrudService::select(
            $this->table,
            'WHERE scope = :scope AND merchant_id = :merchant_id AND idempotency_key = :idempotency_key',
            '* FROM',
            $params
        );

        $rowId = 0;
        if ($inserted['status'] === true && !empty($inserted['response'])) {
            $rowId = (int) $inserted['response'][0]['id'];
        }

        return [
            'replay' => false,
            'conflict' => false,
            'cached_response' => null,
            'cached_status' => null,
            'row_id' => $rowId,
        ];
    }

    /**
     * Mark an idempotency key as completed with the response.
     *
     * @param int    $rowId        The idempotency key row ID
     * @param string $responseBody The JSON response payload to cache
     * @param int    $httpStatus   The HTTP status code
     */
    public function complete(int $rowId, string $responseBody, int $httpStatus): void
    {
        CrudService::update(
            $this->table,
            ['response_code', 'response_body', 'locked_at', 'locked_until'],
            [$httpStatus, $responseBody, null, null],
            "id = :id",
            [':id' => $rowId]
        );
    }
}
