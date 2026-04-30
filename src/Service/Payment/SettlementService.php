<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\SettlementRepository;

/**
 * Settlement service — batch payouts to merchant bank accounts.
 */
final class SettlementService
{
    private SettlementRepository $settlements;
    private LedgerService $ledger;

    public function __construct(SettlementRepository $settlements, LedgerService $ledger)
    {
        $this->settlements = $settlements;
        $this->ledger = $ledger;
    }

    /**
     * Create settlement batch.
     */
    public function createBatch(int $merchantId, string $amount, string $currency, ?string $bankReference = null): array
    {
        $balance = $this->ledger->calculateBalance($merchantId, $currency);

        if (bccomp($balance, $amount, 2) < 0) {
            return ['success' => false, 'error' => 'Insufficient balance'];
        }

        $id = $this->settlements->create([
            'merchant_id'    => $merchantId,
            'amount'         => $amount,
            'currency'       => $currency,
            'bank_reference' => $bankReference,
            'status'         => 'pending',
        ]);

        return ['success' => true, 'settlement_id' => $id];
    }

    /**
     * Mark settlement as completed (after bank confirms).
     */
    public function markCompleted(int $settlementId): void
    {
        $settlement = $this->settlements->find($settlementId);
        if ($settlement === null) {
            return;
        }

        $this->settlements->update($settlementId, [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->ledger->recordSettlement(
            (int) $settlement['merchant_id'],
            $settlement['amount'],
            $settlement['currency'],
            $settlement['bank_reference'] ?? null
        );
    }
}
