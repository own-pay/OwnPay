<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\SettlementRepository;
use OwnPay\Support\DateHelper;

/**
 * Service managing merchant payouts and bank settlements.
 *
 * Verifies that the merchant possesses sufficient ledger balances prior to creating
 * payout batches, updates batch progress, and records corresponding settlement postings in the ledger.
 */
final class SettlementService
{
    /**
     * @var SettlementRepository Repository managing payout records.
     */
    private SettlementRepository $settlements;

    /**
     * @var LedgerService Bookkeeping service posting settlement transactions.
     */
    private LedgerService $ledger;

    /**
     * SettlementService constructor.
     *
     * @param SettlementRepository $settlements Repository for settlement database operations.
     * @param LedgerService $ledger Service compiling merchant account balances.
     */
    public function __construct(SettlementRepository $settlements, LedgerService $ledger)
    {
        $this->settlements = $settlements;
        $this->ledger = $ledger;
    }

    /**
     * Creates a new pending settlement payout batch for a brand.
     *
     * Validates that the brand/merchant has a sufficient balance in the target currency
     * before creating the entry in the database.
     *
     * @param int $merchantId The ID of the merchant/brand.
     * @param string $amount The payout amount.
     * @param string $currency The transaction ISO currency code.
     * @param string|null $bankReference Optional wire reference identifier.
     * @return array{success: bool, error?: string, settlement_id?: int|string} Payout creation status.
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
     * Marks a settlement batch as completed after receiving bank confirmation.
     *
     * Updates the status and commits the corresponding settlement balance removal to the ledger.
     *
     * @param int $settlementId The unique ID of the settlement batch to complete.
     * @return void
     */
    public function markCompleted(int $settlementId): void
    {
        $settlement = $this->settlements->find($settlementId);
        if ($settlement === null) {
            return;
        }

        $this->settlements->update($settlementId, [
            'status' => 'completed',
            'completed_at' => DateHelper::now(),
        ]);

        $this->ledger->recordSettlement(
            (int) $settlement['merchant_id'],
            $settlement['amount'],
            $settlement['currency'],
            $settlement['bank_reference'] ?? null
        );
    }
}
