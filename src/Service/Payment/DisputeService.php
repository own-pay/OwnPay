<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\DisputeRepository;
use OwnPay\Repository\TransactionRepository;

/**
 * Manages chargeback and transaction disputes.
 *
 * Provides capabilities to open new disputes, track dispute statuses,
 * resolve disputes, and trigger actions/filters via the system event manager.
 */
final class DisputeService
{
    /**
     * @var DisputeRepository Repository for accessing and modifying dispute records.
     */
    private DisputeRepository $disputes;

    /**
     * @var EventManager Event dispatcher for registering and executing action/filter hooks.
     */
    private EventManager $events;

    /**
     * @var TransactionRepository Repository for transaction lookups used during dispute open() validation.
     */
    private TransactionRepository $transactions;

    /**
     * DisputeService constructor.
     *
     * @param DisputeRepository $disputes Repository for dispute database operations.
     * @param EventManager $events Event dispatcher for system hooks.
     * @param TransactionRepository $transactions Repository used to validate the underlying transaction
     *                                            before a dispute is opened (status, amount, prior disputes).
     */
    public function __construct(
        DisputeRepository $disputes,
        EventManager $events,
        TransactionRepository $transactions
    ) {
        $this->disputes = $disputes;
        $this->events = $events;
        $this->transactions = $transactions;
    }

    /**
     * Opens a new dispute record for a transaction.
     *
     * Scopes the dispute creation to the designated merchant/brand and fires the
     * `dispute.opened` event hook upon successful creation.
     *
     * Validation guards (issue #336, PAY-8):
     *   1. The referenced transaction must exist and belong to $merchantId
     *      (otherwise a brand admin could open a dispute against another brand's
     *      transaction by guessing the ID).
     *   2. The transaction status must be 'completed' or 'refunded'. Opening a
     *      dispute against a 'pending' / 'processing' / 'cancelled' transaction
     *      is nonsensical - the funds never settled.
     *   3. The dispute amount must not exceed the transaction's captured amount
     *      (a partial dispute is legitimate; an over-dispute is not).
     *   4. No existing 'open' / 'under_review' dispute may exist for the same
     *      transaction - the admin must resolve or close the existing dispute
     *      first. Multiple concurrent disputes on one transaction confuse the
     *      ledger and double-count reserve holds.
     *
     * @param int $merchantId The unique ID of the merchant/brand owning the transaction.
     * @param int $transactionId The unique ID of the disputed transaction.
     * @param string $reason The reason or category of the dispute.
     * @param string $amount The disputed amount as a decimal string.
     * @return array<string, mixed> The newly created dispute database record.
     * @throws \InvalidArgumentException If the transaction does not exist, is not in a disputable status,
     *                                    the dispute amount exceeds the transaction amount, or an active
     *                                    dispute already exists for this transaction.
     * @throws \RuntimeException If the dispute record is not found in storage after being created.
     */
    public function open(int $merchantId, int $transactionId, string $reason, string $amount): array
    {
        // Guard 1: transaction must exist and belong to the merchant.
        $txn = $this->transactions->forTenant($merchantId)->findScoped($transactionId);
        if ($txn === null) {
            throw new \InvalidArgumentException(
                "Transaction #{$transactionId} not found or does not belong to merchant #{$merchantId}."
            );
        }

        // Guard 2: transaction status must be 'completed' or 'refunded'.
        $txnStatusVal = $txn['status'] ?? '';
        $txnStatus = is_scalar($txnStatusVal) ? (string) $txnStatusVal : '';
        if (!in_array($txnStatus, ['completed', 'refunded'], true)) {
            throw new \InvalidArgumentException(
                "Cannot open a dispute against transaction #{$transactionId}: "
                . "status '{$txnStatus}' is not disputable (must be 'completed' or 'refunded')."
            );
        }

        // Guard 3: dispute amount must not exceed the transaction amount.
        $txnAmountVal = $txn['amount'] ?? '0.00';
        $txnAmount = is_scalar($txnAmountVal) ? (string) $txnAmountVal : '0.00';
        // Defensive: if either value is non-numeric the comparison is meaningless;
        // treat that as a malformed request and reject before reaching bccomp().
        if (!is_numeric($amount) || !is_numeric($txnAmount)) {
            throw new \InvalidArgumentException(
                "Dispute amount {$amount} or transaction #{$transactionId} amount {$txnAmount} is non-numeric."
            );
        }
        /** @var numeric-string $amount */
        /** @var numeric-string $txnAmount */
        if (bccomp($amount, $txnAmount, 2) > 0) {
            throw new \InvalidArgumentException(
                "Dispute amount {$amount} exceeds transaction #{$transactionId} captured amount {$txnAmount}."
            );
        }

        // Guard 4: no existing open / under_review dispute may exist for this transaction.
        $existing = $this->disputes->forTenant($merchantId)->findByTransactionId($transactionId);
        if ($existing !== null) {
            $existingIdVal = $existing['id'] ?? 0;
            $existingId = is_scalar($existingIdVal) ? (int) $existingIdVal : 0;
            throw new \InvalidArgumentException(
                "An active dispute (#{$existingId}) already exists for transaction #{$transactionId}. "
                . "Resolve or close it before opening a new dispute."
            );
        }

        $repo = $this->disputes->forTenant($merchantId);
        $id = $repo->createScoped([
            'transaction_id' => $transactionId,
            'reason'         => $reason,
            'amount'         => $amount,
            'status'         => 'open',
        ]);

        $dispute = $repo->findScoped((int) $id);
        if ($dispute === null) {
            throw new \RuntimeException("Dispute #{$id} not found after creation");
        }
        $this->events->doAction('dispute.opened', $dispute);
        return $dispute;
    }

    /**
     * Resolves an active dispute by recording a status and resolution evidence.
     *
     * Updates the dispute status and fires the `dispute.resolved` action hook.
     *
     * @param int $merchantId The unique ID of the merchant/brand owning the dispute.
     * @param int $disputeId The unique ID of the dispute to resolve.
     * @param string $status The final dispute status ('won', 'lost', 'closed').
     * @param string|null $resolution Optional description of the resolution details.
     * @return void
     * @throws \RuntimeException If the dispute is already resolved (no rows affected by the
     *                            state-machine-guarded UPDATE). The controller surfaces this
     *                            as a user-facing flash error.
     */
    public function resolve(int $merchantId, int $disputeId, string $status, ?string $resolution = null): void
    {
        $affected = $this->disputes->forTenant($merchantId)->resolve($disputeId, $status, $resolution);
        if ($affected === 0) {
            // The UPDATE WHERE clause restricted to status IN ('open','under_review')
            // matched no rows - i.e. the dispute is already resolved (won/lost/closed).
            // Refuse to overwrite the existing resolution / re-fire dispute.resolved.
            throw new \RuntimeException(
                "Dispute #{$disputeId} is already resolved and cannot be re-resolved."
            );
        }
        $this->events->doAction('dispute.resolved', ['id' => $disputeId, 'status' => $status, 'resolution' => $resolution]);
    }
}
