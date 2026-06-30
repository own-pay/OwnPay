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
        if (!$twig instanceof \Twig\Environment) {
            throw new \RuntimeException("Twig Environment not found");
        }

        // If no active invoice record exists, return an expired/unavailable response page.
        if (!$invoice) {
            return $this->renderExpired($twig);
        }

        $merchantIdVal = $invoice['merchant_id'] ?? 0;
        $merchantId = (is_int($merchantIdVal) || is_string($merchantIdVal)) ? (int) $merchantIdVal : 0;
        $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
            $brandCtx->setActiveBrandId($merchantId);
        }

        $status = is_string($invoice['status'] ?? null) ? $invoice['status'] : '';

        if (!in_array($status, $allowedStatuses, true)) {
            // Direct non-payable invoices to the expired status view with appropriate context labeling.
            $statusLabels = [
                'draft' => 'Invoice Not Ready',
                'paid'  => 'Invoice Already Paid',
                'void'  => 'Invoice Voided',
            ];
            $label = $statusLabels[$status] ?? 'Invoice Unavailable';
            return $this->renderExpired($twig, $label);
        }

        // Assess invoice deadline: automatically transition 'sent' invoices to 'overdue' if the due date has elapsed.
        if (!empty($invoice['due_date'])) {
            $dueDateRaw = $invoice['due_date'];
            $dueDateStr = is_string($dueDateRaw) ? $dueDateRaw : '';
            $dueDate = strtotime($dueDateStr);
            if ($dueDate !== false && $dueDate < strtotime('today')) {
                // Update database status of the invoice context if currently marked as sent.
                if ($status === 'sent') {
                    $merchantIdVal = $invoice['merchant_id'] ?? 0;
                    $merchantId = (is_int($merchantIdVal) || is_string($merchantIdVal)) ? (int) $merchantIdVal : 0;
                    $invoiceIdVal = $invoice['id'] ?? 0;
                    $invoiceId = (is_int($invoiceIdVal) || is_string($invoiceIdVal)) ? (int) $invoiceIdVal : 0;

                    $this->invoiceRepo->forTenant($merchantId)
                        ->updateScoped($invoiceId, ['status' => 'overdue']);
                    $invoice['status'] = 'overdue';
                }
            }
        }

        // Retrieve any existing pending transaction session linked to this invoice to prevent double-billing.
        $invoiceIdVal = $invoice['id'] ?? 0;
        $invoiceId = (is_int($invoiceIdVal) || is_string($invoiceIdVal)) ? (int) $invoiceIdVal : 0;
        $existingTxn = $this->invoiceRepo->findPendingTransaction($invoiceId);
        if (is_array($existingTxn) && isset($existingTxn['trx_id']) && is_string($existingTxn['trx_id'])) {
            return Response::redirect("/checkout/{$existingTxn['trx_id']}");
        }

        // Create new transaction with ALL required NOT NULL fields
        $trxId = $this->txnRepo->generateTrxId();
        $totalVal = $invoice['total'] ?? '0';
        $total = is_string($totalVal) || is_int($totalVal) || is_float($totalVal) ? (string) $totalVal : '0';

        $merchantIdVal = $invoice['merchant_id'] ?? 0;
        $merchantId = (is_int($merchantIdVal) || is_string($merchantIdVal)) ? (int) $merchantIdVal : 0;

        $customerIdVal = $invoice['customer_id'] ?? null;
        $customerId = ($customerIdVal !== null && (is_int($customerIdVal) || is_string($customerIdVal))) ? (int) $customerIdVal : null;

        $currencyVal = $invoice['currency'] ?? 'BDT';
        $currency = is_string($currencyVal) ? $currencyVal : 'BDT';

        $invoiceNumVal = $invoice['invoice_number'] ?? '';
        $invoiceNum = is_string($invoiceNumVal) ? $invoiceNumVal : '';

        $this->txnRepo->create([
            'uuid'         => Uuid::uuid4()->toString(),
            'trx_id'       => $trxId,
            'merchant_id'  => $merchantId,
            'payment_intent_id' => null,
            'customer_id'  => $customerId,
            'gateway_slug' => 'invoice',
            'amount'       => $total,
            'net_amount'   => $total,
            'currency'     => $currency,
            'method'       => 'invoice',
            'status'       => 'pending',
            'metadata'     => json_encode(['invoice_id' => $invoiceId, 'invoice_number' => $invoiceNum]),
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
        $tplFilter = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
        $tpl = is_string($tplFilter) ? $tplFilter : 'checkout/checkout-status.twig';
        return Response::html($twig->render($tpl, [
            'status'       => 'expired',
            'status_label' => $label,
            'txn'          => [],
            'brand'        => ['name' => 'OwnPay', 'logo' => '', 'color' => '#0D9488', 'support_email' => ''],
            'lang'         => [],
        ]));
    }
}
