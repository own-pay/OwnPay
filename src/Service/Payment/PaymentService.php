<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\PaymentIntentRepository;
use Ramsey\Uuid\Uuid;
use OwnPay\Support\DateHelper;

/**
 * Service managing payment intents representing transaction requests.
 *
 * Creates payment intent templates, manages intent validation lifecycles (pending, expired, paid),
 * processes expiration sweep actions, and executes system hooks.
 */
final class PaymentService
{
    /**
     * @var PaymentIntentRepository Repository accessing payment intents.
     */
    private PaymentIntentRepository $intents;

    /**
     * @var EventManager Event dispatcher for system hooks.
     */
    private EventManager $events;

    /**
     * PaymentService constructor.
     *
     * @param PaymentIntentRepository $intents Repository for payment intent database actions.
     * @param EventManager $events Event dispatcher for system hooks.
     */
    public function __construct(PaymentIntentRepository $intents, EventManager $events)
    {
        $this->intents = $intents;
        $this->events = $events;
    }

    /**
     * Creates a new payment intent.
     *
     * Resolves the final processing amount by executing the `payment.amount.calculate` filter,
     * structures intent metadata, and fires the `payment.intent.created` event hook.
     *
     * @param int $merchantId The ID of the merchant/brand.
     * @param array{
     *     amount: string,
     *     currency: string,
     *     description?: string,
     *     redirect_url?: string,
     *     cancel_url?: string,
     *     webhook_url?: string,
     *     metadata?: array<string, mixed>
     * } $data Input parameters for the payment intent.
     * @return array<string, mixed> The newly created payment intent database record fields.
     */
    public function createIntent(int $merchantId, array $data): array
    {
        $amount = $this->events->applyFilter('payment.amount.calculate', $data['amount'], [
            'currency' => $data['currency'],
            'merchant_id' => $merchantId,
        ]);
        $data['amount'] = $amount;

        $amountStr = is_scalar($data['amount']) ? (string) $data['amount'] : '';
        if ($amountStr === '' || !is_numeric($amountStr) || bccomp($amountStr, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Payment intent amount must be a positive number.');
        }

        if ($data['metadata'] ?? null) {
            $data['metadata'] = json_encode($data['metadata']);
        }

        $repo = $this->intents->forTenant($merchantId);
        $id = $repo->createIntent($data);
        $intent = $repo->findScoped((int) $id);
        if ($intent === null) {
            throw new \RuntimeException('Failed to retrieve newly created payment intent.');
        }

        $this->events->doAction('payment.intent.created', $intent);

        return $intent;
    }

    /**
     * Finds a payment intent using its unique lookup token (used on the public checkout page).
     *
     * Verifies that the intent has not expired. If it is pending but past its expires_at timestamp,
     * marks it as expired and fires the `payment.intent.expired` hook.
     *
     * @param string $token The unique payment intent token.
     * @return array<string, mixed>|null The payment intent fields, or null if not found.
     */
    public function findByToken(string $token): ?array
    {
        $intent = $this->intents->findByToken($token);
        if ($intent === null) {
            return null;
        }

        $expiresAtVal = $intent['expires_at'] ?? '';
        $expiresAt = is_scalar($expiresAtVal) ? (string) $expiresAtVal : '';
        if ($intent['status'] === 'pending' && DateHelper::isPast($expiresAt)) {
            $midVal = $intent['merchant_id'] ?? 0;
            $idVal = $intent['id'] ?? 0;
            $mid = is_scalar($midVal) ? (int) $midVal : 0;
            $id = is_scalar($idVal) ? (int) $idVal : 0;
            $this->intents->forTenant($mid)
                ->updateScoped($id, ['status' => 'expired']);
            $intent['status'] = 'expired';
            $this->events->doAction('payment.intent.expired', $intent);
        }

        return $intent;
    }

    /**
     * Identifies and marks all stale pending payment intents as expired.
     *
     * Triggered by cron runner and fires the `payment.intent.expired` batch action.
     *
     * @return int The total number of expired intents.
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
     * Finds a payment intent using its unique UUID (payment_id).
     *
     * @param string $uuid The UUID of the payment intent.
     * @return array<string, mixed>|null The payment intent fields, or null if not found.
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->intents->findByUuid($uuid);
    }

    /**
     * Marks a specific payment intent as paid.
     *
     * @param int $intentId The unique ID of the payment intent.
     * @param int $merchantId The unique ID of the merchant/brand.
     * @return void
     */
    public function markPaid(int $intentId, int $merchantId): void
    {
        $this->intents->forTenant($merchantId)->updateScoped($intentId, ['status' => 'paid']);
    }
}
