<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

/**
 * Auto-dispatches merchant webhooks when a payment transaction completes.
 *
 * Previously, webhooks were only dispatched from manual admin paths (test
 * webhook, manual resend) and the retry cron for already-queued events; the
 * real payment-completion flow fired the `payment.transaction.completed` action
 * but no listener forwarded the event to merchants (issue #59).
 *
 * This listener is wired to `payment.transaction.completed` in
 * `config/services.php` and forwards the completed transaction payload to
 * {@see WebhookService::dispatch()} so merchants receive their events without
 * requiring a manual admin action. The dispatcher is idempotent per webhook
 * event row, so retries or replays of the completion action do not produce
 * duplicate deliveries to the same endpoint for the same event id.
 */
final class WebhookAutoDispatchListener
{
    /**
     * @var WebhookService Outbound webhook dispatch service.
     */
    private WebhookService $webhookService;

    /**
     * WebhookAutoDispatchListener constructor.
     *
     * @param WebhookService $webhookService Outbound webhook dispatch service.
     */
    public function __construct(WebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Forwards a completed transaction event to all configured merchant webhooks.
     *
     * The transaction row carries every field {@see WebhookService::dispatch()}
     * needs to build the merchant-facing payload (trx_id, gateway_trx_id,
     * amount, currency, fee, gateway_slug, customer_* fields, metadata,
     * payment_intent_id). We normalize a handful of keys to the names the
     * dispatcher expects so the payload is consistent regardless of which
     * completion path produced the row.
     *
     * @param array<string, mixed> $transaction The completed transaction record.
     * @return void
     */
    public function onTransactionCompleted(array $transaction): void
    {
        $merchantIdVal = $transaction['merchant_id'] ?? 0;
        $merchantId = is_scalar($merchantIdVal) ? (int) $merchantIdVal : 0;
        if ($merchantId <= 0) {
            return;
        }

        $customerNameVal  = $transaction['customer_name'] ?? '';
        $customerEmailVal = $transaction['customer_email'] ?? '';
        $customerPhoneVal = $transaction['customer_phone'] ?? '';

        $payload = [
            'transaction_id'     => $transaction['trx_id'] ?? '',
            'payment_intent_id'  => $transaction['payment_intent_id'] ?? null,
            'gateway_trx_id'     => $transaction['gateway_trx_id'] ?? '',
            'amount'             => $transaction['amount'] ?? '0.00',
            'currency'           => $transaction['currency'] ?? 'BDT',
            'fee'                => $transaction['fee'] ?? '0.00',
            'gateway'            => $transaction['gateway_slug'] ?? '',
            'gateway_type'       => 'unknown',
            'status'             => $transaction['status'] ?? 'completed',
            'customer_name'      => is_string($customerNameVal) ? $customerNameVal : '',
            'customer_email'     => is_string($customerEmailVal) ? $customerEmailVal : '',
            'customer_phone'     => is_string($customerPhoneVal) ? $customerPhoneVal : '',
            'metadata'           => $transaction['metadata'] ?? [],
        ];

        $this->webhookService->dispatch($merchantId, 'payment.completed', $payload);
    }
}
