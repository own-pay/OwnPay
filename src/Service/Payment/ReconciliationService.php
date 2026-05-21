<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Core\Database;

/**
 * Manages ledger and transaction data reconciliation.
 *
 * Audits ledger consistency by validating direct ledger balances against computed
 * transaction net totals, subtracting proportional refund fee margins, and subtracting settlements.
 */
final class ReconciliationService
{
    /**
     * @var Database The database service.
     */
    private Database $db;

    /**
     * @var LedgerService Service managing the double-entry bookkeeping ledger.
     */
    private LedgerService $ledger;

    /**
     * ReconciliationService constructor.
     *
     * @param Database $db Direct database service.
     * @param LedgerService $ledger Service handling double-entry ledger queries and balance computation.
     */
    public function __construct(Database $db, LedgerService $ledger)
    {
        $this->db = $db;
        $this->ledger = $ledger;
    }

    /**
     * Reconciles completed transactions and settlements against double-entry ledger accounts.
     *
     * Summarizes transaction net gains, calculates GAAP-compliant proportional refund amounts,
     * subtracts settlements, and validates the expected result against the actual ledger balance.
     *
     * @param int $merchantId The ID of the merchant/brand.
     * @param string $currency The transaction/ledger ISO currency code.
     * @return array{
     *     balanced: bool,
     *     transaction_total: string,
     *     refund_total: string,
     *     settlement_total: string,
     *     expected_balance: string,
     *     ledger_balance: string,
     *     difference: string
     * } Detailed reconciliation balance sheet.
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

        // Sum refunds (calculating proportional net refund amounts to match new GAAP model)
        $refundRows = $this->db->fetchAll(
            "SELECT r.amount as refund_amount, t.amount as tx_amount, COALESCE(t.fee, 0) as tx_fee
             FROM op_refunds r
             JOIN op_transactions t ON t.id = r.transaction_id
             WHERE r.merchant_id = :mid AND t.currency = :cur AND r.status = 'completed'",
            ['mid' => $merchantId, 'cur' => $currency]
        );

        $refundNetTotal = '0.00';
        foreach ($refundRows as $row) {
            $refAmt = (string)$row['refund_amount'];
            $txAmt = (string)$row['tx_amount'];
            $txFee = (string)$row['tx_fee'];

            if (bccomp($txAmt, '0.00', 4) > 0) {
                $ratio = bcdiv($txFee, $txAmt, 18);
                $refundFee = bcmul($refAmt, $ratio, 4);
            } else {
                $refundFee = '0.00';
            }
            $refundNet = bcsub($refAmt, $refundFee, 4);
            $refundNetTotal = bcadd($refundNetTotal, $refundNet, 4);
        }
        $refundTotal = bcadd('0.00', $refundNetTotal, 2);

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
