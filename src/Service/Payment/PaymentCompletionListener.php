<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\InvoiceRepository;
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
     * PaymentCompletionListener constructor.
     *
     * @param InvoiceRepository $invoiceRepo Repository for invoice database actions.
     * @param PaymentLinkRepository $linkRepo Repository for payment link database actions.
     */
    public function __construct(InvoiceRepository $invoiceRepo, PaymentLinkRepository $linkRepo)
    {
        $this->invoiceRepo = $invoiceRepo;
        $this->linkRepo = $linkRepo;
    }

    /**
     * Responds to the transaction completion event.
     *
     * Extracts meta-parameters to identify linked invoices and payment links.
     * Marks invoices as paid and updates link usage constraints.
     *
     * @param array<string, mixed> $transaction The completed transaction database record fields.
     * @return void
     */
    public function onTransactionCompleted(array $transaction): void
    {
        $meta = json_decode($transaction['metadata'] ?? '{}', true);
        $merchantId = (int) ($transaction['merchant_id'] ?? 0);

        if ($merchantId <= 0) {
            return;
        }

        // CHK-003: Mark invoice as paid
        $invoiceId = $meta['invoice_id'] ?? null;
        if ($invoiceId !== null) {
            $this->invoiceRepo->forTenant($merchantId)->updateScoped((int) $invoiceId, [
                'status'  => 'paid',
                'paid_at' => DateHelper::nowMicro(),
            ]);
        }

        // CHK-006: Increment payment link use_count + auto-deactivate if max reached
        $linkId = $meta['payment_link_id'] ?? null;
        if ($linkId !== null) {
            $this->linkRepo->forTenant($merchantId);
            $this->linkRepo->incrementUseCount((int) $linkId);

            // Check max_uses
            $link = $this->linkRepo->findScoped((int) $linkId);
            if ($link && $link['max_uses'] > 0 && ($link['use_count'] ?? 0) >= $link['max_uses']) {
                $this->linkRepo->updateScoped((int) $linkId, ['status' => 'inactive']);
            }
        }
    }
}
