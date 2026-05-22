<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\LedgerRepository;
use OwnPay\Repository\TransactionRepository;

/**
 * Ledger service — double-entry bookkeeping for every money movement.
 * Uses strict triple-table schema (accounts, transactions, entries).
 *
 * Fires: ledger.entry.created
 */
final class LedgerService
{
    private LedgerRepository $ledger;
    private EventManager $events;
    private TransactionRepository $transactions;

    public function __construct(
        LedgerRepository $ledger,
        EventManager $events,
        TransactionRepository $transactions
    ) {
        $this->ledger = $ledger;
        $this->events = $events;
        $this->transactions = $transactions;
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
            case 'PLATFORM_FEE_REVENUE':
                return 'revenue';
            default:
                return 'asset';
        }
    }



    /**
     * Post a balanced multi-entry journal transaction.
     */
    public function postEntries(
        int $merchantId,
        string $eventType,
        string $currency,
        array $entries,
        string $referenceType,
        string $referenceId,
        ?string $description = null
    ): void {
        $resolvedEntries = [];
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($entries as $entry) {
            $code = $entry['account'];
            $type = $entry['type']; // 'debit' or 'credit'
            $amount = $entry['amount'];

            $acctType = $this->getAccountType($code);
            $account = $this->ledger->findOrCreateAccount($code, $acctType, $currency, $merchantId);

            $resolvedEntries[] = [
                'account_id' => (int) $account['id'],
                'type' => $type,
                'amount' => $amount
            ];

            if ($type === 'debit') {
                $totalDebit = bcadd($totalDebit, $amount, 4);
            } else {
                $totalCredit = bcadd($totalCredit, $amount, 4);
            }
        }

        // Safety check to ensure debits equal credits
        if (bccomp($totalDebit, $totalCredit, 4) !== 0) {
            throw new \InvalidArgumentException("Ledger transaction is not balanced. Debits: {$totalDebit}, Credits: {$totalCredit}");
        }

        $scopedLedger = $this->ledger->forTenant($merchantId);
        $db = $scopedLedger->getDatabase();
        $db->transaction(function () use ($scopedLedger, $merchantId, $eventType, $resolvedEntries, $currency, $referenceType, $referenceId, $description, $totalDebit) {
            
            // 2. Create Journal Header (uses tenantId from scoped clone)
            $txnId = $scopedLedger->createTransaction(
                $referenceType,
                (int) $referenceId,
                $description ?? $eventType
            );

            // 3. Create Entries and Update Balances
            foreach ($resolvedEntries as $entry) {
                $scopedLedger->createEntry($txnId, $entry['account_id'], $entry['type'], $entry['amount']);
                $scopedLedger->adjustBalance($entry['account_id'], $entry['amount'], $entry['type']);
            }

            // Fire event
            $this->events->doAction('ledger.entry.created', [
                'transaction_id' => $txnId,
                'merchant_id'    => $merchantId,
                'amount'         => $totalDebit,
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
        
        $entries = [
            ['account' => 'CASH', 'type' => 'debit', 'amount' => $amount],
            ['account' => 'MERCHANT_PAYABLE', 'type' => 'credit', 'amount' => $net],
            ['account' => 'PLATFORM_FEE_REVENUE', 'type' => 'credit', 'amount' => $fee],
        ];

        $this->postEntries(
            $merchantId,
            'payment_received',
            $currency,
            $entries,
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
        $txn = $this->transactions->forTenant($merchantId)->findScoped($transactionId);
        if ($txn === null) {
            throw new \RuntimeException("Transaction not found: {$transactionId}");
        }

        $origGross = (string) $txn['amount'];
        $origFee = (string) ($txn['fee'] ?? '0.00');

        if (bccomp($origGross, '0.00', 4) > 0) {
            $ratio = bcdiv($origFee, $origGross, 18);
            $refundFee = bcmul($amount, $ratio, 4);
        } else {
            $refundFee = '0.00';
        }

        $refundNet = bcsub($amount, $refundFee, 4);

        $entries = [
            ['account' => 'CASH', 'type' => 'credit', 'amount' => $amount],
            ['account' => 'MERCHANT_PAYABLE', 'type' => 'debit', 'amount' => $refundNet],
            ['account' => 'PLATFORM_FEE_REVENUE', 'type' => 'debit', 'amount' => $refundFee],
        ];

        $this->postEntries(
            $merchantId,
            'refund',
            $currency,
            $entries,
            'transaction',
            (string) $transactionId,
            "Refund issued"
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
