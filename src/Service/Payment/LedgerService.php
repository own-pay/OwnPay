<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\LedgerRepository;

/**
 * Ledger service — double-entry bookkeeping for every money movement.
 *
 * Fires: ledger.entry.created
 */
final class LedgerService
{
    private LedgerRepository $ledger;
    private EventManager $events;

    public function __construct(LedgerRepository $ledger, EventManager $events)
    {
        $this->ledger = $ledger;
        $this->events = $events;
    }

    /**
     * Record a ledger entry (debit + credit).
     * Every transaction creates two entries for balance.
     */
    public function record(
        int $merchantId,
        string $type,
        string $debit,
        string $credit,
        string $currency,
        ?int $transactionId = null,
        ?string $description = null
    ): void {
        $balance = $this->calculateBalance($merchantId, $currency);
        $newBalance = bcadd(bcsub($balance, $debit, 2), $credit, 2);

        $id = $this->ledger->create([
            'merchant_id'    => $merchantId,
            'transaction_id' => $transactionId,
            'type'           => $type,
            'debit'          => $debit,
            'credit'         => $credit,
            'balance'        => $newBalance,
            'currency'       => $currency,
            'description'    => $description,
        ]);

        $entry = $this->ledger->find((int) $id);
        $this->events->doAction('ledger.entry.created', $entry);
    }

    /**
     * Record payment received (credit merchant).
     */
    public function recordPaymentReceived(int $merchantId, int $transactionId, string $amount, string $fee, string $currency): void
    {
        $this->record(
            $merchantId,
            'payment_received',
            '0.00',
            bcsub($amount, $fee, 2),
            $currency,
            $transactionId,
            "Payment received (fee: {$fee})"
        );
    }

    /**
     * Record refund (debit merchant).
     */
    public function recordRefund(int $merchantId, int $transactionId, string $amount, string $currency): void
    {
        $this->record(
            $merchantId,
            'refund',
            $amount,
            '0.00',
            $currency,
            $transactionId,
            "Refund issued"
        );
    }

    /**
     * Record settlement payout (debit merchant).
     */
    public function recordSettlement(int $merchantId, string $amount, string $currency, ?string $reference = null): void
    {
        $this->record(
            $merchantId,
            'settlement',
            $amount,
            '0.00',
            $currency,
            null,
            "Settlement payout" . ($reference ? " (ref: {$reference})" : '')
        );
    }

    /**
     * Get current balance for merchant in currency.
     */
    public function calculateBalance(int $merchantId, string $currency): string
    {
        $row = $this->ledger->getDb()->fetchOne(
            "SELECT balance FROM op_ledger
             WHERE merchant_id = :mid AND currency = :cur
             ORDER BY id DESC LIMIT 1",
            ['mid' => $merchantId, 'cur' => $currency]
        );
        return $row['balance'] ?? '0.00';
    }

    /**
     * Get ledger entries for merchant (paginated).
     */
    public function entries(int $merchantId, int $page = 1, int $perPage = 50): array
    {
        return $this->ledger->paginate(
            $page,
            $perPage,
            'merchant_id = :mid',
            ['mid' => $merchantId],
            'id DESC'
        );
    }
}
