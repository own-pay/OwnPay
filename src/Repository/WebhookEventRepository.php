<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_webhook_events table.
 */
final class WebhookEventRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_webhook_events';

    /**
     * Find an event by its unique event ID (for deduplication).
     */
    public function findByEventId(string $eventId): ?array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM {$this->table} WHERE event_id = :eid LIMIT 1");
        $stmt->execute([':eid' => $eventId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Update event status by event_id.
     */
    public function updateStatusByEventId(string $eventId, string $status): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare("
            UPDATE {$this->table}
            SET status = :st, updated_at = NOW(6)
            WHERE event_id = :eid
        ")->execute([':st' => $status, ':eid' => $eventId]);
    }

    /**
     * List events by merchant with pagination.
     */
    public function findByMerchant(int $merchantId, int $limit = 50, int $offset = 0): array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE merchant_id = :mid
            ORDER BY created_at DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':mid', $merchantId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count failed events for monitoring/alerting.
     */
    public function countFailedByMerchant(int $merchantId): int
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM {$this->table}
            WHERE merchant_id = :mid AND status = 'failed'
        ");
        $stmt->execute([':mid' => $merchantId]);
        return (int) $stmt->fetchColumn();
    }
}
