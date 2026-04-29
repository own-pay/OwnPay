<?php

declare(strict_types=1);

namespace OwnPay\Service\Notification;

use OwnPay\Core\Database;

/**
 * AlertService — mismatch notification system.
 *
 * Severity levels: info, warning, critical
 * Channels: DB log (always), outbound webhook (configurable)
 * Rate-limited: max 10 alerts per type per hour to prevent floods.
 */
final class AlertService
{
    private const MAX_ALERTS_PER_HOUR = 10;

    private Database $db;
    private ?WebhookService $webhooks;

    public function __construct(?Database $db = null, ?WebhookService $webhooks = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->webhooks = $webhooks;
    }

    /**
     * Fire an alert.
     *
     * @param string $type     Alert type (e.g. 'reconciliation.mismatch')
     * @param string $severity 'info', 'warning', 'critical'
     * @param string $message  Human-readable description
     * @param array  $context  Additional data
     * @param int    $merchantId
     * @return bool Whether the alert was recorded (false if rate-limited)
     */
    public function fire(
        string $type,
        string $severity,
        string $message,
        array $context = [],
        int $merchantId = 0
    ): bool {
        // Rate limiting check
        if ($this->isRateLimited($type, $merchantId)) {
            return false;
        }

        try {
            // Store in DB
            $this->db->execute("
                INSERT INTO op_alerts
                    (merchant_id, alert_type, severity, message, context, status, created_at)
                VALUES (:mid, :type, :sev, :msg, :ctx, 'open', NOW(6))
            ", [
                ':mid' => $merchantId,
                ':type' => $type,
                ':sev' => $severity,
                ':msg' => $message,
                ':ctx' => json_encode($context),
            ]);

            // Critical alerts → webhook notification
            if ($severity === 'critical' && $this->webhooks !== null && $merchantId > 0) {
                $this->webhooks->dispatch($merchantId, 'alert.critical', [
                    'type' => $type,
                    'message' => $message,
                    'context' => $context,
                ]);
            }

            return true;
        } catch (\PDOException $e) {
            error_log("[Alert] Failed to record alert: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Convenience: fire reconciliation mismatch alerts from a report.
     */
    public function fireFromReconciliation(array $report): int
    {
        $fired = 0;
        $merchantId = (int) ($report['merchant_id'] ?? 0);

        foreach ($report['mismatches'] ?? [] as $mismatch) {
            $this->fire(
                'reconciliation.' . ($mismatch['type'] ?? 'unknown'),
                $mismatch['severity'] ?? 'warning',
                $mismatch['detail'] ?? json_encode($mismatch),
                $mismatch,
                $merchantId
            );
            $fired++;
        }

        return $fired;
    }

    /**
     * Acknowledge (close) an alert.
     */
    public function acknowledge(int $alertId, string $acknowledgedBy = 'system'): void
    {
        $this->db->execute("
            UPDATE op_alerts
            SET status = 'acknowledged', acknowledged_by = :by, acknowledged_at = NOW(6)
            WHERE id = :id AND status = 'open'
        ", [':by' => $acknowledgedBy, ':id' => $alertId]);
    }

    /**
     * Get open alerts for a merchant.
     */
    public function getOpenAlerts(int $merchantId, int $limit = 50): array
    {
        // Database::execute() with emulate_prepares=false requires typed bind for LIMIT,
        // so we use getPdo() for bindValue with explicit PDO::PARAM_INT on the LIMIT param.
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM op_alerts
            WHERE (merchant_id = :mid OR merchant_id = 0) AND status = 'open'
            ORDER BY FIELD(severity, 'critical', 'warning', 'info'), created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':mid', $merchantId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count open alerts by severity.
     */
    public function countBySeverity(int $merchantId = 0): array
    {
        $rows = $this->db->fetchAll("
            SELECT severity, COUNT(*) AS cnt
            FROM op_alerts
            WHERE (merchant_id = :mid OR merchant_id = 0) AND status = 'open'
            GROUP BY severity
        ", [':mid' => $merchantId]);

        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($rows as $row) {
            $counts[$row['severity']] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Check if alert type is rate-limited.
     */
    private function isRateLimited(string $type, int $merchantId): bool
    {
        try {
            $count = $this->db->fetchColumn("
                SELECT COUNT(*) FROM op_alerts
                WHERE alert_type = :type AND merchant_id = :mid
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ", [':type' => $type, ':mid' => $merchantId]);
            return (int) $count >= self::MAX_ALERTS_PER_HOUR;
        } catch (\PDOException $e) {
            return false;
        }
    }
}
