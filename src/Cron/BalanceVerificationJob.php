<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\Payment\ReconciliationService;
use OwnPay\Service\Notification\AlertService;

/**
 * Balance verification job — runs reconciliation and alerts on mismatch.
 */
final class BalanceVerificationJob
{
    private ReconciliationService $reconciliation;
    private AlertService $alerts;
    private \OwnPay\Core\Database $db;

    public function __construct(
        ReconciliationService $reconciliation,
        AlertService $alerts,
        \OwnPay\Core\Database $db
    ) {
        $this->reconciliation = $reconciliation;
        $this->alerts = $alerts;
        $this->db = $db;
    }

    public function run(): array
    {
        $merchants = $this->db->fetchAll(
            "SELECT id, name FROM op_merchants WHERE status = 'active'"
        );

        $results = [];
        $mismatches = 0;

        foreach ($merchants as $merchant) {
            $mid = (int) $merchant['id'];

            // Get currencies with transactions
            $currencies = $this->db->fetchAll(
                "SELECT DISTINCT currency FROM op_transactions WHERE merchant_id = :mid",
                ['mid' => $mid]
            );

            foreach ($currencies as $cur) {
                $result = $this->reconciliation->reconcile($mid, $cur['currency']);

                if (!$result['balanced']) {
                    $mismatches++;
                    $this->alerts->create(
                        $mid,
                        'balance_mismatch',
                        'Balance Mismatch Detected',
                        "Currency: {$cur['currency']}, Difference: {$result['difference']}",
                        'warning'
                    );
                }

                $results[] = [
                    'merchant_id' => $mid,
                    'currency'    => $cur['currency'],
                    'balanced'    => $result['balanced'],
                    'difference'  => $result['difference'],
                ];
            }
        }

        return ['total_checks' => count($results), 'mismatches' => $mismatches];
    }
}
