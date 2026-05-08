<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

/**
 * Reconciliation service â€” verify ledger vs transactions integrity.
 */
final class ReconciliationService
{
    private \OwnPay\Core\Database $db;
    private LedgerService $ledger;

    public function __construct(\OwnPay\Core\Database $db, LedgerService $ledger)
    {
        $this->db = $db;
        $this->ledger = $ledger;
    }

    /**
     * Run reconciliation for merchant.
     * Compares sum of completed transactions vs ledger balance.
     *
     * @return array{balanced: bool, transaction_total: string, ledger_balance: string, difference: string}
     */
    public function reconcile(int $merchantId, string $currency): array
    {
        // Sum completed transaction net amounts
        $txnRow = $this->db->fetchOne(
            "SELECT COALESCE(SUM(net_amount), 0) as total
             FROM op_transactions
             WHERE merchant_id = :mid AND currency = :cur AND status = 'completed'",
            ['mid' => $merchantId, 'cur' => $currency]
        );
        $txnTotal = $txnRow['total'] ?? '0.00';

        // Sum refunds
        $refundRow = $this->db->fetchOne(
            "SELECT COALESCE(SUM(r.amount), 0) as total
             FROM op_refunds r
             JOIN op_transactions t ON t.id = r.transaction_id
             WHERE r.merchant_id = :mid AND t.currency = :cur AND r.status = 'completed'",
            ['mid' => $merchantId, 'cur' => $currency]
        );
        $refundTotal = $refundRow['total'] ?? '0.00';

        // Expected balance = transactions - refunds - settlements
        $settlementRow = $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total
             FROM op_settlements
             WHERE merchant_id = :mid AND currency = :cur AND status = 'completed'",
            ['mid' => $merchantId, 'cur' => $currency]
        );
        $settlementTotal = $settlementRow['total'] ?? '0.00';

        $expectedBalance = bcsub(bcsub($txnTotal, $refundTotal, 2), $settlementTotal, 2);

        // Ledger balance
        $ledgerBalance = $this->ledger->calculateBalance($merchantId, $currency);

        $difference = bcsub($expectedBalance, $ledgerBalance, 2);

        return [
            'balanced'          => bccomp($difference, '0.00', 2) === 0,
            'transaction_total' => $txnTotal,
            'refund_total'      => $refundTotal,
            'settlement_total'  => $settlementTotal,
            'expected_balance'  => $expectedBalance,
            'ledger_balance'    => $ledgerBalance,
            'difference'        => $difference,
        ];
    }
}
