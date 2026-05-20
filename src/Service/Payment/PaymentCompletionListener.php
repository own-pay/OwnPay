<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\InvoiceRepository;
use OwnPay\Repository\PaymentLinkRepository;
use OwnPay\Support\DateHelper;

/**
 * CHK-003 + CHK-006: Listens for payment.transaction.completed hook.
 * - Marks associated invoice as 'paid'
 * - Increments payment link use_count and deactivates if max_uses reached
 */
final class PaymentCompletionListener
{
    private InvoiceRepository $invoiceRepo;
    private PaymentLinkRepository $linkRepo;

    public function __construct(InvoiceRepository $invoiceRepo, PaymentLinkRepository $linkRepo)
    {
        $this->invoiceRepo = $invoiceRepo;
        $this->linkRepo = $linkRepo;
    }

    /**
     * Handle transaction completion — update invoice status + payment link use_count.
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
