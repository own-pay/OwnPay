<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\DisputeRepository;

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
     * DisputeService constructor.
     *
     * @param DisputeRepository $disputes Repository for dispute database operations.
     * @param EventManager $events Event dispatcher for system hooks.
     */
    public function __construct(DisputeRepository $disputes, EventManager $events)
    {
        $this->disputes = $disputes;
        $this->events = $events;
    }

    /**
     * Opens a new dispute record for a transaction.
     *
     * Scopes the dispute creation to the designated merchant/brand and fires the
     * `dispute.opened` event hook upon successful creation.
     *
     * @param int $merchantId The unique ID of the merchant/brand owning the transaction.
     * @param int $transactionId The unique ID of the disputed transaction.
     * @param string $reason The reason or category of the dispute.
     * @param string $amount The disputed amount as a decimal string.
     * @return array<string, mixed> The newly created dispute database record.
     * @throws \RuntimeException If the dispute record is not found in storage after being created.
     */
    public function open(int $merchantId, int $transactionId, string $reason, string $amount): array
    {
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
     */
    public function resolve(int $merchantId, int $disputeId, string $status, ?string $resolution = null): void
    {
        $this->disputes->forTenant($merchantId)->resolve($disputeId, $status, $resolution);
        $this->events->doAction('dispute.resolved', ['id' => $disputeId, 'status' => $status, 'resolution' => $resolution]);
    }
}
