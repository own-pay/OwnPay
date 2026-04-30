<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\PaymentLinkService;
use OwnPay\Event\EventManager;

/**
 * Fires: payment_link.created, payment_link.updated
 */
final class PaymentLinkController
{
    private Container $c;
    private PaymentLinkService $links;
    private EventManager $events;

    public function __construct(Container $c, PaymentLinkService $links, EventManager $events)
    {
        $this->c = $c;
        $this->links = $links;
        $this->events = $events;
    }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $list = $this->links->listForMerchant($mid);
        return $this->render('admin/payment-links/index.twig', ['payment_links' => $list, 'active_page' => 'payment-links']);
    }

    public function create(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        if ($req->method() === 'GET') {
            return $this->render('admin/payment-links/edit.twig', ['link' => [], 'currencies' => $this->getCurrencies(), 'active_page' => 'payment-links']);
        }
        $link = $this->links->create($mid, $req->post());
        $this->events->doAction('payment_link.created', $link);
        $_SESSION['flash_success'] = 'Payment link created';
        return Response::redirect('/admin/payment-links');
    }

    public function edit(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $link = $this->links->find($mid, $id);
        if (!$link) { $_SESSION['flash_error'] = 'Not found'; return Response::redirect('/admin/payment-links'); }
        if ($req->method() === 'GET') {
            return $this->render('admin/payment-links/edit.twig', ['link' => $link, 'currencies' => $this->getCurrencies(), 'active_page' => 'payment-links']);
        }
        $updated = $this->links->update($mid, $id, $req->post());
        $this->events->doAction('payment_link.updated', $updated);
        $_SESSION['flash_success'] = 'Updated';
        return Response::redirect('/admin/payment-links');
    }

    private function getCurrencies(): array
    {
        return $this->c->get(\OwnPay\Core\Database::class)->fetchAll("SELECT code, name FROM op_currencies WHERE status='active' ORDER BY code");
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? '';
        $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay';
        $data['current_user'] = $_SESSION['user'] ?? [];
        $data['flash_success'] = $_SESSION['flash_success'] ?? null; $data['flash_error'] = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
        return Response::html($twig->render($tpl, $data));
    }
}
