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
        $refund = null;

        $db->transaction(function () use ($db, $merchantId, $transactionId, $amount, $data, &$refund) {
            // Lock parent transaction row to prevent concurrent refund computations on same transaction
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

            // Lock existing refunds for update to calculate absolute total accurately under concurrent requests
            $alreadyRefundedVal = $db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0) FROM op_refunds 
                 WHERE transaction_id = :txid AND merchant_id = :mid AND status IN ('pending', 'completed') FOR UPDATE",
                ['txid' => $txnId, 'mid' => $merchantId]
            );
            $alreadyRefunded = is_scalar($alreadyRefundedVal) ? (string)$alreadyRefundedVal : '0.00';
            
            $origAmountVal = $txn['amount'] ?? '0.00';
            $origAmount = is_scalar($origAmountVal) ? (string) $origAmountVal : '0.00';

            /** @var numeric-string $alreadyRefunded */
            /** @var numeric-string $origAmount */
            if ($amount === null || !is_numeric($amount) || bccomp((string)$amount, '0', 2) <= 0) {
                $amount = bcsub($origAmount, $alreadyRefunded, 2);
            }

            $amountStr = (string)$amount;
            /** @var numeric-string $amountStr */
            if (bccomp($amountStr, '0.00', 2) <= 0) {
                throw new InvalidArgumentException('No remaining amount left to refund');
            }

            $newTotal = bcadd($alreadyRefunded, $amountStr, 2);
            /** @var numeric-string $newTotal */
            if (bccomp($newTotal, $origAmount, 2) > 0) {
                throw new InvalidArgumentException('Refund amount cannot exceed transaction amount');
            }

            // Validate merchant payable ledger balance prior to issuing refund
            $currencyVal = $txn['currency'] ?? 'BDT';
            $currency = is_scalar($currencyVal) ? (string)$currencyVal : 'BDT';
            $merchantBalance = $this->ledger->calculateBalance($merchantId, $currency);

            $feeVal = $txn['fee'] ?? '0.00';
            $origFee = is_scalar($feeVal) ? (string)$feeVal : '0.00';
            if (bccomp($origAmount, '0.00', 4) > 0) {
                $ratio = bcdiv($origFee, $origAmount, 18);
                $refundFee = bcmul($amountStr, $ratio, 4);
            } else {
                $refundFee = '0.00';
            }
            $refundNet = bcsub($amountStr, $refundFee, 4);

            /** @var numeric-string $merchantBalance */
            /** @var numeric-string $refundNet */
            if (bccomp($merchantBalance, $refundNet, 4) < 0) {
                throw new InvalidArgumentException("Insufficient merchant payable ledger balance ({$merchantBalance} {$currency}) to issue refund net of {$refundNet} {$currency}");
            }

            // Create refund record
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

            try {
                $gwSlugVal = $txn['gateway_slug'] ?? '';
                $gwSlug = is_scalar($gwSlugVal) ? (string)$gwSlugVal : '';
                $gwTrxIdVal = $txn['gateway_trx_id'] ?? $txn['trx_id'] ?? '';
                $gwTrxId = is_scalar($gwTrxIdVal) ? (string)$gwTrxIdVal : '';

                $result = $this->bridge->refund(
                    $gwSlug,
                    $merchantId,
                    $gwTrxId,
                    (string)$amount
                );

                if ($result['success']) {
                    $this->refunds->forTenant($merchantId)->updateScoped((int)$id, [
                        'status' => 'completed',
                        'processed_at' => DateHelper::nowMicro()
                    ]);
                    
                    // Ledger recording
                    $currencyVal = $txn['currency'] ?? 'BDT';
                    $currency = is_scalar($currencyVal) ? (string)$currencyVal : 'BDT';
                    $this->ledger->recordRefund($merchantId, (int)$id, $txnId, (string)$amount, $currency);

                    if (bccomp($newTotal, $origAmount, 2) === 0) {
                        $this->transactions->forTenant($merchantId)->updateScoped($txnId, [
                            'status' => 'refunded'
                        ]);
                    }
                    $refund['status'] = 'completed';
                } else {
                    $this->refunds->forTenant($merchantId)->updateScoped((int)$id, [
                        'status' => 'failed'
                    ]);
                    $refund['status'] = 'failed';
                }
            } catch (\Throwable $e) {
                $this->refunds->forTenant($merchantId)->updateScoped((int)$id, [
                    'status' => 'failed'
                ]);
                $refund['status'] = 'failed';
                throw new RuntimeException('Gateway refund failed: ' . $e->getMessage());
            }
        });

        if ($refund === null) {
            throw new RuntimeException("Failed to complete refund transaction");
        }

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
