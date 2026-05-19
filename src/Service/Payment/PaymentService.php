<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\PaymentIntentRepository;
use Ramsey\Uuid\Uuid;
use OwnPay\Support\DateHelper;

/**
 * Payment service — creates and manages payment intents.
 *
 * Fires: payment.intent.created, payment.intent.expired, payment.amount.calculate
 */
final class PaymentService
{
    private PaymentIntentRepository $intents;
    private EventManager $events;

    public function __construct(PaymentIntentRepository $intents, EventManager $events)
    {
        $this->intents = $intents;
        $this->events = $events;
    }

    /**
     * Create payment intent.
     *
     * @param array{amount: string, currency: string, description?: string, redirect_url?: string, cancel_url?: string, webhook_url?: string, metadata?: array} $data
     */
    public function createIntent(int $merchantId, array $data): array
    {
        // Apply amount filter (plugins can modify)
        $amount = $this->events->applyFilter('payment.amount.calculate', $data['amount'], [
            'currency' => $data['currency'],
            'merchant_id' => $merchantId,
        ]);
        $data['amount'] = $amount;

        if ($data['metadata'] ?? null) {
            $data['metadata'] = json_encode($data['metadata']);
        }

        $repo = $this->intents->forTenant($merchantId);
        $id = $repo->createIntent($data);
        $intent = $repo->findScoped((int) $id);

        $this->events->doAction('payment.intent.created', $intent);

        return $intent;
    }

    /**
     * Find intent by token (public checkout page).
     */
    public function findByToken(string $token): ?array
    {
        $intent = $this->intents->findByToken($token);
        if ($intent === null) {
            return null;
        }

        // Check expiry
        if ($intent['status'] === 'pending' && DateHelper::isPast($intent['expires_at'])) {
            $this->intents->forTenant((int) $intent['merchant_id'])
                ->updateScoped((int) $intent['id'], ['status' => 'expired']);
            $intent['status'] = 'expired';
            $this->events->doAction('payment.intent.expired', $intent);
        }

        return $intent;
    }

    /**
     * Expire all stale intents (cron job).
     */
    public function expireStale(): int
    {
        $count = $this->intents->expireStale();
        if ($count > 0) {
            $this->events->doAction('payment.intent.expired', ['count' => $count, 'batch' => true]);
        }
        return $count;
    }

    /**
     * Mark intent as paid.
     */
    public function markPaid(int $intentId, int $merchantId): void
    {
        $this->intents->forTenant($merchantId)->updateScoped($intentId, ['status' => 'paid']);
    }
}
