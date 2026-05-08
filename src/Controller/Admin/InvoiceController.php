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
 * Fires: invoice.created, invoice.updated
 */
final class InvoiceController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private InvoiceService $invoices;
    private EventManager $events;

    public function __construct(Container $c, AdminSession $session, InvoiceService $invoices, EventManager $events)
    {
        $this->c       = $c;
        $this->session = $session;
        $this->invoices = $invoices;
        $this->events  = $events;
    }

    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid  = $brand->getActiveBrandId();
        $page = max(1, (int) $req->get('page', '1'));

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

    /** POST /admin/invoices/store — alias for POST branch of create() */
    public function store(Request $req): Response
    {
        return $this->create($req);
    }

    /** GET /admin/invoices/{id} — show edit form for existing invoice */
    public function show(Request $req): Response
    {
        return $this->edit($req);
    }

    /** POST /admin/invoices/{id}/update — process edit form */
    public function update(Request $req): Response
    {
        return $this->edit($req);
    }

    public function pdf(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid        = $brand->getActiveBrandId();
        $id         = (int) $req->param('id');
        $pdfContent = $this->invoices->generatePdf($mid, $id);
        return Response::download($pdfContent, "invoice-{$id}.pdf", 'application/pdf');
    }

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

    private function getCurrencies(): array
    {
        return $this->c->get(\OwnPay\Service\Payment\CurrencyService::class)->listAll();
    }
}
