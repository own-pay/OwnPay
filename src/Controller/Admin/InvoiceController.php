<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\InvoiceService;
use OwnPay\Event\EventManager;
use OwnPay\Security\FieldEncryptor;

/**
 * Class InvoiceController
 *
 * Manages administrative lifecycle of brand invoices, including listing, creation,
 * modification, PDF generation, and event notifications.
 *
 * Fired actions:
 * - `invoice.created`: Invoked immediately after successfully saving a new invoice.
 * - `invoice.updated`: Invoked immediately after updating an existing invoice.
 *
 * @package OwnPay\Controller\Admin
 */
final class InvoiceController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session service.
     */
    private AdminSession $session;

    /**
     * @var InvoiceService The invoice management service.
     */
    private InvoiceService $invoices;

    /**
     * @var EventManager The hooks and actions event manager.
     */
    private EventManager $events;

    /**
     * InvoiceController constructor.
     *
     * @param Container      $c        The dependency injection container.
     * @param AdminSession   $session  The administrative session service.
     * @param InvoiceService $invoices The invoice management service.
     * @param EventManager   $events   The hooks and actions event manager.
     */
    public function __construct(Container $c, AdminSession $session, InvoiceService $invoices, EventManager $events)
    {
        $this->c        = $c;
        $this->session  = $session;
        $this->invoices = $invoices;
        $this->events   = $events;
    }

    /**
     * Renders the paginated invoice dashboard with customer names decrypted for display.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The invoice list response.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid  = $brand->getActiveBrandId();
        $page = max(1, (int) $req->query('page', '1'));

        $invoices = $this->invoices->listForMerchant($mid, $page);

        // Decrypt customer names for display
        $enc = $this->c->get(FieldEncryptor::class);
        foreach ($invoices as &$inv) {
            $inv['customer_name'] = !empty($inv['customer_name_enc'])
                ? $enc->decrypt($inv['customer_name_enc'])
                : '—';
        }
        unset($inv);

        $pagination = $this->invoices->pagination($mid, $page);

        return $this->renderAdminPage('admin/invoices/index.twig', [
            'invoices'    => $invoices,
            'pagination'  => $pagination,
            'active_page' => 'invoices',
        ]);
    }

    /**
     * Handles displaying the invoice creation form or processing the creation request.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The form template or HTTP redirect response.
     */
    public function create(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        if ($req->method() === 'GET') {
            return $this->renderAdminPage('admin/invoices/edit.twig', [
                'invoice'     => [],
                'customers'   => $this->getCustomers($mid),
                'currencies'  => $this->getCurrencies(),
                'active_page' => 'invoices',
            ]);
        }

        $data    = $req->post();
        $invoice = $this->invoices->create($mid, $data);
        $this->events->doAction('invoice.created', $invoice);

        $this->session->flashSuccess('Invoice created');
        return Response::redirect('/admin/invoices');
    }

    /**
     * Handles displaying the invoice modification form or processing the update request.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The form template or HTTP redirect response.
     */
    public function edit(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid     = $brand->getActiveBrandId();
        $id      = (int) $req->param('id');
        $invoice = $this->invoices->find($mid, $id);

        if ($invoice === null) {
            $this->session->flashError('Invoice not found');
            return Response::redirect('/admin/invoices');
        }

        if ($req->method() === 'GET') {
            return $this->renderAdminPage('admin/invoices/edit.twig', [
                'invoice'     => $invoice,
                'customers'   => $this->getCustomers($mid),
                'currencies'  => $this->getCurrencies(),
                'active_page' => 'invoices',
            ]);
        }

        $data    = $req->post();
        $updated = $this->invoices->update($mid, $id, $data);
        $this->events->doAction('invoice.updated', $updated);

        $this->session->flashSuccess('Invoice updated');
        return Response::redirect('/admin/invoices');
    }

    /**
     * Endpoint alias handler for storing a new invoice via POST request.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function store(Request $req): Response
    {
        return $this->create($req);
    }

    /**
     * Endpoint alias handler for displaying the invoice modification interface.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The form page or redirect response.
     */
    public function show(Request $req): Response
    {
        return $this->edit($req);
    }

    /**
     * Endpoint alias handler for processing update submissions.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function update(Request $req): Response
    {
        return $this->edit($req);
    }

    /**
     * Generates a dynamic PDF of the target invoice and initiates a file download.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The PDF file download response.
     */
    public function pdf(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid        = $brand->getActiveBrandId();
        $id         = (int) $req->param('id');
        $pdfContent = $this->invoices->generatePdf($mid, $id);
        return Response::download($pdfContent, "invoice-{$id}.pdf", 'application/pdf');
    }

    /**
     * Retrieves brand-scoped customers list and decrypts names for use in dropdown selections.
     *
     * @param int $mid Active merchant (brand) ID.
     *
     * @return array<int, array{id: int, name: string}> Decrypted list of customers.
     */
    private function getCustomers(int $mid): array
    {
        $repo = $this->c->get(\OwnPay\Repository\CustomerRepository::class)->forTenant($mid);
        $enc  = $this->c->get(FieldEncryptor::class);
        $customers = $repo->paginateScoped(1, 1000)['items'];
        $result = [];
        foreach ($customers as $c) {
            $name = !empty($c['name_enc']) ? $enc->decrypt($c['name_enc']) : ($c['name'] ?? 'Unknown');
            $result[] = ['id' => $c['id'], 'name' => $name];
        }
        return $result;
    }

    /**
     * Fetches all registered system currencies.
     *
     * @return array<int, array<string, mixed>> Currencies list.
     */
    private function getCurrencies(): array
    {
        return $this->c->get(\OwnPay\Service\Payment\CurrencyService::class)->listAll();
    }
}
