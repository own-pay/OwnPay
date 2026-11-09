<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\LedgerRepository;
use OwnPay\Repository\TransactionRepository;

/**
 * Ledger service - double-entry bookkeeping for every money movement.
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
     *
     * @param int $merchantId The merchant/brand ID.
     * @param string $eventType The type of transaction event.
     * @param string $currency The transaction currency.
     * @param array<int, array{account: string, type: 'debit'|'credit', amount: string}> $entries The double-entry bookkeeping ledger entries.
     * @param string $referenceType The type of referenced entity (e.g. 'transaction').
     * @param string $referenceId The unique ID of the referenced entity.
     * @param string|null $description Optional descriptive text.
     * @return void
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
            $amount = (string) $entry['amount'];
            /** @var numeric-string $amount */

            $acctType = $this->getAccountType($code);
            $account = $this->ledger->findOrCreateAccount($code, $acctType, $currency, $merchantId);

            $acctIdVal = $account['id'] ?? 0;
            $resolvedEntries[] = [
                'account_id' => is_scalar($acctIdVal) ? (int) $acctIdVal : 0,
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
        try {
            $db->transaction(function () use ($scopedLedger, $merchantId, $eventType, $resolvedEntries, $currency, $referenceType, $referenceId, $description, $totalDebit) {
                // Check for pre-existing transaction to prevent double ledger posting
                $exists = $scopedLedger->getDatabase()->fetchOne(
                    "SELECT `id` FROM `op_ledger_transactions`
                 WHERE `merchant_id` = :mid
                   AND `reference_type` = :rt
                   AND `reference_id` = :ri
                   AND `description` = :desc
                 FOR UPDATE",
                    [
                        'mid' => $merchantId,
                        'rt' => $referenceType,
                        'ri' => (int) $referenceId,
                        'desc' => $description ?? $eventType
                    ]
                );

                if ($exists !== null) {
                    return;
                }

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
        } catch (\PDOException $e) {
            // Issue #335 (PAY-7): despite the SELECT ... FOR UPDATE pre-check, two
            // concurrent postEntries() calls can both observe "no row" and both
            // attempt to INSERT the same (merchant_id, reference_type, reference_id,
            // description) tuple. The database-level UNIQUE KEY uk_merchant_ref
            // (added in migration 003) is the authoritative guard. When the second
            // INSERT loses the race it raises MySQL errno 1062 / SQLSTATE 23000 -
            // treat that as "already posted" and bail gracefully instead of
            // propagating the exception to the caller (which would surface as a
            // 500 to the customer and might trigger a harmful retry).
            if ($this->isDuplicateKeyError($e)) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Determines whether a PDO exception represents a MySQL duplicate-key
     * violation (errno 1062) against the uk_merchant_ref unique constraint.
     *
     * SQLSTATE 23000 alone is insufficient - it also covers foreign-key
     * violations, which must propagate as real errors. We additionally match
     * the constraint name so that an unrelated unique-key violation on a
     * different index is not silently swallowed.
     *
     * @param \PDOException $e The caught PDO exception.
     * @return bool True when the failure is a uk_merchant_ref conflict.
     */
    private function isDuplicateKeyError(\PDOException $e): bool
    {
        if (!isset($e->errorInfo[1]) || (int) $e->errorInfo[1] !== 1062) {
            return false;
        }
        $message = $e->getMessage();
        return str_contains($message, 'uk_merchant_ref')
            || str_contains($message, "key 'merchant_id'");
    }

    /**
     * Record payment received (credit merchant).
     */
    public function recordPaymentReceived(int $merchantId, int $transactionId, string $amount, string $fee, string $currency): void
    {
        /** @var numeric-string $amount */
        /** @var numeric-string $fee */
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
    public function recordRefund(int $merchantId, int $refundId, int $transactionId, string $amount, string $currency): void
    {
        $txn = $this->transactions->forTenant($merchantId)->findScoped($transactionId);
        if ($txn === null) {
            throw new \RuntimeException("Transaction not found: {$transactionId}");
        }

        // Defense-in-depth brand guard (issue #77): the scoped findScoped()
        // above already returns null when the transaction does not belong to
        // $merchantId, so this check is redundant in the normal path. We keep
        // it as an explicit guard so a future refactor that swaps the lookup
        // for an unscoped one cannot silently post a refund ledger entry to
        // the wrong brand's books. The transaction's own currency is also
        // asserted to match the requested posting currency - a currency
        // mismatch would indicate a logic error upstream and would unbalance
        // the brand's books once posted.
        $txnMerchantVal = $txn['merchant_id'] ?? null;
        $txnMerchant = is_scalar($txnMerchantVal) ? (int) $txnMerchantVal : 0;
        if ($txnMerchant !== $merchantId) {
            throw new \RuntimeException(
                "Cross-brand refund blocked: transaction #{$transactionId} belongs to merchant {$txnMerchant}, cannot refund from merchant {$merchantId}"
            );
        }

        $amountVal = $txn['amount'] ?? '0.00';
        $feeVal = $txn['fee'] ?? '0.00';
        $origGross = is_scalar($amountVal) ? (string) $amountVal : '0.00';
        $origFee = is_scalar($feeVal) ? (string) $feeVal : '0.00';

        /** @var numeric-string $origGross */
        /** @var numeric-string $origFee */
        /** @var numeric-string $amount */
        if (bccomp($origGross, '0.00', 4) > 0) {
            $ratio = bcdiv($origFee, $origGross, 18);
            /** @var numeric-string $ratio */
            $refundFee = bcmul($amount, $ratio, 4);
        } else {
            $refundFee = '0.00';
        }

        /** @var numeric-string $refundFee */
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
            'refund',
            (string) $refundId,
            "Refund issued for txn #{$transactionId}"
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
     *
     * @param int|null $merchantId The merchant/brand ID, or null for global (all brands).
     * @param int $page The page number.
     * @param int $perPage The number of entries per page.
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int} The paginated ledger entries result.
     */
    public function entries(?int $merchantId, int $page = 1, int $perPage = 50): array
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
