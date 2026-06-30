<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository class responsible for database operations, persistence, and lookup
 * of outgoing webhook events within the 'op_webhook_events' table.
 *
 * Webhook events track payload delivery states, retry intervals, and historical delivery logs
 * for integration destinations. Note that since the 'op_webhook_events' schema does not
 * feature a direct 'merchant_id' column, brand isolation is resolved by performing joins with
 * the 'op_webhooks' table.
 */
final class WebhookEventRepository extends BaseRepository
{
    use TenantScope;

    /**
     * The database table name associated with this repository.
     *
     * @var string
     */
    protected string $table = 'op_webhook_events';

    /**
     * The list of columns that are safe to be bulk-filled on insertion or update.
     *
     * @var array<int, string>
     */
    protected array $fillable = [
        'webhook_id', 'event_type', 'payload', 'status',
        'attempts', 'last_attempt_at', 'next_retry_at',
    ];

    /**
     * Resolves a specific webhook event by its internal identifier and associated webhook ID.
     *
     * @param int $webhookId The internal primary key of the parent webhook endpoint.
     * @param int $eventId The internal primary key of the webhook event.
     * @return array<string, mixed>|null The webhook event record, or null if not found.
     */
    public function findByWebhookAndId(int $webhookId, int $eventId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE id = :eid AND webhook_id = :wid LIMIT 1",
            ['eid' => $eventId, 'wid' => $webhookId]
        );
    }

    /**
     * Updates the delivery status of a webhook event record.
     *
     * Increments the delivery attempt counter and logs the current timestamp as the last attempt.
     *
     * @param int $id The internal primary key identifier of the webhook event.
     * @param string $status The target status code (e.g., 'success', 'failed').
     * @return void
     */
    public function updateStatus(int $id, string $status): void
    {
        $this->db->execute("
            UPDATE {$this->table}
            SET status = :st, last_attempt_at = NOW(6), attempts = attempts + 1
            WHERE id = :id
        ", ['st' => $status, 'id' => $id]);
    }

    /**
     * Updates the detailed retry state of a webhook event.
     *
     * @param int $id The webhook event ID.
     * @param string $status The new status ('pending', 'delivered', 'failed').
     * @param int $attempts The number of attempts made.
     * @param string|null $nextRetryAt ISO-8601 formatted date/time string or null.
     * @return void
     */
    public function updateRetryState(int $id, string $status, int $attempts, ?string $nextRetryAt): void
    {
        $this->db->execute("
            UPDATE {$this->table}
            SET status = :st, last_attempt_at = NOW(6), attempts = :att, next_retry_at = :next
            WHERE id = :id
        ", [
            'st'   => $status,
            'att'  => $attempts,
            'next' => $nextRetryAt,
            'id'   => $id,
        ]);
    }

    /**
     * Inserts a record into the webhook delivery logs.
     *
     * @param int $eventId The webhook event ID.
     * @param int|null $responseCode HTTP status code returned.
     * @param string|null $responseBody Raw response body content.
     * @param int|null $durationMs Execution duration in milliseconds.
     * @param string|null $error Error message string.
     * @return void
     */
    public function logDelivery(
        int $eventId,
        ?int $responseCode,
        ?string $responseBody,
        ?int $durationMs,
        ?string $error
    ): void {
        $this->db->execute("
            INSERT INTO `op_webhook_delivery_logs` (webhook_event_id, response_code, response_body, duration_ms, error)
            VALUES (:eid, :code, :body, :dur, :err)
        ", [
            'eid'  => $eventId,
            'code' => $responseCode,
            'body' => $responseBody !== null ? mb_substr($responseBody, 0, 65535) : null,
            'dur'  => $durationMs,
            'err'  => $error !== null ? mb_substr($error, 0, 500) : null,
        ]);
    }

    /**
     * Retrieves all webhook events recorded for a specific merchant brand with pagination.
     *
     * Performs an inner join with the 'op_webhooks' table to resolve the merchant relationship.
     *
     * @param int $merchantId The unique identifier of the merchant brand.
     * @param int $limit Maximum number of event records to retrieve. Defaults to 50.
     * @param int $offset Numerical offset for database query pagination. Defaults to 0.
     * @return array<int, array<string, mixed>> List of matching webhook event records.
     */
    public function findByMerchant(int $merchantId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll("
            SELECT we.* FROM {$this->table} we
            INNER JOIN op_webhooks w ON we.webhook_id = w.id
            WHERE w.merchant_id = :mid
            ORDER BY we.created_at DESC
            LIMIT :lim OFFSET :off
        ", ['mid' => $merchantId, 'lim' => $limit, 'off' => $offset]);
    }

    /**
     * Counts the total number of failed webhook events recorded for a specific merchant brand.
     *
     * Performs an inner join with the 'op_webhooks' table to filter events under the merchant scope.
     *
     * @param int $merchantId The unique identifier of the merchant brand.
     * @return int The total count of failed webhook event deliveries.
     */
    public function countFailedByMerchant(int $merchantId): int
    {
        $row = $this->db->fetchOne("
            SELECT COUNT(*) as cnt FROM {$this->table} we
            INNER JOIN op_webhooks w ON we.webhook_id = w.id
            WHERE w.merchant_id = :mid AND we.status = 'failed'
        ", ['mid' => $merchantId]);
        $cntVal = $row['cnt'] ?? 0;
        return is_scalar($cntVal) ? (int) $cntVal : 0;
    }

    /**
     * Retrieves a list of failed webhook events that are scheduled for delivery retries.
     *
     * @param int $limit Maximum number of retry events to return. Defaults to 50.
     * @return array<int, array<string, mixed>> List of webhook events pending retry.
     */
    public function findPendingRetries(int $limit = 50): array
    {
        return $this->db->fetchAll("
            SELECT * FROM {$this->table}
            WHERE status = 'failed' AND next_retry_at IS NOT NULL AND next_retry_at <= NOW(6)
            ORDER BY next_retry_at ASC
            LIMIT :lim
        ", ['lim' => $limit]);
    }

    /**
     * Lists webhook events with sorting and pagination, optionally scoped by merchant ID.
     *
     * Joins op_webhooks to resolve merchant context and url details.
     *
     * @param int|null $merchantId Scoping merchant ID context, or null for all merchants.
     * @param int $limit Maximum records to return.
     * @param int $offset Records offset.
     * @return array<int, array<string, mixed>> List of webhook event records.
     */
    public function listPaginated(?int $merchantId, int $limit, int $offset): array
    {
        $where = $merchantId !== null ? 'WHERE w.merchant_id = :mid' : '';
        $params = $merchantId !== null ? ['mid' => $merchantId] : [];
        $params['lim'] = $limit;
        $params['off'] = $offset;

        return $this->db->fetchAll(
            "SELECT we.*, w.url as webhook_url
             FROM {$this->table} we
             INNER JOIN op_webhooks w ON we.webhook_id = w.id
             {$where}
             ORDER BY we.created_at DESC
             LIMIT :lim OFFSET :off",
            $params
        );
    }

    /**
     * Counts the total webhook events matching criteria.
     *
     * @param int|null $merchantId Scoping merchant ID context, or null for all merchants.
     * @return int Matching records count.
     */
    public function countFiltered(?int $merchantId): int
    {
        $where = $merchantId !== null ? 'INNER JOIN op_webhooks w ON we.webhook_id = w.id WHERE w.merchant_id = :mid' : '';
        $params = $merchantId !== null ? ['mid' => $merchantId] : [];

        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM {$this->table} we {$where}",
            $params
        );
        $cntVal = $row['cnt'] ?? 0;
        return is_scalar($cntVal) ? (int)$cntVal : 0;
    }
}
