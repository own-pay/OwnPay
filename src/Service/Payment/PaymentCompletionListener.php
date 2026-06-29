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
            $scopedLinks = $this->linkRepo->forTenant($merchantId);
            $scopedLinks->incrementUseCount($linkId);
            $link = $scopedLinks->findScoped($linkId);
            if ($link) {
                $maxUsesVal = $link['max_uses'] ?? 0;
                $useCountVal = $link['use_count'] ?? 0;
                $maxUses = is_scalar($maxUsesVal) ? (int) $maxUsesVal : 0;
                $useCount = is_scalar($useCountVal) ? (int) $useCountVal : 0;
                if ($maxUses > 0 && $useCount >= $maxUses) {
                    $scopedLinks->updateScoped($linkId, ['status' => 'inactive']);
                }
            }
        }
    }
}
