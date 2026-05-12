<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\PaymentLinkService;
use OwnPay\Event\EventManager;

/**
 * Fires: payment_link.created, payment_link.updated
 */
final class PaymentLinkController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private PaymentLinkService $links;
    private EventManager $events;

    public function __construct(Container $c, AdminSession $session, PaymentLinkService $links, EventManager $events)
    {
        $this->c = $c;
        $this->session = $session;
        $this->links = $links;
        $this->events = $events;
    }

    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class); $brand->resolveFromRequest($req); $mid = $brand->getActiveBrandId();
        $list = $this->links->listForMerchant($mid);
        return $this->renderAdminPage('admin/payment-links/index.twig', ['payment_links' => $list, 'active_page' => 'payment-links']);
    }

    public function create(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class); $brand->resolveFromRequest($req); $mid = $brand->getActiveBrandId();
        if ($req->method() === 'GET') {
            return $this->renderAdminPage('admin/payment-links/edit.twig', ['link' => [], 'currencies' => $this->getCurrencies(), 'active_page' => 'payment-links']);
        }
        $link = $this->links->create($mid, $req->post());
        $this->events->doAction('payment_link.created', $link);
        $this->session->flashSuccess('Payment link created');
        return Response::redirect('/admin/payment-links');
    }

    public function edit(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class); $brand->resolveFromRequest($req); $mid = $brand->getActiveBrandId();
        $id = (int) $req->param('id');
        $link = $this->links->find($mid, $id);
        if (!$link) { $this->session->flashError('Not found'); return Response::redirect('/admin/payment-links'); }
        if ($req->method() === 'GET') {
            return $this->renderAdminPage('admin/payment-links/edit.twig', ['link' => $link, 'currencies' => $this->getCurrencies(), 'active_page' => 'payment-links']);
        }
        $updated = $this->links->update($mid, $id, $req->post());
        $this->events->doAction('payment_link.updated', $updated);
        $this->session->flashSuccess('Updated');
        return Response::redirect('/admin/payment-links');
    }

    /** POST /admin/payment-links/store â€” alias for POST branch of create() */
    public function store(Request $req): Response
    {
        return $this->create($req);
    }

    /** GET /admin/payment-links/{id} â€” show edit form for existing payment link */
    public function show(Request $req): Response
    {
        return $this->edit($req);
    }

    /** POST /admin/payment-links/{id}/update â€” process edit form */
    public function update(Request $req): Response
    {
        return $this->edit($req);
    }

    private function getCurrencies(): array
    {
        return $this->c->get(\OwnPay\Service\Payment\CurrencyService::class)->listAll();
    }

}

