<?php

declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Core\Database;
use OwnPay\Repository\DisputeRepository;
use OwnPay\Repository\TransactionRepository;
use InvalidArgumentException;

/**
 * DisputeService — dispute lifecycle management.
 *
 * Flow: open → under_review → won | lost
 *
 * When a dispute is opened, the transaction amount is held.
 * On resolution (lost), a refund is issued. On resolution (won),
 * the hold is released.
 */
final class DisputeService
{
    private Database $db;
    private DisputeRepository $disputes;
    private TransactionRepository $transactions;
    private LedgerService $ledger;
    private AuditLogger $audit;

    public function __construct(
        ?Database $db = null,
        ?DisputeRepository $disputes = null,
        ?TransactionRepository $transactions = null,
        ?LedgerService $ledger = null,
        ?AuditLogger $audit = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->disputes = $disputes ?? new DisputeRepository();
        $this->transactions = $transactions ?? new TransactionRepository();
        $this->ledger = $ledger ?? new LedgerService();
        $this->audit = $audit ?? new AuditLogger();
    }

    /**
     * Open a dispute for a transaction.
     *
     * @param int    $merchantId
     * @param int    $transactionId  Internal transaction ID
     * @param string $reason         Dispute reason
     * @param string $disputeType    'chargeback', 'inquiry', 'fraud'
     * @return array Dispute record
     */
    public function openDispute(
        int $merchantId,
        int $transactionId,
        string $reason,
        string $disputeType = 'chargeback'
    ): array {
        return $this->db->transactional(function () use ($merchantId, $transactionId, $reason, $disputeType) {
            // Validate transaction
            $txn = $this->transactions->findById($transactionId);
            if ($txn === null || (int) $txn['merchant_id'] !== $merchantId) {
                throw new InvalidArgumentException('Transaction not found.');
            }

            if (!in_array($txn['status'], ['completed', 'settled'], true)) {
                throw new InvalidArgumentException(
                    "Cannot dispute a transaction with status '{$txn['status']}'. Only completed/settled transactions can be disputed."
                );
            }

            // Check for existing open dispute
            $existing = $this->disputes->findByTransactionId($transactionId);
            if ($existing !== null) {
                throw new InvalidArgumentException('An active dispute already exists for this transaction.');
            }

            // Create dispute
            $disputeId = $this->disputes->insert([
                'merchant_id' => $merchantId,
                'transaction_id' => $transactionId,
                'amount' => $txn['amount'],
                'currency' => $txn['currency'],
                'reason' => $reason,
                'dispute_type' => $disputeType,
                'status' => 'open',
            ]);

            $dispute = $this->disputes->findById($disputeId);

            // Post ledger hold entry
            $this->ledger->postDisputeHold(
                $merchantId,
                $dispute['public_id'],
                $txn['amount'],
                $txn['currency']
            );

            // Audit
            $this->audit->log(
                $merchantId,
                'dispute.opened',
                'dispute',
                $dispute['public_id'],
                'system',
                'dispute_service',
                null,
                [
                    'transaction_id' => $txn['public_id'],
                    'amount' => $txn['amount'],
                    'reason' => $reason,
                    'type' => $disputeType,
                ]
            );

            return $dispute;
        });
    }

    /**
     * Resolve a dispute.
     *
     * @param int    $disputeId
     * @param string $outcome    'won' (merchant wins) or 'lost' (customer wins)
     * @param string $resolution Explanation of the resolution
     * @param string|null $evidence Evidence/notes
     * @return array Updated dispute record
     */
    public function resolveDispute(
        int $disputeId,
        string $outcome,
        string $resolution,
        ?string $evidence = null
    ): array {
        if (!in_array($outcome, ['won', 'lost'], true)) {
            throw new InvalidArgumentException("Outcome must be 'won' or 'lost'.");
        }

        return $this->db->transactional(function () use ($disputeId, $outcome, $resolution, $evidence) {
            $dispute = $this->disputes->findById($disputeId);
            if ($dispute === null) {
                throw new InvalidArgumentException("Dispute #{$disputeId} not found.");
            }

            if (!in_array($dispute['status'], ['open', 'under_review'], true)) {
                throw new InvalidArgumentException("Dispute is not in a resolvable state (current: {$dispute['status']}).");
            }

            $merchantId = (int) $dispute['merchant_id'];

            // Resolve the dispute
            $this->disputes->resolve($disputeId, $outcome, $resolution, $evidence);

            if ($outcome === 'lost') {
                // Customer wins — release hold as refund
                $this->ledger->postDisputeLost(
                    $merchantId,
                    $dispute['public_id'],
                    $dispute['amount'],
                    $dispute['currency']
                );
            } else {
                // Merchant wins — release hold back to merchant
                $this->ledger->postDisputeWon(
                    $merchantId,
                    $dispute['public_id'],
                    $dispute['amount'],
                    $dispute['currency']
                );
            }

            // Audit
            $this->audit->log(
                $merchantId,
                "dispute.resolved_{$outcome}",
                'dispute',
                $dispute['public_id'],
                'system',
                'dispute_service',
                ['status' => $dispute['status']],
                ['status' => $outcome, 'resolution' => $resolution]
            );

            return $this->disputes->findById($disputeId);
        });
    }

    /**
     * Move a dispute to under_review status.
     */
    public function markUnderReview(int $disputeId): array
    {
        $dispute = $this->disputes->findById($disputeId);
        if ($dispute === null) {
            throw new InvalidArgumentException("Dispute #{$disputeId} not found.");
        }

        if ($dispute['status'] !== 'open') {
            throw new InvalidArgumentException("Only open disputes can be moved to under_review.");
        }

        $this->db->execute(
            "UPDATE op_disputes SET status = 'under_review', updated_at = NOW(6) WHERE id = :id",
            [':id' => $disputeId]
        );

        $this->audit->log(
            (int) $dispute['merchant_id'],
            'dispute.under_review',
            'dispute',
            $dispute['public_id'],
            'system',
            'dispute_service',
            ['status' => 'open'],
            ['status' => 'under_review']
        );

        return $this->disputes->findById($disputeId);
    }
}
