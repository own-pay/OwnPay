<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Service\System\AuditLogger;
use OwnPay\Service\System\Logger;

/**
 * Class RefundReconciliationJob
 *
 * Reconciles refunds stuck in 'pending'. RefundService executes refunds in
 * three phases (prepare → gateway call → reconcile); if the process dies
 * between the gateway call and the reconcile phase, the refund record stays
 * 'pending' forever - and pending refunds withhold the merchant's available
 * ledger balance. Gateway adapters expose no refund-status query, so after a
 * conservative 24h window the refund is marked 'failed': funds-conservative,
 * because failing releases the withheld balance and the merchant can retry,
 * while the audit trail and the fired event surface the case for manual
 * verification against the gateway's dashboard.
 *
 * Fires system hooks:
 * - payment.refund.reconciliation_failed: Dispatched per refund auto-failed by this job.
 *
 * @package OwnPay\Cron
 */
final class RefundReconciliationJob
{
    /**
     * Hours a refund may stay 'pending' before it is considered stale.
     */
    private const STALE_AFTER_HOURS = 24;

    /**
     * Maximum refunds processed per run (backstop against unbounded loops).
     */
    private const BATCH_LIMIT = 100;

    /**
     * @var Database The database connection instance.
     */
    private Database $db;

    /**
     * @var EventManager The system event hook dispatcher.
     */
    private EventManager $events;

    /**
     * @var AuditLogger Compliance audit trail logger.
     */
    private AuditLogger $audit;

    /**
     * @var Logger Application logger.
     */
    private Logger $logger;

    /**
     * RefundReconciliationJob constructor.
     *
     * @param Database $db The database connection instance.
     * @param EventManager $events The system event hook dispatcher.
     * @param AuditLogger $audit Compliance audit trail logger.
     * @param Logger $logger Application logger.
     */
    public function __construct(Database $db, EventManager $events, AuditLogger $audit, Logger $logger)
    {
        $this->db = $db;
        $this->events = $events;
        $this->audit = $audit;
        $this->logger = $logger;
    }

    /**
     * Fails refunds that have been pending longer than the stale window.
     *
     * @return array{failed: int, total: int} Reconciliation execution results.
     */
    public function run(): array
    {
        $stale = $this->db->fetchAll(
            "SELECT id, merchant_id, transaction_id, amount, created_at
             FROM op_refunds
             WHERE status = 'pending'
               AND created_at < DATE_SUB(NOW(), INTERVAL " . self::STALE_AFTER_HOURS . " HOUR)
             ORDER BY created_at ASC
             LIMIT " . self::BATCH_LIMIT
        );

        $failedCount = 0;

        foreach ($stale as $refund) {
            if (!isset($refund['id'], $refund['merchant_id']) || !is_scalar($refund['id']) || !is_scalar($refund['merchant_id'])) {
                continue;
            }
            $refundId = (int) $refund['id'];
            $merchantId = (int) $refund['merchant_id'];

            try {
                $transitioned = $this->db->transaction(function () use ($refundId): bool {
                    $locked = $this->db->fetchOne(
                        "SELECT status FROM op_refunds WHERE id = :id LIMIT 1 FOR UPDATE",
                        ['id' => $refundId]
                    );
                    if ($locked === null || ($locked['status'] ?? '') !== 'pending') {
                        return false;
                    }
                    $this->db->execute(
                        "UPDATE op_refunds SET status = 'failed' WHERE id = :id",
                        ['id' => $refundId]
                    );
                    return true;
                });

                if (!$transitioned) {
                    continue;
                }

                $failedCount++;
                $this->audit->log(
                    $merchantId,
                    null,
                    'refund.reconciliation_failed',
                    'refund',
                    $refundId,
                    ['status' => 'pending'],
                    ['status' => 'failed', 'reason' => 'stale_pending_timeout']
                );
                $this->events->doAction('payment.refund.reconciliation_failed', $refund);
                $this->logger->warning(
                    "Refund #{$refundId} (merchant {$merchantId}) was pending for over " . self::STALE_AFTER_HOURS
                    . "h and has been auto-failed. Verify against the gateway dashboard before retrying."
                );
            } catch (\Throwable $e) {
                $this->logger->error("Refund reconciliation failed for refund #{$refundId}: " . $e->getMessage());
            }
        }

        return ['failed' => $failedCount, 'total' => count($stale)];
    }
}
