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
 * Class PaymentLinkController
 *
 * Handles management of customer checkout payment links (one-off or reusable payment pages),
 * including creation, editing, dynamic redirection routes, and event hooks.
 *
 * Fired actions:
 * - `payment_link.created`: Invoked immediately after successfully creating a new payment link.
 * - `payment_link.updated`: Invoked immediately after updating an existing payment link.
 *
 * @package OwnPay\Controller\Admin
 */
final class PaymentLinkController
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
     * @var PaymentLinkService The payment link management service.
     */
    private PaymentLinkService $links;

    /**
     * @var EventManager The hooks and actions event manager.
     */
    private EventManager $events;

    /**
     * PaymentLinkController constructor.
     *
     * @param Container          $c       The dependency injection container.
     * @param AdminSession       $session The administrative session service.
     * @param PaymentLinkService $links   The payment link management service.
     * @param EventManager       $events  The hooks and actions event manager.
     */
    public function __construct(Container $c, AdminSession $session, PaymentLinkService $links, EventManager $events)
    {
        $this->c       = $c;
        $this->session = $session;
        $this->links   = $links;
        $this->events  = $events;
    }

    /**
     * Renders a list of all payment links for the active merchant brand.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The payment links overview page response.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        $list = $this->links->listForMerchant($mid);

        return $this->renderAdminPage('admin/payment-links/index.twig', [
            'payment_links' => $list,
            'active_page'   => 'payment-links',
        ]);
    }

    /**
     * Displays creation form or handles dynamic submission for a new payment link.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The form page or redirect response.
     */
    public function create(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        if ($req->method() === 'GET') {
            return $this->renderAdminPage('admin/payment-links/edit.twig', [
                'link'        => [],
                'currencies'  => $this->getCurrencies(),
                'active_page' => 'payment-links',
            ]);
        }

        $link = $this->links->create($mid, $req->post());
        $this->events->doAction('payment_link.created', $link);
        $this->session->flashSuccess('Payment link created');
        return Response::redirect('/admin/payment-links');
    }

    /**
     * Displays modification form or handles updating an existing payment link.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The form page or redirect response.
     */
    public function edit(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        $id = (int) $req->param('id');
        $link = $this->links->find($mid, $id);

        if (!$link) {
            $this->session->flashError('Not found');
            return Response::redirect('/admin/payment-links');
        }

        if ($req->method() === 'GET') {
            return $this->renderAdminPage('admin/payment-links/edit.twig', [
                'link'        => $link,
                'currencies'  => $this->getCurrencies(),
                'active_page' => 'payment-links',
            ]);
        }

        $updated = $this->links->update($mid, $id, $req->post());
        $this->events->doAction('payment_link.updated', $updated);
        $this->session->flashSuccess('Updated');
        return Response::redirect('/admin/payment-links');
    }

    /**
     * Endpoint alias handler for storing a new payment link.
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
     * Endpoint alias handler for displaying payment link modification interface.
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
     * Retrieves all system currencies.
     *
     * @return array<int, array<string, mixed>> Available currencies list.
     */
    private function getCurrencies(): array
    {
        return $this->c->get(\OwnPay\Service\Payment\CurrencyService::class)->listAll();
    }
}
