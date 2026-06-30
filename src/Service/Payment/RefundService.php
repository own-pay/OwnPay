<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Gateway\GatewayBridge;
use OwnPay\Repository\RefundRepository;
use OwnPay\Repository\TransactionRepository;
use InvalidArgumentException;
use RuntimeException;
use OwnPay\Support\DateHelper;
use OwnPay\Service\Payment\LedgerService;

/**
 * Service managing partial and full transaction refund lifecycles.
 *
 * Checks refund limits and thresholds, interacts with downstream payment gateway integrations
 * via the GatewayBridge, updates transaction statuses, and registers refund entries in the double-entry ledger.
 */
final class RefundService
{
    /**
     * @var RefundRepository Repository logging refund requests.
     */
    private RefundRepository $refunds;

    /**
     * @var TransactionRepository Repository accessing core transactions.
     */
    private TransactionRepository $transactions;

    /**
     * @var GatewayBridge Gateway adapter connector bridge.
     */
    private GatewayBridge $bridge;

    /**
     * @var LedgerService Ledger bookkeeping record publisher.
     */
    private LedgerService $ledger;

    /**
     * RefundService constructor.
     *
     * @param RefundRepository $refunds Repository managing refunds.
     * @param TransactionRepository $transactions Repository managing transaction records.
     * @param GatewayBridge $bridge Payment gateway adapter orchestration bridge.
     * @param LedgerService $ledger Service posting ledger balances.
     */
    public function __construct(
        RefundRepository $refunds,
        TransactionRepository $transactions,
        GatewayBridge $bridge,
        LedgerService $ledger
    ) {
        $this->refunds = $refunds;
        $this->transactions = $transactions;
        $this->bridge = $bridge;
        $this->ledger = $ledger;
    }

    /**
     * Creates and executes a refund request for a completed transaction.
     *
     * Validates that the transaction belongs to the merchant, verify that it is eligible
     * for refund (completed), calculates the remaining non-refunded amount, runs the gateway refund API,
     * and logs double-entry ledger records upon success.
     *
     * @param int $merchantId The ID of the merchant/brand.
     * @param array{transaction_id: int|string, amount?: float|int|string|null, reason?: string} $data Input refund options.
     * @return array<string, mixed> The generated refund record fields.
     * @throws InvalidArgumentException If the transaction does not exist, is not completed, or the amount exceeds the limit.
     * @throws RuntimeException If the downstream payment gateway adapter execution throws an error.
     */
    public function create(int $merchantId, array $data): array
    {
        $transactionId = (int) $data['transaction_id'];
        $amount = $data['amount'] ?? null;

        $db = \OwnPay\Core\Database::getInstance();

        $preparation = $db->transaction(function () use ($db, $merchantId, $transactionId, $amount, $data) {
            $txn = $db->fetchOne(
                "SELECT * FROM op_transactions WHERE id = :id AND merchant_id = :mid FOR UPDATE",
                ['id' => $transactionId, 'mid' => $merchantId]
            );

            if (!$txn) {
                throw new InvalidArgumentException('Transaction not found');
            }

            if ($txn['status'] !== 'completed' && $txn['status'] !== 'refunded') {
                throw new InvalidArgumentException('Only completed transactions can be refunded');
            }

            $txnIdVal = $txn['id'] ?? 0;
            $txnId = is_scalar($txnIdVal) ? (int)$txnIdVal : 0;

            $alreadyRefundedVal = $db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0) FROM op_refunds 
                 WHERE transaction_id = :txid AND merchant_id = :mid AND status IN ('pending', 'completed') FOR UPDATE",
                ['txid' => $txnId, 'mid' => $merchantId]
            );
            $alreadyRefunded = is_scalar($alreadyRefundedVal) ? (string)$alreadyRefundedVal : '0.00';
            /** @var numeric-string $alreadyRefunded */
            
            $origAmountVal = $txn['amount'] ?? '0.00';
            $origAmount = is_scalar($origAmountVal) ? (string) $origAmountVal : '0.00';
            /** @var numeric-string $origAmount */

            if ($amount === null) {
                $amount = bcsub($origAmount, $alreadyRefunded, 2);
            } elseif (!is_numeric($amount) || bccomp((string)$amount, '0', 2) <= 0) {
                throw new InvalidArgumentException('Refund amount must be a positive number');
            }

            $amountStr = (string)$amount;
            /** @var numeric-string $amountStr */
            if (bccomp($amountStr, '0.00', 2) <= 0) {
                throw new InvalidArgumentException('No remaining amount left to refund');
            }

            $newTotal = bcadd($alreadyRefunded, $amountStr, 2);
            if (bccomp($newTotal, $origAmount, 2) > 0) {
                throw new InvalidArgumentException('Refund amount cannot exceed transaction amount');
            }

            $currencyVal = $txn['currency'] ?? 'BDT';
            $currency = is_scalar($currencyVal) ? (string)$currencyVal : 'BDT';

            $account = $db->fetchOne(
                "SELECT balance FROM op_ledger_accounts 
                 WHERE merchant_id = :mid AND currency = :cur AND name = 'MERCHANT_PAYABLE' 
                 LIMIT 1 FOR UPDATE",
                ['mid' => $merchantId, 'cur' => $currency]
            );
            $balanceVal = $account['balance'] ?? '0.00';
            $merchantBalance = is_scalar($balanceVal) ? (string)$balanceVal : '0.00';
            /** @var numeric-string $merchantBalance */

            $pendingRefundsVal = $db->fetchColumn(
                "SELECT COALESCE(SUM(r.amount), 0) FROM op_refunds r
                 JOIN op_transactions t ON t.id = r.transaction_id
                 WHERE r.merchant_id = :mid AND r.status = 'pending' AND t.currency = :cur",
                ['mid' => $merchantId, 'cur' => $currency]
            );
            $pendingRefunds = is_scalar($pendingRefundsVal) ? (string)$pendingRefundsVal : '0.00';
            /** @var numeric-string $pendingRefunds */

            $availableBalance = bcsub($merchantBalance, $pendingRefunds, 4);

            $feeVal = $txn['fee'] ?? '0.00';
            $origFee = is_scalar($feeVal) ? (string)$feeVal : '0.00';
            /** @var numeric-string $origFee */
            if (bccomp($origAmount, '0.00', 4) > 0) {
                $ratio = bcdiv($origFee, $origAmount, 18);
                $refundFee = bcmul($amountStr, $ratio, 4);
            } else {
                $refundFee = '0.00';
            }
            /** @var numeric-string $refundFee */
            $refundNet = bcsub($amountStr, $refundFee, 4);

            if (bccomp($availableBalance, $refundNet, 4) < 0) {
                throw new InvalidArgumentException("Insufficient merchant payable ledger balance ({$availableBalance} {$currency}) to issue refund net of {$refundNet} {$currency}");
            }

            $id = $this->refunds->forTenant($merchantId)->createRefund([
                'transaction_id' => $txnId,
                'amount' => (string)$amount,
                'reason' => $data['reason'] ?? '',
                'status' => 'pending'
            ]);

            $refund = $this->refunds->forTenant($merchantId)->findScoped((int)$id);
            if (!$refund) {
                throw new RuntimeException("Refund record not found after creation");
            }

            $gwSlugVal = $txn['gateway_slug'] ?? '';
            $gwSlug = is_scalar($gwSlugVal) ? (string)$gwSlugVal : '';
            $gwTrxIdVal = $txn['gateway_trx_id'] ?? $txn['trx_id'] ?? '';
            $gwTrxId = is_scalar($gwTrxIdVal) ? (string)$gwTrxIdVal : '';

            return [
                'refund' => $refund,
                'refund_id' => (int)$id,
                'txn_id' => $txnId,
                'amount' => (string)$amount,
                'currency' => $currency,
                'gateway_slug' => $gwSlug,
                'gateway_trx_id' => $gwTrxId,
                'orig_amount' => $origAmount,
            ];
        });

        $refundId = $preparation['refund_id'];
        $txnId = $preparation['txn_id'];
        $amountStr = $preparation['amount'];
        $currency = $preparation['currency'];
        $gwSlug = $preparation['gateway_slug'];
        $gwTrxId = $preparation['gateway_trx_id'];
        $origAmount = $preparation['orig_amount'];

        $gatewaySuccess = false;
        $gatewayError = null;

        try {
            $result = $this->bridge->refund(
                $gwSlug,
                $merchantId,
                $gwTrxId,
                $amountStr
            );
            $gatewaySuccess = (bool) ($result['success'] ?? false);
        } catch (\Throwable $e) {
            $gatewayError = $e;
        }

        $refund = $db->transaction(function () use ($db, $merchantId, $refundId, $txnId, $amountStr, $currency, $origAmount, $gatewaySuccess, $gatewayError) {
            $refRecord = $db->fetchOne(
                "SELECT * FROM op_refunds WHERE id = :id AND merchant_id = :mid FOR UPDATE",
                ['id' => $refundId, 'mid' => $merchantId]
            );
            if (!$refRecord) {
                throw new RuntimeException("Refund record not found during finalization");
            }

            if ($gatewaySuccess) {
                $this->refunds->forTenant($merchantId)->updateScoped($refundId, [
                    'status' => 'completed',
                    'processed_at' => DateHelper::nowMicro()
                ]);

                $this->ledger->recordRefund($merchantId, $refundId, $txnId, $amountStr, $currency);

                $txn = $db->fetchOne(
                    "SELECT * FROM op_transactions WHERE id = :id AND merchant_id = :mid FOR UPDATE",
                    ['id' => $txnId, 'mid' => $merchantId]
                );
                if ($txn) {
                    $totalRefundedVal = $db->fetchColumn(
                        "SELECT COALESCE(SUM(amount), 0) FROM op_refunds 
                         WHERE transaction_id = :txid AND merchant_id = :mid AND status = 'completed'",
                        ['txid' => $txnId, 'mid' => $merchantId]
                    );
                    $totalRefunded = is_scalar($totalRefundedVal) ? (string)$totalRefundedVal : '0.00';
                    /** @var numeric-string $totalRefunded */
                    /** @var numeric-string $origAmount */

                    if (bccomp($totalRefunded, $origAmount, 2) === 0) {
                        $this->transactions->forTenant($merchantId)->updateScoped($txnId, [
                            'status' => 'refunded'
                        ]);
                    }
                }
            } else {
                $this->refunds->forTenant($merchantId)->updateScoped($refundId, [
                    'status' => 'failed'
                ]);
            }

            $finalRefund = $this->refunds->forTenant($merchantId)->findScoped($refundId);
            if (!$finalRefund) {
                throw new RuntimeException("Refund record not found after finalization");
            }

            if ($gatewayError !== null) {
                throw new RuntimeException('Gateway refund failed: ' . $gatewayError->getMessage());
            }

            return $finalRefund;
        });

        return $refund;
    }

    /**
     * Retrieves a single refund record by ID, scoped to the brand/merchant.
     *
     * @param int $merchantId The unique ID of the merchant.
     * @param int $id The unique ID of the refund to retrieve.
     * @return array<string, mixed>|null The refund fields, or null if not found.
     */
    public function find(int $merchantId, int $id): ?array
    {
        return $this->refunds->forTenant($merchantId)->findScoped($id);
    }
}
