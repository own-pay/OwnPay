<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\DisputeRepository;

/**
 * Dispute service â€” chargeback/dispute management.
 */
final class DisputeService
{
    private DisputeRepository $disputes;
    private EventManager $events;

    public function __construct(DisputeRepository $disputes, EventManager $events)
    {
        $this->disputes = $disputes;
        $this->events = $events;
    }

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
        $this->events->doAction('dispute.opened', $dispute);
        return $dispute;
    }

    public function resolve(int $merchantId, int $disputeId, string $resolution): void
    {
        $this->disputes->forTenant($merchantId)->resolve($disputeId, 'resolved', $resolution);
        $this->events->doAction('dispute.resolved', ['id' => $disputeId, 'resolution' => $resolution]);
    }
}
