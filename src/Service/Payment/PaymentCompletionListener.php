<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\InvoiceRepository;
use OwnPay\Repository\PaymentIntentRepository;
use OwnPay\Repository\PaymentLinkRepository;
use OwnPay\Support\DateHelper;

/**
 * Listens for payment transaction completion hooks.
 *
 * Implements transaction lifecycle reactions by updating associated invoice states
 * to 'paid' and checking usage limit rules on payment link instances (incrementing counters
 * and auto-deactivating when max use thresholds are crossed).
 */
final class PaymentCompletionListener
{
    /**
     * @var InvoiceRepository Repository accessing invoices.
     */
    private InvoiceRepository $invoiceRepo;

    /**
     * @var PaymentLinkRepository Repository accessing payment links.
     */
    private PaymentLinkRepository $linkRepo;

    /**
     * @var PaymentIntentRepository Repository accessing payment intents.
     */
    private PaymentIntentRepository $intentRepo;

    /**
     * PaymentCompletionListener constructor.
     *
     * @param InvoiceRepository $invoiceRepo Repository for invoice database actions.
     * @param PaymentLinkRepository $linkRepo Repository for payment link database actions.
     * @param PaymentIntentRepository $intentRepo Repository for payment intent database actions.
     */
    public function __construct(
        InvoiceRepository $invoiceRepo,
        PaymentLinkRepository $linkRepo,
        PaymentIntentRepository $intentRepo
    ) {
        $this->invoiceRepo = $invoiceRepo;
        $this->linkRepo = $linkRepo;
        $this->intentRepo = $intentRepo;
    }

    /**
     * Responds to the transaction completion event.
     *
     * Extracts meta-parameters to identify linked invoices, payment links, and payment intents.
     * Marks invoices as paid, updates link usage constraints, and marks payment intents as completed.
     *
     * @param array<string, mixed> $transaction The completed transaction database record fields.
     * @return void
     */
    public function onTransactionCompleted(array $transaction): void
    {
        $metadataVal = $transaction['metadata'] ?? '{}';
        $meta = json_decode(is_string($metadataVal) ? $metadataVal : '{}', true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $midVal = $transaction['merchant_id'] ?? 0;
        $merchantId = is_scalar($midVal) ? (int) $midVal : 0;

        if ($merchantId <= 0) {
            return;
        }

        $invoiceIdVal = $meta['invoice_id'] ?? null;
        $invoiceId = is_scalar($invoiceIdVal) ? (int) $invoiceIdVal : null;
        if ($invoiceId !== null) {
            $this->invoiceRepo->forTenant($merchantId)->updateScoped($invoiceId, [
                'status'  => 'paid',
                'paid_at' => DateHelper::nowMicro(),
            ]);
        }

        $linkIdVal = $meta['payment_link_id'] ?? null;
        $linkId = is_scalar($linkIdVal) ? (int) $linkIdVal : null;
        if ($linkId !== null) {
            // PAY-13: increment use_count and flip status to 'inactive' atomically. The
            // previous sequence (incrementUseCount -> findScoped -> check >= maxUses ->
            // updateScoped) was non-atomic: two concurrent completions of the same link
            // could both increment, both observe a sub-threshold use_count, and both
            // leave the link active, allowing use_count to overshoot max_uses. The atomic
            // version returns 0 when the link is already exhausted (no row updated); the
            // transaction itself has already been captured at the gateway at this point,
            // so we cannot refund here - but at least the in-platform state stays correct.
            $scopedLinks = $this->linkRepo->forTenant($merchantId);
            $scopedLinks->incrementUseCountAtomic($linkId);
        }

        $intentIdVal = $transaction['payment_intent_id'] ?? $meta['payment_intent_id'] ?? null;
        $intentId = is_scalar($intentIdVal) ? (int) $intentIdVal : null;
        if ($intentId !== null && $intentId > 0) {
            $this->intentRepo->forTenant($merchantId)->updateScoped($intentId, [
                'status' => 'completed',
            ]);
        }
    }
}
