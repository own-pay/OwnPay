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
        $mid = 0;
        $isGlobal = false;
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            $isGlobal = $brand->isGlobalView();
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }
        $pageVal = $req->query('page', '1');
        $page = max(1, is_scalar($pageVal) && is_numeric($pageVal) ? (int) $pageVal : 1);

        $invoices = $this->invoices->listForMerchant($isGlobal ? null : $mid, $page);

        // Decrypt customer names for display
        $enc = $this->c->get(FieldEncryptor::class);
        if ($enc instanceof FieldEncryptor) {
            foreach ($invoices as &$inv) {
                $inv['customer_name'] = !empty($inv['customer_name_enc']) && is_string($inv['customer_name_enc'])
                    ? $enc->decrypt($inv['customer_name_enc'])
                    : '-';
            }
            unset($inv);
        }

        $pagination = $this->invoices->pagination($isGlobal ? null : $mid, $page);

        /** @var \OwnPay\Service\Domain\DomainUrlService $urlService */
        $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
        $baseUrl = $urlService->resolveBaseUrl($mid, $req);

        return $this->renderAdminPage('admin/invoices/index.twig', [
            'invoices'    => $invoices,
            'pagination'  => $pagination,
            'base_url'    => $baseUrl,
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
        $mid = 0;
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }

        if ($req->method() === 'GET') {
            return $this->renderAdminPage('admin/invoices/edit.twig', [
                'invoice'     => [],
                'customers'   => $this->getCustomers($mid),
                'currencies'  => $this->getCurrencies(),
                'active_page' => 'invoices',
            ]);
        }

        $data = $req->post();
        /** @var array{invoice_number?: string, customer_id?: int|string, due_date?: string|null, notes?: string|null, currency?: string, tax?: float|int|string, discount?: float|int|string, items?: array<int, array{description?: string, quantity?: int|string, unit_price?: float|int|string, amount?: float|int|string}>} $postData */
        $postData = is_array($data) ? $data : [];
        if ($guard = $this->requireActiveBrand($mid, '/admin/invoices')) {
            return $guard;
        }
        $invoice = $this->invoices->create($mid, $postData);
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
        $mid = 0;
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }
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

        $data = $req->post();
        /** @var array{customer_id?: int|string, due_date?: string|null, notes?: string|null, currency?: string, tax?: float|int|string, discount?: float|int|string, status?: string, items?: array<int, array{description?: string, quantity?: int|string, unit_price?: float|int|string, amount?: float|int|string}>} $postData */
        $postData = is_array($data) ? $data : [];
        $updated = $this->invoices->update($mid, $id, $postData);
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
     * Renders a print-friendly invoice document for the browser.
     *
     * InvoiceService::generatePdf() returns a self-contained, print-ready HTML
     * document (PdfService wraps it with @page/A4 print styles). It is served
     * inline so the administrator can review it and use the browser's
     * Print → "Save as PDF" to export - there is no binary-PDF engine bundled.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The printable invoice HTML, or a redirect if not found.
     */
    public function pdf(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $mid = 0;
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }
        $id      = (int) $req->param('id');
        $content = $this->invoices->generatePdf($mid, $id);

        if ($content === '') {
            $this->session->flashError('Invoice not found');
            return Response::redirect('/admin/invoices');
        }

        return Response::html($content);
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
        $customerRepo = $this->c->get(\OwnPay\Repository\CustomerRepository::class);
        $enc  = $this->c->get(FieldEncryptor::class);
        $result = [];
        if ($customerRepo instanceof \OwnPay\Repository\CustomerRepository && $enc instanceof FieldEncryptor) {
            $repo = $customerRepo->forTenant($mid);
            $paginateResult = $repo->paginateScoped(1, 1000);
            $customers = is_array($paginateResult['items'] ?? null) ? $paginateResult['items'] : [];
            foreach ($customers as $c) {
                if (is_array($c)) {
                    $name = !empty($c['name_enc']) && is_string($c['name_enc']) ? $enc->decrypt($c['name_enc']) : (is_string($c['name'] ?? null) ? $c['name'] : 'Unknown');
                    $result[] = ['id' => isset($c['id']) && is_scalar($c['id']) && is_numeric($c['id']) ? (int) $c['id'] : 0, 'name' => $name];
                }
            }
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
        $currencyService = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
        if ($currencyService instanceof \OwnPay\Service\Payment\CurrencyService) {
            return $currencyService->listAll();
        }
        return [];
    }
}
