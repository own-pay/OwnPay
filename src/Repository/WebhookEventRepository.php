<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_webhook_events table.
 */
final class WebhookEventRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_webhook_events';

    /**
     * Find an event by its unique event ID (for deduplication).
     */
    public function findByEventId(string $eventId): ?array
    {
        return $this->db->fetchOne("SELECT * FROM {$this->table} WHERE event_id = :eid AND merchant_id = :mid LIMIT 1", [
            'eid' => $eventId,
            'mid' => $this->requireTenant()
        ]);
    }

    /**
     * Update event status by event_id.
     */
    public function updateStatusByEventId(string $eventId, string $status): void
    {
        $this->db->execute("
            UPDATE {$this->table}
            SET status = :st, updated_at = NOW(6)
            WHERE event_id = :eid AND merchant_id = :mid
        ", [
            'st' => $status,
            'eid' => $eventId,
            'mid' => $this->requireTenant()
        ]);
    }

    /**
     * List events by merchant with pagination.
     */
    public function findByMerchant(int $merchantId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll("
            SELECT * FROM {$this->table}
            WHERE merchant_id = :mid
            ORDER BY created_at DESC
            LIMIT :lim OFFSET :off
        ", [
            'mid' => $merchantId,
            'lim' => $limit,
            'off' => $offset
        ]);
    }

    /**
     * Count failed events for monitoring/alerting.
     */
    public function countFailedByMerchant(int $merchantId): int
    {
        return $this->db->count($this->table, "merchant_id = :mid AND status = 'failed'", ['mid' => $merchantId]);
    }
}
