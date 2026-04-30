<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\DisputeRepository;

/**
 * Dispute service — chargeback/dispute management.
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
        $id = $this->disputes->create([
            'merchant_id'    => $merchantId,
            'transaction_id' => $transactionId,
            'reason'         => $reason,
            'amount'         => $amount,
            'status'         => 'open',
        ]);

        $dispute = $this->disputes->find((int) $id);
        return $dispute;
    }

    public function resolve(int $disputeId, string $resolution): void
    {
        $this->disputes->update($disputeId, [
            'status'      => 'resolved',
            'resolution'  => $resolution,
            'resolved_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
