<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;
use OwnPay\Repository\InvoiceRepository;
use OwnPay\Repository\TransactionRepository;
use Ramsey\Uuid\Uuid;

final class InvoiceCheckoutController
{
    private Container $c;
    private EventManager $events;
    private InvoiceRepository $invoiceRepo;
    private TransactionRepository $txnRepo;

    public function __construct(Container $c, EventManager $events, InvoiceRepository $invoiceRepo, TransactionRepository $txnRepo)
    {
        $this->c           = $c;
        $this->events      = $events;
        $this->invoiceRepo = $invoiceRepo;
        $this->txnRepo     = $txnRepo;
    }

    public function show(Request $req): Response
    {
        $invoiceNumber = (string) $req->param('token');
        $invoice = $this->invoiceRepo->findUnpaidByNumber($invoiceNumber);
        $twig = $this->c->get(\Twig\Environment::class);

        if (!$invoice) {
            // M-01 FIX: Pass brand/status_label to status page
            return $this->renderExpired($twig);
        }

        // C-02 FIX: Reuse existing pending transaction (query by metadata JSON)
        $existingTxn = $this->invoiceRepo->findPendingTransaction((int) $invoice['id']);
        if ($existingTxn) {
            return Response::redirect("/checkout/{$existingTxn['trx_id']}");
        }

        // Create new transaction with ALL required NOT NULL fields
        $trxId = 'TXN-' . strtoupper(bin2hex(random_bytes(8)));
        $total = (float) $invoice['total'];
        $this->txnRepo->create([
            'uuid'         => Uuid::uuid4()->toString(),
            'trx_id'       => $trxId,
            'merchant_id'  => $invoice['merchant_id'],
            'payment_intent_id' => null,
            'customer_id'  => $invoice['customer_id'] ?? null,
            'gateway_slug' => 'invoice',
            'amount'       => $total,
            'net_amount'   => $total,
            'currency'     => $invoice['currency'],
            'method'       => 'invoice',
            'status'       => 'pending',
            'metadata'     => json_encode(['invoice_id' => $invoice['id'], 'invoice_number' => $invoice['invoice_number']]),
        ]);

        return Response::redirect("/checkout/{$trxId}");
    }

    /**
     * M-01 FIX: Render expired status with proper brand data.
     */
    private function renderExpired(\Twig\Environment $twig): Response
    {
        $tpl = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
        return Response::html($twig->render($tpl, [
            'status'       => 'expired',
            'status_label' => 'Invoice Expired',
            'txn'          => [],
            'brand'        => ['name' => 'Own Pay', 'logo' => '', 'color' => '#0D9488', 'support_email' => ''],
            'lang'         => [],
        ]));
    }
}
