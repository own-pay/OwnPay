<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_webhook_events.
 *
 * BUG-21 FIX: Corrected all column references to match schema.
 * Schema: id, webhook_id, event_type, payload, status, attempts,
 *         last_attempt_at, next_retry_at, created_at.
 * NO: event_id, merchant_id, updated_at columns.
 */
final class WebhookEventRepository extends BaseRepository
{
    use TenantScope;
    protected string $table = 'op_webhook_events';
    protected array $fillable = [
        'webhook_id', 'event_type', 'payload', 'status',
        'attempts', 'last_attempt_at', 'next_retry_at',
    ];

    /**
     * Find event by webhook ID and event type.
     * BUG-21 FIX: Schema has no 'event_id' column. Use webhook_id + id.
     */
    public function findByWebhookAndId(int $webhookId, int $eventId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE id = :eid AND webhook_id = :wid LIMIT 1",
            ['eid' => $eventId, 'wid' => $webhookId]
        );
    }

    /**
     * Update event status by ID.
     * BUG-21 FIX: No 'updated_at' column. Track via last_attempt_at + attempts.
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
     * Find events by merchant.
     * BUG-21 FIX: No 'merchant_id' column. JOIN with op_webhooks.
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
     * Count failed events for a merchant.
     * BUG-21 FIX: No 'merchant_id' column. JOIN with op_webhooks.
     */
    public function countFailedByMerchant(int $merchantId): int
    {
        $row = $this->db->fetchOne("
            SELECT COUNT(*) as cnt FROM {$this->table} we
            INNER JOIN op_webhooks w ON we.webhook_id = w.id
            WHERE w.merchant_id = :mid AND we.status = 'failed'
        ", ['mid' => $merchantId]);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Find events pending retry.
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
