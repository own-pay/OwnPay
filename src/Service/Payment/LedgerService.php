<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\LedgerRepository;

/**
 * Ledger service Ã¢€— double-entry bookkeeping for every money movement.
 * Uses strict triple-table schema (accounts, transactions, entries).
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
     * Resolve type for standard ledger accounts.
     */
    public function getAccountType(string $code): string
    {
        $code = strtoupper($code);
        switch ($code) {
            case 'CASH':
            case 'BANK_OUT':
                return 'asset';
            case 'MERCHANT_PAYABLE':
                return 'liability';
            default:
                return 'asset';
        }
    }

    /**
     * Internal method to post a balanced journal entry.
     */
    private function postJournal(
        int $merchantId,
        string $eventType,
        string $amount,
        string $currency,
        string $debitAccountCode,
        string $creditAccountCode,
        string $referenceType,
        string $referenceId,
        ?string $description = null
    ): void {
        // 1. Ensure accounts exist with correct resolved types
        $drType = $this->getAccountType($debitAccountCode);
        $crType = $this->getAccountType($creditAccountCode);
        $drAccount = $this->ledger->findOrCreateAccount($debitAccountCode, $drType, $currency, $merchantId);
        $crAccount = $this->ledger->findOrCreateAccount($creditAccountCode, $crType, $currency, $merchantId);

        $this->ledger->forTenant($merchantId);
        $db = $this->ledger->getDatabase();
        $db->transaction(function () use ($merchantId, $eventType, $amount, $currency, $drAccount, $crAccount, $referenceType, $referenceId, $description) {
            
            // 2. Create Journal Header
            $txnId = $this->ledger->createTransaction(
                $referenceType,
                (int) $referenceId,
                $description ?? $eventType
            );

            // 3. Create Entries (Debit + Credit)
            $this->ledger->createEntry($txnId, (int) $drAccount['id'], 'debit', $amount);
            $this->ledger->createEntry($txnId, (int) $crAccount['id'], 'credit', $amount);

            // 4. Update Balances
            $this->ledger->adjustBalance((int) $drAccount['id'], $amount, 'debit');
            $this->ledger->adjustBalance((int) $crAccount['id'], $amount, 'credit');

            // Fire event
            $this->events->doAction('ledger.entry.created', [
                'transaction_id' => $txnId,
                'merchant_id'    => $merchantId,
                'amount'         => $amount,
                'currency'       => $currency
            ]);
        });
    }

    /**
     * Record payment received (credit merchant).
     */
    public function recordPaymentReceived(int $merchantId, int $transactionId, string $amount, string $fee, string $currency): void
    {
        $net = bcsub($amount, $fee, 4);
        
        $this->postJournal(
            $merchantId,
            'payment_received',
            $net,
            $currency,
            'CASH',
            'MERCHANT_PAYABLE',
            'transaction',
            (string) $transactionId,
            "Payment received (fee: {$fee})"
        );
    }

    /**
     * Record refund (debit merchant).
     */
    public function recordRefund(int $merchantId, int $transactionId, string $amount, string $currency): void
    {
        $this->postJournal(
            $merchantId,
            'refund',
            $amount,
            $currency,
            'MERCHANT_PAYABLE',
            'CASH',
            'transaction',
            (string) $transactionId,
            "Refund issued"
        );
    }

    /**
     * Record settlement payout (debit merchant).
     */
    public function recordSettlement(int $merchantId, string $amount, string $currency, ?string $reference = null): void
    {
        $this->postJournal(
            $merchantId,
            'settlement',
            $amount,
            $currency,
            'MERCHANT_PAYABLE',
            'BANK_OUT',
            'settlement',
            $reference ?? 'unknown',
            "Settlement payout"
        );
    }

    /**
     * Get current balance for merchant in currency.
     */
    public function calculateBalance(int $merchantId, string $currency): string
    {
        return $this->ledger->merchantBalance($merchantId, $currency);
    }

    /**
     * Get ledger transactions for merchant (paginated).
     */
    public function entries(int $merchantId, int $page = 1, int $perPage = 50): array
    {
        $result = $this->ledger->entriesPaginated($merchantId, $page, $perPage);

        return [
            'items'       => $result['items'],
            'total'       => $result['total'],
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($result['total'] / $perPage)
        ];
    }
}
