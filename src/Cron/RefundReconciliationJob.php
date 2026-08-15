<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\GatewayBridge;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Service\Payment\LedgerService;
use OwnPay\Service\System\AuditLogger;
use OwnPay\Service\System\Logger;
use OwnPay\Support\DateHelper;

/**
 * Class RefundReconciliationJob
 *
 * Reconciles refunds stuck in 'pending'. RefundService executes refunds in
 * three phases (prepare -> gateway call -> reconcile); if the process dies
 * between the gateway call and the reconcile phase, the refund record stays
 * 'pending' forever - and pending refunds withhold the merchant's available
 * ledger balance.
 *
 * Issue #340 (PAY-12): the job now runs in two phases.
 *
 *   Phase 1 (gateway probe, 30-minute window):
 *     For each refund that has been pending for >= 30 minutes, look up the
 *     linked transaction's gateway_slug and gateway_trx_id, and - when the
 *     gateway adapter exposes a refund-status query (supportsRefundStatus()
 *     === true) - ask the gateway for the authoritative refund status:
 *       - 'succeeded'  -> mark the refund 'completed', post the ledger entry.
 *       - 'failed'     -> mark the refund 'failed' (releases the balance hold).
 *       - 'not_found'  -> mark the refund 'failed' (the gateway never recorded
 *                         it - safe to release the hold).
 *       - 'pending'    -> leave it pending; the gateway is still processing.
 *       - null/unknown -> log a warning and skip; do NOT auto-fail (the
 *                         gateway API being unavailable does not mean the
 *                         refund failed, and auto-failing could release the
 *                         balance hold on a refund that is still in flight).
 *
 *   Phase 2 (24-hour stale-pending backstop):
 *     Refunds still 'pending' after 24 hours are marked 'pending_verification'
 *     (a new status introduced by audit fix CRON-7). Funds-conservative:
 *     unlike the previous 'failed' transition, 'pending_verification'
 *     PRESERVES the withheld balance so the merchant cannot accidentally
 *     double-refund by retrying. The refund is surfaced for explicit admin
 *     review against the gateway's dashboard before any balance release.
 *     The previous auto-fail behaviour risked double-refunding: if the
 *     gateway had actually processed the refund but the local process died
 *     before the reconcile phase, the customer got their money back AND the
 *     merchant's withheld balance was released - so a merchant retry would
 *     send a second refund.
 *
 * Fires system hooks:
 * - payment.refund.requires_verification: Dispatched per refund marked 'pending_verification' by Phase 2.
 * - payment.refund.reconciliation_failed: Dispatched per refund that Phase 1 explicitly fails after the gateway reports 'failed' or 'not_found'.
 * - payment.refund.reconciliation_completed: Dispatched per refund reconciled to 'completed' by Phase 1.
 *
 * @package OwnPay\Cron
 */
final class RefundReconciliationJob
{
    /**
     * Hours a refund may stay 'pending' before it is considered stale and
     * auto-failed by the Phase 2 backstop.
     */
    private const STALE_AFTER_HOURS = 24;

    /**
     * Minutes a refund must have been 'pending' before Phase 1 will attempt
     * to query the gateway for its authoritative status. The 30-minute window
     * gives the gateway plenty of time to settle the refund one way or the
     * other (most adapters confirm within seconds, but some bank-rail refunds
     * take longer) while still catching crashes well before the 24-hour
     * backstop would otherwise kick in.
     */
    private const GATEWAY_PROBE_AFTER_MINUTES = 30;

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
     * @var GatewayBridge|null Gateway bridge for refund-status queries. Null when
     *                         gateway probing is unavailable (e.g. test environments
     *                         that wire the job without a full DI graph).
     */
    private ?GatewayBridge $bridge;

    /**
     * @var LedgerService|null Ledger service used to post the refund entry when
     *                          Phase 1 confirms a refund 'succeeded' at the gateway.
     *                          Null in test environments without a full DI graph.
     */
    private ?LedgerService $ledger;

    /**
     * @var TransactionRepository|null Transaction lookup for resolving the
     *                                  gateway_slug and gateway_trx_id associated
     *                                  with a pending refund. Null in test
     *                                  environments without a full DI graph.
     */
    private ?TransactionRepository $transactions;

    /**
     * RefundReconciliationJob constructor.
     *
     * The gateway-bridge, ledger, and transaction-repository dependencies are
     * nullable so that the existing integration test (which constructs the
     * job with only the four original dependencies) continues to work: when
     * any of the three is null, Phase 1 is skipped entirely and the job
     * falls back to the original Phase 2 auto-fail behaviour.
     *
     * @param Database $db The database connection instance.
     * @param EventManager $events The system event hook dispatcher.
     * @param AuditLogger $audit Compliance audit trail logger.
     * @param Logger $logger Application logger.
     * @param GatewayBridge|null $bridge Gateway bridge for refund-status queries.
     * @param LedgerService|null $ledger Ledger service for posting refund entries.
     * @param TransactionRepository|null $transactions Transaction lookup for gateway slug/id resolution.
     */
    public function __construct(
        Database $db,
        EventManager $events,
        AuditLogger $audit,
        Logger $logger,
        ?GatewayBridge $bridge = null,
        ?LedgerService $ledger = null,
        ?TransactionRepository $transactions = null
    ) {
        $this->db = $db;
        $this->events = $events;
        $this->audit = $audit;
        $this->logger = $logger;
        $this->bridge = $bridge;
        $this->ledger = $ledger;
        $this->transactions = $transactions;
    }

    /**
     * Reconciles pending refunds in two phases (gateway probe then stale-pending mark-for-verification).
     *
     * @return array{requires_verification: int, completed: int, total: int} Reconciliation execution results.
     */
    public function run(): array
    {
        $completedCount = $this->runGatewayProbePhase();
        $requiresVerificationCount = $this->runStalePendingVerificationPhase();

        // total reflects the sum of refunds the job actually transitioned;
        // refunds left pending (gateway 'pending' or unknown) are not counted.
        // Audit fix CRON-7: the 'failed' key was renamed to 'requires_verification'
        // because Phase 2 no longer auto-fails - it marks the refund for
        // explicit admin review instead.
        return [
            'requires_verification' => $requiresVerificationCount,
            'completed'             => $completedCount,
            'total'                 => $requiresVerificationCount + $completedCount,
        ];
    }

    /**
     * Phase 1: probe the gateway for the authoritative status of refunds that
     * have been pending long enough for the gateway to have settled (>= 30 min)
     * but not yet long enough to hit the 24-hour auto-fail backstop.
     *
     * @return int Number of refunds transitioned to 'completed' or 'failed' by this phase.
     */
    private function runGatewayProbePhase(): int
    {
        $bridge = $this->bridge;
        $transactions = $this->transactions;
        if ($bridge === null || $transactions === null) {
            // Test / minimal-DI environment: skip gateway probing entirely.
            return 0;
        }

        $probeWindow = self::GATEWAY_PROBE_AFTER_MINUTES;
        $staleHours = self::STALE_AFTER_HOURS;

        $candidates = $this->db->fetchAll(
            "SELECT id, merchant_id, transaction_id, amount, created_at
             FROM op_refunds
             WHERE status = 'pending'
               AND created_at < DATE_SUB(NOW(), INTERVAL {$probeWindow} MINUTE)
               AND created_at > DATE_SUB(NOW(), INTERVAL {$staleHours} HOUR)
             ORDER BY created_at ASC
             LIMIT " . self::BATCH_LIMIT
        );

        $transitioned = 0;

        foreach ($candidates as $refund) {
            if (!isset($refund['id'], $refund['merchant_id'], $refund['transaction_id'])
                || !is_scalar($refund['id'])
                || !is_scalar($refund['merchant_id'])
                || !is_scalar($refund['transaction_id'])) {
                continue;
            }
            $refundId = (int) $refund['id'];
            $merchantId = (int) $refund['merchant_id'];
            $txnId = (int) $refund['transaction_id'];
            $amountVal = $refund['amount'] ?? '0.00';
            $amount = is_scalar($amountVal) ? (string) $amountVal : '0.00';

            try {
                $transitioned += $this->probeGatewayForRefund($refundId, $merchantId, $txnId, $amount, $bridge, $transactions);
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Refund gateway probe failed for refund #{$refundId}: " . $e->getMessage()
                );
            }
        }

        return $transitioned;
    }

    /**
     * Queries the gateway for a single refund's status and applies the appropriate transition.
     *
     * The $bridge and $transactions parameters are passed in explicitly (rather
     * than read from $this) so PHPStan can follow the non-null type narrowing
     * from {@see runGatewayProbePhase()}'s guard.
     *
     * @param int $refundId The op_refunds.id being reconciled.
     * @param int $merchantId The owning merchant ID.
     * @param int $txnId The associated op_transactions.id.
     * @param string $amount The refund amount (decimal string).
     * @param GatewayBridge $bridge Gateway bridge for refund-status queries.
     * @param TransactionRepository $transactions Transaction lookup for gateway slug/id resolution.
     * @return int 1 when the refund was transitioned (completed or failed), 0 when left pending.
     */
    private function probeGatewayForRefund(
        int $refundId,
        int $merchantId,
        int $txnId,
        string $amount,
        GatewayBridge $bridge,
        TransactionRepository $transactions
    ): int {
        $txn = $transactions->forTenant($merchantId)->findScoped($txnId);
        if ($txn === null) {
            $this->logger->warning(
                "Refund #{$refundId}: linked transaction #{$txnId} not found for merchant #{$merchantId}; skipping gateway probe."
            );
            return 0;
        }

        $gwSlugVal = $txn['gateway_slug'] ?? '';
        $gwSlug = is_scalar($gwSlugVal) ? (string) $gwSlugVal : '';
        $gwTrxIdVal = $txn['gateway_trx_id'] ?? ($txn['trx_id'] ?? '');
        $gwTrxId = is_scalar($gwTrxIdVal) ? (string) $gwTrxIdVal : '';

        if ($gwSlug === '' || $gwTrxId === '') {
            $this->logger->warning(
                "Refund #{$refundId}: linked transaction #{$txnId} has no gateway_slug/gateway_trx_id; skipping gateway probe."
            );
            return 0;
        }

        if (!$bridge->supportsRefundStatus($gwSlug)) {
            $this->logger->warning(
                "Refund #{$refundId}: gateway '{$gwSlug}' does not support refund-status queries; leaving pending for the 24-hour backstop."
            );
            return 0;
        }

        // Use gateway_trx_id as the lookup key. The op_refunds table does not
        // currently persist a gateway-side refund_id, so adapters that need
        // the original refund_id should return null when handed a transaction
        // ID - the job will then leave the refund pending (safe default).
        $gatewayStatus = $bridge->getRefundStatus($gwSlug, $merchantId, $gwTrxId);

        if ($gatewayStatus === null) {
            $this->logger->warning(
                "Refund #{$refundId}: gateway '{$gwSlug}' returned null status for txn #{$txnId}; cannot determine refund state, leaving pending."
            );
            return 0;
        }

        if ($gatewayStatus === 'pending') {
            // Gateway is still processing - leave the local record pending too.
            return 0;
        }

        if ($gatewayStatus === 'succeeded') {
            return $this->markRefundCompleted($refundId, $merchantId, $txnId, $amount);
        }

        if ($gatewayStatus === 'failed' || $gatewayStatus === 'not_found') {
            return $this->markRefundFailedFromProbe(
                $refundId,
                $merchantId,
                $gatewayStatus
            );
        }

        // Unrecognised status string - log and leave pending.
        $this->logger->warning(
            "Refund #{$refundId}: gateway '{$gwSlug}' returned unrecognised status '{$gatewayStatus}'; leaving pending."
        );
        return 0;
    }

    /**
     * Marks a refund as 'completed' inside a locked transaction and posts the
     * ledger entry, mirroring RefundService::create()'s success path.
     *
     * @param int $refundId The op_refunds.id being completed.
     * @param int $merchantId The owning merchant ID.
     * @param int $txnId The associated op_transactions.id.
     * @param string $amount The refund amount (decimal string).
     * @return int 1 when the transition was applied, 0 when the row was no longer pending.
     */
    private function markRefundCompleted(int $refundId, int $merchantId, int $txnId, string $amount): int
    {
        $transitioned = $this->db->transaction(function () use ($refundId): int {
            $locked = $this->db->fetchOne(
                "SELECT status FROM op_refunds WHERE id = :id LIMIT 1 FOR UPDATE",
                ['id' => $refundId]
            );
            if ($locked === null || ($locked['status'] ?? '') !== 'pending') {
                return 0;
            }
            $this->db->execute(
                "UPDATE op_refunds SET status = 'completed', processed_at = :ts WHERE id = :id",
                ['ts' => DateHelper::nowMicro(), 'id' => $refundId]
            );
            return 1;
        });

        if ($transitioned === 0) {
            return 0;
        }

        if ($this->ledger !== null) {
            try {
                $this->ledger->recordRefund($merchantId, $refundId, $txnId, $amount, 'BDT');
            } catch (\Throwable $e) {
                // The refund is already marked completed in the DB; a ledger
                // posting failure here is a serious (but recoverable) data-
                // integrity issue. Log it loudly for manual reconciliation
                // rather than reverting the refund status (which would
                // confuse the customer who has now received their money).
                $this->logger->error(
                    "Refund #{$refundId} was marked completed but ledger posting failed: " . $e->getMessage()
                );
            }
        } else {
            $this->logger->warning(
                "Refund #{$refundId} was marked completed but no LedgerService is wired; ledger entry was not posted."
            );
        }

        $this->events->doAction('payment.refund.reconciliation_completed', [
            'refund_id'     => $refundId,
            'merchant_id'   => $merchantId,
            'transaction_id' => $txnId,
            'amount'        => $amount,
        ]);
        $this->logger->info(
            "Refund #{$refundId} (merchant {$merchantId}) was reconciled to 'completed' via gateway status query."
        );
        return 1;
    }

    /**
     * Marks a refund as 'failed' inside a locked transaction after the gateway
     * explicitly reported 'failed' or 'not_found'. Releases the balance hold.
     *
     * @param int $refundId The op_refunds.id being failed.
     * @param int $merchantId The owning merchant ID.
     * @param string $gatewayStatus The gateway's reported status ('failed' or 'not_found').
     * @return int 1 when the transition was applied, 0 when the row was no longer pending.
     */
    private function markRefundFailedFromProbe(int $refundId, int $merchantId, string $gatewayStatus): int
    {
        $transitioned = $this->db->transaction(function () use ($refundId): int {
            $locked = $this->db->fetchOne(
                "SELECT status FROM op_refunds WHERE id = :id LIMIT 1 FOR UPDATE",
                ['id' => $refundId]
            );
            if ($locked === null || ($locked['status'] ?? '') !== 'pending') {
                return 0;
            }
            $this->db->execute(
                "UPDATE op_refunds SET status = 'failed' WHERE id = :id",
                ['id' => $refundId]
            );
            return 1;
        });

        if ($transitioned === 0) {
            return 0;
        }

        $this->audit->log(
            $merchantId,
            null,
            'refund.reconciliation_failed',
            'refund',
            $refundId,
            ['status' => 'pending'],
            ['status' => 'failed', 'reason' => 'gateway_reported_' . $gatewayStatus]
        );
        $this->events->doAction('payment.refund.reconciliation_failed', ['id' => $refundId]);
        $this->logger->warning(
            "Refund #{$refundId} (merchant {$merchantId}) was auto-failed after gateway reported status '{$gatewayStatus}'."
        );
        return 1;
    }

    /**
     * Phase 2: marks refunds that have been pending longer than the 24-hour
     * stale window as 'pending_verification' (audit fix CRON-7, issue #377). The
     * previous behaviour auto-failed these refunds, which released the withheld
     * balance - risking a double refund if the gateway had actually processed
     * the refund but the local process died before reconcile.
     *
     * 'pending_verification' preserves the balance hold and surfaces the
     * refund for explicit admin review against the gateway's dashboard. The
     * admin can then either mark the refund 'completed' (if the gateway
     * confirms it) or 'failed' (if the gateway confirms it never happened).
     *
     * @return int Number of refunds marked 'pending_verification' by this phase.
     */
    private function runStalePendingVerificationPhase(): int
    {
        $stale = $this->db->fetchAll(
            "SELECT id, merchant_id, transaction_id, amount, created_at
             FROM op_refunds
             WHERE status = 'pending'
               AND created_at < DATE_SUB(NOW(), INTERVAL " . self::STALE_AFTER_HOURS . " HOUR)
             ORDER BY created_at ASC
             LIMIT " . self::BATCH_LIMIT
        );

        $markedCount = 0;

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
                    // Audit fix CRON-7: mark as 'pending_verification' rather
                    // than 'failed' so the withheld balance is preserved and
                    // the refund is surfaced for explicit admin review.
                    $this->db->execute(
                        "UPDATE op_refunds SET status = 'pending_verification' WHERE id = :id",
                        ['id' => $refundId]
                    );
                    return true;
                });

                if (!$transitioned) {
                    continue;
                }

                $markedCount++;
                $this->audit->log(
                    $merchantId,
                    null,
                    'refund.requires_verification',
                    'refund',
                    $refundId,
                    ['status' => 'pending'],
                    ['status' => 'pending_verification', 'reason' => 'stale_pending_requires_verification']
                );
                $this->events->doAction('payment.refund.requires_verification', $refund);
                $this->logger->warning(
                    "Refund #{$refundId} (merchant {$merchantId}) was pending for over " . self::STALE_AFTER_HOURS
                    . "h and has been marked 'pending_verification'. Admin must verify against the gateway dashboard before completing or failing the refund."
                );
            } catch (\Throwable $e) {
                $this->logger->error("Refund reconciliation failed for refund #{$refundId}: " . $e->getMessage());
            }
        }

        return $markedCount;
    }
}
