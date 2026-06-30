<?php
declare(strict_types=1);

namespace OwnPay\Service\Notification;

/**
 * Service managing administrative alerts.
 *
 * Dispatches and tracks critical system alerts (such as low ledger balances, failed webhooks,
 * or security violations) for merchant brands.
 */
final class AlertService
{
    /**
     * @var \OwnPay\Core\Database Database driver helper wrapper.
     */
    private \OwnPay\Core\Database $db;

    /**
     * Constructs a new AlertService instance.
     *
     * @param \OwnPay\Core\Database $db Database access wrapper.
     */
    public function __construct(\OwnPay\Core\Database $db)
    {
        $this->db = $db;
    }

    /**
     * Creates an administrative alert for a merchant.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $type The categorical type of alert.
     * @param string $title Brief title/summary of the alert.
     * @param string $message Detailed message body.
     * @param string $severity Severity level classification (e.g. 'info', 'warning', 'critical').
     * @return void
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
     * Retrieves a list of unread alerts for a merchant.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param int $limit Maximum number of alert entries to return.
     * @return array<int, array<string, mixed>> List of unread alerts.
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
     * Marks a specific alert as read.
     *
     * @param int $alertId Unique identifier of the alert to update.
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @return void
     */
    public function markRead(int $alertId, int $merchantId): void
    {
        $this->db->update(
            "UPDATE op_alerts SET status = 'read' WHERE id = :id AND merchant_id = :mid",
            ['id' => $alertId, 'mid' => $merchantId]
        );
    }

    /**
     * Marks all unread alerts for a merchant as read.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @return void
     */
    public function markAllRead(int $merchantId): void
    {
        $this->db->update(
            "UPDATE op_alerts SET status = 'read' WHERE merchant_id = :mid AND status = 'unread'",
            ['mid' => $merchantId]
        );
    }

    /**
     * Cleans up historically read alerts older than a given day threshold.
     *
     * @param int $daysOld Threshold in days.
     * @return int Count of deleted database records.
     */
    public function cleanup(int $daysOld = 30): int
    {
        return $this->db->delete(
            "DELETE FROM op_alerts WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY) AND status = 'read'",
            ['days' => $daysOld]
        );
    }
}
