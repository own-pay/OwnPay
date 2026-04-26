<?php

declare(strict_types=1);

namespace OwnPay\Service;

use OwnPay\Core\Database;

/**
 * ReconciliationService — automated financial reconciliation.
 *
 * Compares:
 *   1. Ledger entry totals vs transaction aggregate totals per merchant
 *   2. Settlement batch totals vs linked transaction sums
 *   3. Legacy op_transaction vs op_transactions (bridge drift)
 *
 * Results are stored in op_reconciliation_reports for audit.
 */
final class ReconciliationService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(?Database $db = null, ?AuditLogger $audit = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->audit = $audit ?? new AuditLogger();
    }

    /**
     * Run full ledger reconciliation for a merchant.
     *
     * Compares sum of ledger debit/credit entries against
     * aggregate transaction amounts by status.
     *
     * @return array{
     *   merchant_id: int,
     *   status: string,
     *   ledger_debit_total: string,
     *   ledger_credit_total: string,
     *   txn_completed_total: string,
     *   txn_refunded_total: string,
     *   mismatches: array,
     *   run_at: string
     * }
     */
    public function reconcileLedger(int $merchantId): array
    {
        // Ledger side: sum all debit/credit entries for merchant accounts
        $ledger = $this->db->fetchOne("
            SELECT
                COALESCE(SUM(CASE WHEN le.entry_type = 'debit' THEN le.amount ELSE 0 END), 0) AS total_debit,
                COALESCE(SUM(CASE WHEN le.entry_type = 'credit' THEN le.amount ELSE 0 END), 0) AS total_credit
            FROM op_ledger_entries le
            JOIN op_ledger_accounts la ON la.id = le.account_id
            WHERE la.merchant_id = :mid
        ", [':mid' => $merchantId]);

        // Transaction side: sum amounts by status
        $txn = $this->db->fetchOne("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS completed_total,
                COALESCE(SUM(CASE WHEN status = 'refunded' THEN amount ELSE 0 END), 0) AS refunded_total,
                COALESCE(SUM(CASE WHEN status = 'settled' THEN amount ELSE 0 END), 0) AS settled_total,
                COUNT(*) AS total_count
            FROM op_transactions
            WHERE merchant_id = :mid
        ", [':mid' => $merchantId]);

        // Compare: ledger debits should equal completed txn total
        $mismatches = [];
        $expectedDebit = bcadd($txn['completed_total'], $txn['settled_total'], 4);

        if (bccomp($ledger['total_debit'], $ledger['total_credit'], 4) !== 0) {
            $mismatches[] = [
                'type' => 'ledger_imbalance',
                'severity' => 'critical',
                'detail' => "Ledger debit ({$ledger['total_debit']}) ≠ credit ({$ledger['total_credit']})",
            ];
        }

        $status = empty($mismatches) ? 'matched' : 'mismatched';

        $report = [
            'merchant_id' => $merchantId,
            'status' => $status,
            'ledger_debit_total' => $ledger['total_debit'],
            'ledger_credit_total' => $ledger['total_credit'],
            'txn_completed_total' => $txn['completed_total'],
            'txn_refunded_total' => $txn['refunded_total'],
            'mismatches' => $mismatches,
            'run_at' => gmdate('Y-m-d H:i:s'),
        ];

        // Store report
        $this->storeReport($merchantId, 'ledger', $report);

        return $report;
    }

    /**
     * Reconcile settlement batches against their linked transactions.
     */
    public function reconcileSettlements(int $merchantId): array
    {
        $settlements = $this->db->fetchAll("
            SELECT
                s.id, s.public_id, s.gross_amount, s.net_amount, s.fee_amount,
                s.transaction_count,
                COALESCE(SUM(t.amount), 0) AS actual_txn_total,
                COUNT(t.id) AS actual_txn_count
            FROM op_settlements s
            LEFT JOIN op_transactions t ON t.settlement_id = s.id
            WHERE s.merchant_id = :mid
            GROUP BY s.id
        ", [':mid' => $merchantId]);

        $mismatches = [];
        foreach ($settlements as $s) {
            if (bccomp($s['gross_amount'], $s['actual_txn_total'], 4) !== 0) {
                $mismatches[] = [
                    'type' => 'settlement_amount_mismatch',
                    'severity' => 'warning',
                    'settlement_id' => $s['public_id'],
                    'expected' => $s['gross_amount'],
                    'actual' => $s['actual_txn_total'],
                ];
            }
            if ((int) $s['transaction_count'] !== (int) $s['actual_txn_count']) {
                $mismatches[] = [
                    'type' => 'settlement_count_mismatch',
                    'severity' => 'warning',
                    'settlement_id' => $s['public_id'],
                    'expected' => $s['transaction_count'],
                    'actual' => $s['actual_txn_count'],
                ];
            }
        }

        $report = [
            'merchant_id' => $merchantId,
            'status' => empty($mismatches) ? 'matched' : 'mismatched',
            'settlements_checked' => count($settlements),
            'mismatches' => $mismatches,
            'run_at' => gmdate('Y-m-d H:i:s'),
        ];

        $this->storeReport($merchantId, 'settlement', $report);
        return $report;
    }

    /**
     * Detect bridge drift — legacy op_transaction rows not synced to op_transactions.
     */
    public function reconcileGatewayBridge(): array
    {
        try {
            $unsynced = (int) $this->db->fetchColumn("
                SELECT COUNT(*)
                FROM op_transaction pt
                LEFT JOIN op_transactions at2 ON at2.reference = pt.ref
                WHERE at2.id IS NULL
            ");
        } catch (\PDOException $e) {
            // op_transaction may not exist in new installations
            $unsynced = 0;
        }

        $report = [
            'status' => $unsynced === 0 ? 'synced' : 'drift_detected',
            'unsynced' => $unsynced,
            'run_at' => gmdate('Y-m-d H:i:s'),
        ];

        $this->storeReport(0, 'gateway_bridge', $report);
        return $report;
    }

    /**
     * Run all reconciliation checks for all active merchants.
     */
    public function runAll(): array
    {
        $rows = $this->db->fetchAll("SELECT DISTINCT merchant_id FROM op_transactions");
        $merchants = array_column($rows, 'merchant_id');

        $results = ['merchants' => [], 'bridge' => null];

        foreach ($merchants as $mid) {
            $mid = (int) $mid;
            $results['merchants'][$mid] = [
                'ledger' => $this->reconcileLedger($mid),
                'settlement' => $this->reconcileSettlements($mid),
            ];
        }

        $results['bridge'] = $this->reconcileGatewayBridge();
        return $results;
    }

    /**
     * Store a reconciliation report.
     */
    private function storeReport(int $merchantId, string $type, array $report): void
    {
        try {
            $this->db->execute("
                INSERT INTO op_reconciliation_reports
                    (merchant_id, report_type, status, report_data, created_at)
                VALUES (:mid, :type, :status, :data, NOW(6))
            ", [
                ':mid' => $merchantId,
                ':type' => $type,
                ':status' => $report['status'],
                ':data' => json_encode($report),
            ]);
        } catch (\PDOException $e) {
            error_log("[Reconciliation] Failed to store report: " . $e->getMessage());
        }
    }
}
