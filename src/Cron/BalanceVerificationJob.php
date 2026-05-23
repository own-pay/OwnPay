<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\Payment\ReconciliationService;
use OwnPay\Service\Notification\AlertService;

/**
 * Class BalanceVerificationJob
 *
 * Enterprise cron job executing double-entry ledger bookkeeping verification across active brands (merchants).
 * Performs transactional reconciliation checking integrity between transacted aggregates and ledger entries,
 * triggering warning alerts on any ledger balance mismatch detected.
 *
 * @package OwnPay\Cron
 */
final class BalanceVerificationJob
{
    /**
     * @var ReconciliationService Service responsible for double-entry ledger bookkeeping reconciliation.
     */
    private ReconciliationService $reconciliation;

    /**
     * @var AlertService Service for triggering administrative security/operational alerts.
     */
    private AlertService $alerts;

    /**
     * @var \OwnPay\Core\Database The database connection instance.
     */
    private \OwnPay\Core\Database $db;

    /**
     * BalanceVerificationJob constructor.
     *
     * @param ReconciliationService $reconciliation Service responsible for double-entry ledger bookkeeping reconciliation.
     * @param AlertService          $alerts         Service for triggering administrative security/operational alerts.
     * @param \OwnPay\Core\Database $db             The database connection instance.
     */
    public function __construct(
        ReconciliationService $reconciliation,
        AlertService $alerts,
        \OwnPay\Core\Database $db
    ) {
        $this->reconciliation = $reconciliation;
        $this->alerts = $alerts;
        $this->db = $db;
    }

    /**
     * Runs the ledger balance verification audit across all active brands.
     *
     * Queries all active merchants and their transacted currencies, performs double-entry bookkeeping checks
     * using the ReconciliationService, and records mismatched ledger balances while alerting the store operators.
     *
     * @return array{total_checks: int, mismatches: int} Returns the audit summary metrics.
     */
    public function run(): array
    {
        $merchants = $this->db->fetchAll(
            "SELECT id, name FROM op_merchants WHERE status = 'active'"
        );

        $results = [];
        $mismatches = 0;

        foreach ($merchants as $merchant) {
            if (!isset($merchant['id']) || !is_scalar($merchant['id'])) {
                continue;
            }
            $mid = (int) $merchant['id'];

            // Retrieve the set of distinct currencies that have active transacted records for this brand context.
            $currencies = $this->db->fetchAll(
                "SELECT DISTINCT currency FROM op_transactions WHERE merchant_id = :mid",
                ['mid' => $mid]
            );

            foreach ($currencies as $cur) {
                if (!isset($cur['currency']) || !is_string($cur['currency'])) {
                    continue;
                }
                $currency = $cur['currency'];
                $result = $this->reconciliation->reconcile($mid, $currency);

                if (!$result['balanced']) {
                    $mismatches++;
                    $this->alerts->create(
                        $mid,
                        'balance_mismatch',
                        'Balance Mismatch Detected',
                        "Currency: {$currency}, Difference: {$result['difference']}",
                        'warning'
                    );
                }

                $results[] = [
                    'merchant_id' => $mid,
                    'currency'    => $currency,
                    'balanced'    => $result['balanced'],
                    'difference'  => $result['difference'],
                ];
            }
        }

        return ['total_checks' => count($results), 'mismatches' => $mismatches];
    }
}
