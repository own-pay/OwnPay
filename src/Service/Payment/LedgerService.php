<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\LedgerRepository;
use OwnPay\Repository\TransactionRepository;

/**
 * Ledger Service
 *
 * Implements double-entry bookkeeping operations for all financial transactions.
 * Manages balanced debit/credit posting across merchant asset, liability, and revenue
 * accounts using precise string calculations (BCMath).
 */
final class LedgerService
{
    /**
     * @var LedgerRepository Repository handling ledger database accounts and entries.
     */
    private LedgerRepository $ledger;

    /**
     * @var EventManager The system-wide event manager.
     */
    private EventManager $events;

    /**
     * @var TransactionRepository Repository mapping core transactions.
     */
    private TransactionRepository $transactions;

    /**
     * Constructor.
     *
     * @param LedgerRepository $ledger Repository handling ledger database accounts and entries.
     * @param EventManager $events The system-wide event manager.
     * @param TransactionRepository $transactions Repository mapping core transactions.
     */
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
     * Resolve the corresponding classification type for a ledger account code.
     *
     * @param string $code The unique account designation code.
     * @return string The resolved account type ('asset', 'liability', or 'revenue').
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
     * Post a balanced journal entry mapping a simple debit and credit account pairing.
     *
     * @param int $merchantId Brand context identifier.
     * @param string $eventType Event categorization tag.
     * @param string $amount Value string representation.
     * @param string $currency ISO 3-letter currency code.
     * @param string $debitAccountCode Account code to debit.
     * @param string $creditAccountCode Account code to credit.
     * @param string $referenceType Source model designation tag.
     * @param string $referenceId Primary database ID of the source model.
     * @param string|null $description Narrative context describing the entry.
     * @return void
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
        $entries = [
            ['account' => $debitAccountCode, 'type' => 'debit', 'amount' => $amount],
            ['account' => $creditAccountCode, 'type' => 'credit', 'amount' => $amount]
        ];
        $this->postEntries($merchantId, $eventType, $currency, $entries, $referenceType, $referenceId, $description);
    }

    /**
     * Post a balanced multi-entry journal transaction structure.
     *
     * Validates that the sum of debit entry amounts equals the sum of credit entry
     * amounts to guarantee double-entry balance constraints before database writes.
     *
     * @param int $merchantId Brand context identifier.
     * @param string $eventType Event categorization tag.
     * @param string $currency ISO 3-letter currency code.
     * @param array $entries Collection of debit/credit entry arrays containing account, type, and amount.
     * @param string $referenceType Source model designation tag.
     * @param string $referenceId Primary database ID of the source model.
     * @param string|null $description Narrative context describing the entry.
     * @return void
     * @throws \InvalidArgumentException If debit and credit sums are unbalanced.
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
            $type = $entry['type'];
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

        // Safety check to ensure debits equal credits.
        if (bccomp($totalDebit, $totalCredit, 4) !== 0) {
            throw new \InvalidArgumentException("Ledger transaction is not balanced. Debits: {$totalDebit}, Credits: {$totalCredit}");
        }

        $scopedLedger = $this->ledger->forTenant($merchantId);
        $db = $scopedLedger->getDatabase();
        $db->transaction(function () use ($scopedLedger, $merchantId, $eventType, $resolvedEntries, $currency, $referenceType, $referenceId, $description, $totalDebit) {
            
            // Create Journal Header mapping source reference and merchant id scope.
            $txnId = $scopedLedger->createTransaction(
                $referenceType,
                (int) $referenceId,
                $description ?? $eventType
            );

            // Create individual entry records and recalculate target account balances.
            foreach ($resolvedEntries as $entry) {
                $scopedLedger->createEntry($txnId, $entry['account_id'], $entry['type'], $entry['amount']);
                $scopedLedger->adjustBalance($entry['account_id'], $entry['amount'], $entry['type']);
            }

            // Dispatch ledger entry hook payload.
            $this->events->doAction('ledger.entry.created', [
                'transaction_id' => $txnId,
                'merchant_id'    => $merchantId,
                'amount'         => $totalDebit,
                'currency'       => $currency
            ]);
        });
    }

    /**
     * Record a customer payment capture and split gross amount into payable and fee entries.
     *
     * @param int $merchantId Brand context identifier.
     * @param int $transactionId Linked transaction database identifier.
     * @param string $amount Gross payment amount captured.
     * @param string $fee Platform routing fee levied.
     * @param string $currency ISO 3-letter currency code.
     * @return void
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
     * Record a full or partial refund event against a captured payment.
     *
     * Calculates the proportionate fee reversal share using ratio calculations
     * and performs cash and merchant balance adjustments.
     *
     * @param int $merchantId Brand context identifier.
     * @param int $transactionId Linked transaction database identifier.
     * @param string $amount The refund amount value.
     * @param string $currency ISO 3-letter currency code.
     * @return void
     * @throws \RuntimeException If the referenced transaction record cannot be resolved.
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
     * Record a settlement payout event and adjust payable/bank accounts.
     *
     * @param int $merchantId Brand context identifier.
     * @param string $amount Payout value string.
     * @param string $currency ISO 3-letter currency code.
     * @param string|null $reference Settlement lookup/batch identifier.
     * @return void
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
     * Compute the current aggregate account balance for a merchant in a specific currency.
     *
     * @param int $merchantId Brand context identifier.
     * @param string $currency ISO 3-letter currency code.
     * @return string Balance amount string representation.
     */
    public function calculateBalance(int $merchantId, string $currency): string
    {
        return $this->ledger->merchantBalance($merchantId, $currency);
    }

    /**
     * Retrieve and paginate historical ledger transaction records for a merchant.
     *
     * @param int $merchantId Brand context identifier.
     * @param int $page Target page index mapping.
     * @param int $perPage Count limit per pagination segment.
     * @return array Standardized pagination response payload.
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
