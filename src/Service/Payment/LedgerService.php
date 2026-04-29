<?php

declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Core\Database;
use OwnPay\Repository\LedgerRepository;
use InvalidArgumentException;

/**
 * LedgerService — immutable double-entry accounting.
 *
 * Rules:
 *   1. Every journal MUST balance: sum(debit) === sum(credit)
 *   2. Entries are immutable — corrections via reversal journals only
 *   3. Account locks acquired in ascending account_id order (deadlock prevention)
 *   4. All operations in a single DB transaction
 */
final class LedgerService
{
    private Database $db;
    private LedgerRepository $repo;

    public function __construct(?Database $db = null, ?LedgerRepository $repo = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->repo = $repo ?? new LedgerRepository();
    }

    /**
     * Post a balanced journal entry.
     *
     * @param string $eventType     e.g. 'payment.completed', 'refund.issued'
     * @param string $referenceType e.g. 'transaction', 'refund'
     * @param string $referenceId   Public UUID of the referenced entity
     * @param array  $entries       Array of entry descriptors:
     *   [
     *     ['account_id' => 1, 'type' => 'debit',  'amount' => '1500.0000'],
     *     ['account_id' => 2, 'type' => 'credit', 'amount' => '1500.0000'],
     *   ]
     * @param string $currency      ISO 4217
     * @param string|null $description
     * @return int The ledger_transaction ID
     */
    public function postTransaction(
        string $eventType,
        string $referenceType,
        string $referenceId,
        array $entries,
        string $currency = 'BDT',
        ?string $description = null
    ): int {
        if (empty($entries)) {
            throw new InvalidArgumentException('Ledger entries cannot be empty.');
        }

        // Validate balance BEFORE hitting the database
        $totalDebit = '0';
        $totalCredit = '0';

        foreach ($entries as $entry) {
            if (!isset($entry['account_id'], $entry['type'], $entry['amount'])) {
                throw new InvalidArgumentException(
                    'Each entry must have: account_id, type (debit|credit), amount.'
                );
            }

            if (!in_array($entry['type'], ['debit', 'credit'], true)) {
                throw new InvalidArgumentException(
                    "Invalid entry type: '{$entry['type']}'. Must be 'debit' or 'credit'."
                );
            }

            if (bccomp($entry['amount'], '0', 4) <= 0) {
                throw new InvalidArgumentException('Entry amount must be positive.');
            }

            if ($entry['type'] === 'debit') {
                $totalDebit = bcadd($totalDebit, $entry['amount'], 4);
            } else {
                $totalCredit = bcadd($totalCredit, $entry['amount'], 4);
            }
        }

        if (bccomp($totalDebit, $totalCredit, 4) !== 0) {
            throw new InvalidArgumentException(
                "Ledger entries are unbalanced: debit={$totalDebit}, credit={$totalCredit}. " .
                "Sum of debits must equal sum of credits."
            );
        }

        // Sort entries by account_id ASC (deadlock prevention)
        usort($entries, fn($a, $b) => $a['account_id'] <=> $b['account_id']);

        return $this->db->transactional(function () use ($eventType, $referenceType, $referenceId, $entries, $currency, $totalDebit, $description) {
            // Create journal header
            $journalId = $this->repo->createTransaction(
                $eventType,
                $referenceType,
                $referenceId,
                $totalDebit,  // total_amount = sum of one side
                $currency,
                $description
            );

            // Create entries and adjust balances
            foreach ($entries as $entry) {
                $this->repo->createEntry(
                    $journalId,
                    $entry['account_id'],
                    $entry['type'],
                    $entry['amount'],
                    $currency
                );

                // Adjust account balance:
                //   debit  → positive for asset/expense, negative for liability/equity/revenue
                //   credit → opposite
                // Simplified: debit = +amount, credit = -amount on the balance field
                $balanceChange = $entry['type'] === 'debit'
                    ? $entry['amount']
                    : bcmul($entry['amount'], '-1', 4);

                $this->repo->adjustBalance($entry['account_id'], $balanceChange);
            }

            return $journalId;
        });
    }

    /**
     * Convenience: post a payment.completed event.
     *
     * Debits the merchant's receivable account, credits the gateway liability.
     */
    public function postPaymentCompleted(
        int $merchantId,
        string $transactionPublicId,
        string $amount,
        string $currency = 'BDT'
    ): int {
        // Find or create standard accounts
        $receivable = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_receivable",
            "Merchant #{$merchantId} Receivable",
            'asset',
            $currency,
            $merchantId
        );

        $revenue = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_revenue",
            "Merchant #{$merchantId} Revenue",
            'revenue',
            $currency,
            $merchantId
        );

        return $this->postTransaction(
            'payment.completed',
            'transaction',
            $transactionPublicId,
            [
                ['account_id' => $receivable['id'], 'type' => 'debit', 'amount' => $amount],
                ['account_id' => $revenue['id'], 'type' => 'credit', 'amount' => $amount],
            ],
            $currency,
            "Payment received: {$amount} {$currency}"
        );
    }

    /**
     * Convenience: post a refund.issued event.
     */
    public function postRefundIssued(
        int $merchantId,
        string $refundPublicId,
        string $amount,
        string $currency = 'BDT'
    ): int {
        $receivable = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_receivable",
            "Merchant #{$merchantId} Receivable",
            'asset',
            $currency,
            $merchantId
        );

        $revenue = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_revenue",
            "Merchant #{$merchantId} Revenue",
            'revenue',
            $currency,
            $merchantId
        );

        return $this->postTransaction(
            'refund.issued',
            'refund',
            $refundPublicId,
            [
                // Reverse of payment: credit receivable, debit revenue
                ['account_id' => $receivable['id'], 'type' => 'credit', 'amount' => $amount],
                ['account_id' => $revenue['id'], 'type' => 'debit', 'amount' => $amount],
            ],
            $currency,
            "Refund issued: {$amount} {$currency}"
        );
    }

    /**
     * Convenience: post a settlement event.
     * Debit the merchant's receivable (reduce), credit payout account.
     */
    public function postSettlement(
        int $merchantId,
        string $settlementPublicId,
        string $netAmount,
        string $feeAmount,
        string $currency = 'BDT'
    ): int {
        $receivable = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_receivable",
            "Merchant #{$merchantId} Receivable",
            'asset',
            $currency,
            $merchantId
        );

        $payout = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_payout",
            "Merchant #{$merchantId} Payout",
            'liability',
            $currency,
            $merchantId
        );

        $feeAccount = $this->repo->findOrCreateAccount(
            "platform_fee_revenue",
            "Platform Fee Revenue",
            'revenue',
            $currency,
            null
        );

        $entries = [
            ['account_id' => $receivable['id'], 'type' => 'credit', 'amount' => bcadd($netAmount, $feeAmount, 4)],
            ['account_id' => $payout['id'], 'type' => 'debit', 'amount' => $netAmount],
        ];

        if (bccomp($feeAmount, '0', 4) > 0) {
            $entries[] = ['account_id' => $feeAccount['id'], 'type' => 'debit', 'amount' => $feeAmount];
        }

        return $this->postTransaction(
            'settlement.created',
            'settlement',
            $settlementPublicId,
            $entries,
            $currency,
            "Settlement payout: {$netAmount} {$currency} (fee: {$feeAmount})"
        );
    }

    /**
     * Convenience: post a dispute hold (freeze merchant funds).
     */
    public function postDisputeHold(
        int $merchantId,
        string $disputePublicId,
        string $amount,
        string $currency = 'BDT'
    ): int {
        $receivable = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_receivable",
            "Merchant #{$merchantId} Receivable",
            'asset',
            $currency,
            $merchantId
        );

        $holdAccount = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_dispute_hold",
            "Merchant #{$merchantId} Dispute Hold",
            'liability',
            $currency,
            $merchantId
        );

        return $this->postTransaction(
            'dispute.hold',
            'dispute',
            $disputePublicId,
            [
                ['account_id' => $receivable['id'], 'type' => 'credit', 'amount' => $amount],
                ['account_id' => $holdAccount['id'], 'type' => 'debit', 'amount' => $amount],
            ],
            $currency,
            "Dispute hold: {$amount} {$currency}"
        );
    }

    /**
     * Convenience: merchant wins dispute — release hold back to receivable.
     */
    public function postDisputeWon(
        int $merchantId,
        string $disputePublicId,
        string $amount,
        string $currency = 'BDT'
    ): int {
        $receivable = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_receivable",
            "Merchant #{$merchantId} Receivable",
            'asset',
            $currency,
            $merchantId
        );

        $holdAccount = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_dispute_hold",
            "Merchant #{$merchantId} Dispute Hold",
            'liability',
            $currency,
            $merchantId
        );

        return $this->postTransaction(
            'dispute.resolved_won',
            'dispute',
            $disputePublicId,
            [
                ['account_id' => $holdAccount['id'], 'type' => 'credit', 'amount' => $amount],
                ['account_id' => $receivable['id'], 'type' => 'debit', 'amount' => $amount],
            ],
            $currency,
            "Dispute resolved (merchant won): {$amount} {$currency} released"
        );
    }

    /**
     * Convenience: customer wins dispute — debit hold to refund.
     */
    public function postDisputeLost(
        int $merchantId,
        string $disputePublicId,
        string $amount,
        string $currency = 'BDT'
    ): int {
        $holdAccount = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_dispute_hold",
            "Merchant #{$merchantId} Dispute Hold",
            'liability',
            $currency,
            $merchantId
        );

        $refundExpense = $this->repo->findOrCreateAccount(
            "merchant_{$merchantId}_dispute_loss",
            "Merchant #{$merchantId} Dispute Loss",
            'expense',
            $currency,
            $merchantId
        );

        return $this->postTransaction(
            'dispute.resolved_lost',
            'dispute',
            $disputePublicId,
            [
                ['account_id' => $holdAccount['id'], 'type' => 'credit', 'amount' => $amount],
                ['account_id' => $refundExpense['id'], 'type' => 'debit', 'amount' => $amount],
            ],
            $currency,
            "Dispute resolved (customer won): {$amount} {$currency} refunded"
        );
    }

    /**
     * Verify the balanced invariant for a journal transaction.
     */
    public function verifyBalance(int $journalId): bool
    {
        return $this->repo->isBalanced($journalId);
    }
}
