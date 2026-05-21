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
        
        $txn = $this->transactions->forTenant($merchantId)->findScoped($transactionId);
        if (!$txn) {
            throw new InvalidArgumentException('Transaction not found');
        }

        if ($txn['status'] !== 'completed') {
            throw new InvalidArgumentException('Only completed transactions can be refunded');
        }

        $alreadyRefunded = $this->refunds->getTotalRefundedAmount($txn['id'], $merchantId);

        if ($amount === null || !is_numeric($amount) || bccomp((string)$amount, '0', 2) <= 0) {
            $amount = bcsub($txn['amount'], $alreadyRefunded, 2);
        }

        if (bccomp((string)$amount, '0.00', 2) <= 0) {
            throw new InvalidArgumentException('No remaining amount left to refund');
        }

        $newTotal = bcadd($alreadyRefunded, (string)$amount, 2);
        if (bccomp($newTotal, $txn['amount'], 2) > 0) {
            throw new InvalidArgumentException('Refund amount cannot exceed transaction amount');
        }

        // Create refund record
        $id = $this->refunds->forTenant($merchantId)->createRefund([
            'transaction_id' => $txn['id'],
            'amount' => (string)$amount,
            'reason' => $data['reason'] ?? '',
            'status' => 'pending'
        ]);

        $refund = $this->refunds->forTenant($merchantId)->findScoped((int)$id);
        if (!$refund) {
            throw new RuntimeException("Refund record not found after creation");
        }

        try {
            $result = $this->bridge->refund(
                $txn['gateway_slug'],
                $merchantId,
                $txn['gateway_trx_id'] ?? $txn['trx_id'],
                (string)$amount
            );

            if ($result['success']) {
                $this->refunds->forTenant($merchantId)->updateScoped((int)$id, [
                    'status' => 'completed',
                    'processed_at' => DateHelper::nowMicro()
                ]);
                
                // Ledger recording
                $this->ledger->recordRefund($merchantId, $txn['id'], (string)$amount, $txn['currency']);

                if (bccomp($newTotal, $txn['amount'], 2) === 0) {
                    $this->transactions->forTenant($merchantId)->updateScoped($txn['id'], [
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
