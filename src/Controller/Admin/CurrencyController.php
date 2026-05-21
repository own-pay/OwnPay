<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\CurrencyService;

/**
 * Class CurrencyController
 *
 * Coordinates administrative currency actions, letting administrators configure supported currencies.
 *
 * @package OwnPay\Controller\Admin
 */
final class CurrencyController
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
     * CurrencyController constructor.
     *
     * @param Container    $c       The dependency injection container.
     * @param AdminSession $session The administrative session service.
     */
    public function __construct(Container $c, AdminSession $session)
    {
        $this->c       = $c;
        $this->session = $session;
    }

    /**
     * Redirects to the payment settings tab.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function index(Request $req): Response
    {
        return Response::redirect('/admin/settings#tab-payment');
    }

    /**
     * Creates or updates a currency configuration in the system.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function update(Request $req): Response
    {
        $data = $req->post();
        if (!empty($data['code']) && !empty($data['name'])) {
            $svc = $this->c->get(CurrencyService::class);
            $svc->upsert(
                strtoupper($data['code']),
                $data['name'],
                $data['symbol'] ?? '',
                $data['status'] ?? 'active',
                max(0, min(8, (int) ($data['decimal_places'] ?? 2)))
            );
        }

        $this->session->flashSuccess('Currency saved');
        return Response::redirect('/admin/settings#tab-payment');
    }
}
