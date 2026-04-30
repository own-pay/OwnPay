<?php
declare(strict_types=1);

namespace OwnPay\Service\Notification;

/**
 * Alert service — admin alerts (low balance, failed webhooks, security events).
 */
final class AlertService
{
    private \OwnPay\Core\Database $db;

    public function __construct(\OwnPay\Core\Database $db)
    {
        $this->db = $db;
    }

    /**
     * Create alert for merchant admin.
     */
    public function create(int $merchantId, string $type, string $title, string $message, string $severity = 'info'): void
    {
        $this->db->insert(
            "INSERT INTO op_alerts (merchant_id, type, title, message, severity, status, created_at)
             VALUES (:mid, :type, :title, :msg, :sev, 'unread', NOW())",
            ['mid' => $merchantId, 'type' => $type, 'title' => $title, 'msg' => $message, 'sev' => $severity]
        );
    }

    /**
     * Get unread alerts.
     */
    public function getUnread(int $merchantId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM op_alerts WHERE merchant_id = :mid AND status = 'unread'
             ORDER BY created_at DESC LIMIT {$limit}",
            ['mid' => $merchantId]
        );
    }

    /**
     * Mark alert as read.
     */
    public function markRead(int $alertId, int $merchantId): void
    {
        $this->db->update(
            "UPDATE op_alerts SET status = 'read' WHERE id = :id AND merchant_id = :mid",
            ['id' => $alertId, 'mid' => $merchantId]
        );
    }

    /**
     * Mark all as read for merchant.
     */
    public function markAllRead(int $merchantId): void
    {
        $this->db->update(
            "UPDATE op_alerts SET status = 'read' WHERE merchant_id = :mid AND status = 'unread'",
            ['mid' => $merchantId]
        );
    }

    /**
     * Cleanup old alerts (cron).
     */
    public function cleanup(int $daysOld = 30): int
    {
        return $this->db->delete(
            "DELETE FROM op_alerts WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY) AND status = 'read'",
            ['days' => $daysOld]
        );
    }
}
