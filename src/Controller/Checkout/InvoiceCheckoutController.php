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

/**
 * Controller handling checkout routing for white-labeled brand invoices.
 *
 * This controller processes public checkout links for invoices, resolves the invoice
 * context from the unique token parameter, checks validation rules (including overdue statuses),
 * handles existing or new transaction mapping, and routes the user to the transaction room.
 */
final class InvoiceCheckoutController
{
    /**
     * @var \OwnPay\Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var \OwnPay\Event\EventManager The event manager instance.
     */
    private EventManager $events;

    /**
     * @var \OwnPay\Repository\InvoiceRepository The invoice repository.
     */
    private InvoiceRepository $invoiceRepo;

    /**
     * @var \OwnPay\Repository\TransactionRepository The transaction repository.
     */
    private TransactionRepository $txnRepo;

    /**
     * Initializes the controller with necessary system dependencies.
     *
     * @param \OwnPay\Container $c The dependency injection container.
     * @param \OwnPay\Event\EventManager $events The global event manager.
     * @param \OwnPay\Repository\InvoiceRepository $invoiceRepo Repository for invoice DB access.
     * @param \OwnPay\Repository\TransactionRepository $txnRepo Repository for transaction DB access.
     */
    public function __construct(Container $c, EventManager $events, InvoiceRepository $invoiceRepo, TransactionRepository $txnRepo)
    {
        $this->c           = $c;
        $this->events      = $events;
        $this->invoiceRepo = $invoiceRepo;
        $this->txnRepo     = $txnRepo;
    }

    /**
     * Resolves an invoice from token and redirects user to the transaction checkout session.
     *
     * @param \OwnPay\Http\Request $req The incoming HTTP request.
     * @return \OwnPay\Http\Response The HTTP response (redirect or rendering status).
     * @throws \Exception If transaction token creation fails.
     */
    public function show(Request $req): Response
    {
        $token = (string) $req->param('token');
        
        // Resolve the invoice using its globally unique token to prevent cross-tenant parameter leakage.
        $invoice = $this->invoiceRepo->findByToken($token);

        // Apply a whitelist verification checks on status to only permit payable invoices.
        $allowedStatuses = ['sent', 'overdue'];

        // Initialize Twig template engine environment prior to processing render loops.
        $twig = $this->c->get(\Twig\Environment::class);

        // If no active invoice record exists, return an expired/unavailable response page.
        if (!$invoice) {
            return $this->renderExpired($twig);
        }

        if (!in_array($invoice['status'], $allowedStatuses, true)) {
            // Direct non-payable invoices to the expired status view with appropriate context labeling.
            $statusLabels = [
                'draft' => 'Invoice Not Ready',
                'paid'  => 'Invoice Already Paid',
                'void'  => 'Invoice Voided',
            ];
            $label = $statusLabels[$invoice['status']] ?? 'Invoice Unavailable';
            return $this->renderExpired($twig, $label);
        }

        // Assess invoice deadline: automatically transition 'sent' invoices to 'overdue' if the due date has elapsed.
        if (!empty($invoice['due_date'])) {
            $dueDate = strtotime($invoice['due_date']);
            if ($dueDate !== false && $dueDate < strtotime('today')) {
                // Update database status of the invoice context if currently marked as sent.
                if ($invoice['status'] === 'sent') {
                    $this->invoiceRepo->forTenant((int) $invoice['merchant_id'])
                        ->updateScoped((int) $invoice['id'], ['status' => 'overdue']);
                    $invoice['status'] = 'overdue';
                }
            }
        }

        // Retrieve any existing pending transaction session linked to this invoice to prevent double-billing.
        $existingTxn = $this->invoiceRepo->findPendingTransaction((int) $invoice['id']);
        if ($existingTxn) {
            return Response::redirect("/checkout/{$existingTxn['trx_id']}");
        }

        // Create new transaction with ALL required NOT NULL fields
        $trxId = $this->txnRepo->generateTrxId();
        $total = (string) ($invoice['total'] ?? '0');
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
     * Renders the expired/unavailable invoice error page.
     *
     * @param \Twig\Environment $twig The Twig template engine.
     * @param string $label The message label to show.
     * @return \OwnPay\Http\Response The HTML response.
     */
    private function renderExpired(\Twig\Environment $twig, string $label = 'Invoice Expired'): Response
    {
        $tpl = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
        return Response::html($twig->render($tpl, [
            'status'       => 'expired',
            'status_label' => $label,
            'txn'          => [],
            'brand'        => ['name' => 'Own Pay', 'logo' => '', 'color' => '#0D9488', 'support_email' => ''],
            'lang'         => [],
        ]));
    }
}
