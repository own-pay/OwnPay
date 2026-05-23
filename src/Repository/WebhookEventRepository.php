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
}
