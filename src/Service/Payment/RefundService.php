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

final class RefundService
{
    private RefundRepository $refunds;
    private TransactionRepository $transactions;
    private GatewayBridge $bridge;
    private LedgerService $ledger;

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

    public function create(int $merchantId, array $data): array
    {
        $transactionId = $data['transaction_id'];
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

    public function find(int $merchantId, int $id): ?array
    {
        return $this->refunds->forTenant($merchantId)->findScoped($id);
    }
}
