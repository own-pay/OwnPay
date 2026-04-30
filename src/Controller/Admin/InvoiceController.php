<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\InvoiceService;
use OwnPay\Event\EventManager;

/**
 * Fires: invoice.created, invoice.updated
 */
final class InvoiceController
{
    private Container $c;
    private InvoiceService $invoices;
    private EventManager $events;

    public function __construct(Container $c, InvoiceService $invoices, EventManager $events)
    {
        $this->c = $c;
        $this->invoices = $invoices;
        $this->events = $events;
    }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $page = max(1, (int) $req->get('page', '1'));
        $invoices = $this->invoices->listForMerchant($mid, $page);
        $pagination = $this->invoices->pagination($mid, $page);

        return $this->render('admin/invoices/index.twig', [
            'invoices'    => $invoices,
            'pagination'  => $pagination,
            'active_page' => 'invoices',
        ]);
    }

    public function create(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');

        if ($req->method() === 'GET') {
            return $this->render('admin/invoices/edit.twig', [
                'invoice'    => [],
                'customers'  => $this->getCustomers($mid),
                'currencies' => $this->getCurrencies(),
                'active_page' => 'invoices',
            ]);
        }

        $data = $req->post();
        $invoice = $this->invoices->create($mid, $data);
        $this->events->doAction('invoice.created', $invoice);

        $_SESSION['flash_success'] = 'Invoice created';
        return Response::redirect('/admin/invoices');
    }

    public function edit(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $invoice = $this->invoices->find($mid, $id);

        if ($invoice === null) {
            $_SESSION['flash_error'] = 'Invoice not found';
            return Response::redirect('/admin/invoices');
        }

        if ($req->method() === 'GET') {
            return $this->render('admin/invoices/edit.twig', [
                'invoice'    => $invoice,
                'customers'  => $this->getCustomers($mid),
                'currencies' => $this->getCurrencies(),
                'active_page' => 'invoices',
            ]);
        }

        $data = $req->post();
        $updated = $this->invoices->update($mid, $id, $data);
        $this->events->doAction('invoice.updated', $updated);

        $_SESSION['flash_success'] = 'Invoice updated';
        return Response::redirect('/admin/invoices');
    }

    public function pdf(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $pdfContent = $this->invoices->generatePdf($mid, $id);
        return Response::download($pdfContent, "invoice-{$id}.pdf", 'application/pdf');
    }

    private function getCustomers(int $mid): array
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        return $db->fetchAll("SELECT id, name FROM op_customers WHERE merchant_id = :mid ORDER BY name", ['mid' => $mid]);
    }

    private function getCurrencies(): array
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        return $db->fetchAll("SELECT code, name FROM op_currencies WHERE status = 'active' ORDER BY code");
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? '';
        $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay';
        $data['current_user'] = $_SESSION['user'] ?? [];
        $data['flash_success'] = $_SESSION['flash_success'] ?? null;
        $data['flash_error'] = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
        return Response::html($twig->render($tpl, $data));
    }
}
