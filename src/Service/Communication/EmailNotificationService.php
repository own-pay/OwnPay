<?php
declare(strict_types=1);

namespace OwnPay\Service\Communication;

use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\System\Logger;
use OwnPay\View\FragmentRenderer;

/**
 * Sends transactional admin-notification emails in reaction to payment lifecycle events.
 *
 * Listens to 'payment.transaction.completed' and 'refund.created'. For each, it honours the
 * per-brand notification preferences (brand override → All-Brands fallback → default) resolved
 * through {@see SettingsRepository::getScoped}: the on/off toggle, the recipient address and the
 * sender identity all cascade from the brand to the platform.
 *
 * Email dispatch is wrapped so that a failure can NEVER disrupt the payment-completion path:
 * every handler is fully guarded and only logs on error. (The EventManager already isolates
 * listener exceptions; this is defence-in-depth and keeps a sibling listener running.)
 *
 * @package OwnPay\Service\Communication
 */
final class EmailNotificationService
{
    /**
     * @param CommunicationService $comm Unified message dispatcher (resolves the mail channel + from identity).
     * @param SettingsRepository $settings Brand→global→default settings resolver.
     * @param FragmentRenderer $renderer Twig renderer used to compile the HTML email body.
     * @param Logger $logger System logger for non-fatal email failures.
     */
    public function __construct(
        private readonly CommunicationService $comm,
        private readonly SettingsRepository $settings,
        private readonly FragmentRenderer $renderer,
        private readonly Logger $logger
    ) {
    }

    /**
     * Handles a completed payment by emailing the brand's notification address.
     *
     * @param array<string, mixed> $transaction The completed transaction record (op_transactions row).
     * @return void
     */
    public function onTransactionCompleted(array $transaction): void
    {
        try {
            $merchantId = $this->intVal($transaction['merchant_id'] ?? null);
            if ($merchantId <= 0 || !$this->prefEnabled('email_on_payment', $merchantId)) {
                return;
            }

            $recipient = $this->recipient($merchantId);
            if ($recipient === '') {
                $this->logger->warning(
                    'Payment email skipped: no admin_notification_email configured.',
                    ['merchant_id' => $merchantId]
                );
                return;
            }

            $trxId    = $this->strVal($transaction['trx_id'] ?? '');
            $amount   = $this->strVal($transaction['amount'] ?? '0.00');
            $currency = $this->strVal($transaction['currency'] ?? 'BDT');
            $gateway  = $this->strVal($transaction['gateway_slug'] ?? '');
            $created  = $this->strVal($transaction['created_at'] ?? '');

            $html = $this->renderer->render('email/payment_received.twig', [
                'trx_id'     => $trxId,
                'amount'     => $amount,
                'currency'   => $currency,
                'gateway'    => $gateway,
                'created_at' => $created,
            ]);

            $this->comm->sendEmail($merchantId, [
                'to'      => $recipient,
                'subject' => sprintf('Payment received: %s %s', $currency, $amount),
                'body'    => sprintf('Payment received. Transaction %s: %s %s.', $trxId, $currency, $amount),
                'html'    => $html,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Payment notification email failed: ' . $e->getMessage());
        }
    }

    /**
     * Handles a created (successful) refund by emailing the brand's notification address.
     *
     * @param array<string, mixed> $refund The finalised refund record (op_refunds row).
     * @return void
     */
    public function onRefundCreated(array $refund): void
    {
        try {
            $merchantId = $this->intVal($refund['merchant_id'] ?? null);
            if ($merchantId <= 0 || !$this->prefEnabled('email_on_refund', $merchantId)) {
                return;
            }

            $recipient = $this->recipient($merchantId);
            if ($recipient === '') {
                $this->logger->warning(
                    'Refund email skipped: no admin_notification_email configured.',
                    ['merchant_id' => $merchantId]
                );
                return;
            }

            $refundId = $this->strVal($refund['id'] ?? '');
            $txnId    = $this->strVal($refund['transaction_id'] ?? '');
            $amount   = $this->strVal($refund['amount'] ?? '0.00');
            $reason   = $this->strVal($refund['reason'] ?? '');
            $created  = $this->strVal($refund['created_at'] ?? '');

            $html = $this->renderer->render('email/refund_processed.twig', [
                'refund_id'      => $refundId,
                'transaction_id' => $txnId,
                'amount'         => $amount,
                'reason'         => $reason,
                'created_at'     => $created,
            ]);

            $this->comm->sendEmail($merchantId, [
                'to'      => $recipient,
                'subject' => sprintf('Refund processed: %s', $amount),
                'body'    => sprintf('Refund processed for transaction %s: %s.', $txnId, $amount),
                'html'    => $html,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Refund notification email failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolves whether a boolean notification preference is enabled for the brand.
     *
     * @param string $key The settings key ('email_on_payment' or 'email_on_refund').
     * @param int $merchantId The brand/merchant identifier.
     * @return bool True when the preference resolves to an enabled value.
     */
    private function prefEnabled(string $key, int $merchantId): bool
    {
        $value = $this->settings->getScoped('general', $key, $merchantId, '0');
        return $value === '1' || $value === 'true' || $value === 'on';
    }

    /**
     * Resolves the brand's notification recipient address (brand override → global fallback).
     *
     * @param int $merchantId The brand/merchant identifier.
     * @return string The trimmed recipient address, or '' when none is configured.
     */
    private function recipient(int $merchantId): string
    {
        return trim((string) $this->settings->getScoped('general', 'admin_notification_email', $merchantId, ''));
    }

    /**
     * Casts a loosely-typed value to int, defaulting to 0 for non-scalars.
     *
     * @param mixed $value The raw value.
     * @return int The integer representation.
     */
    private function intVal(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }

    /**
     * Casts a loosely-typed value to string, defaulting to '' for non-scalars.
     *
     * @param mixed $value The raw value.
     * @return string The string representation.
     */
    private function strVal(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
