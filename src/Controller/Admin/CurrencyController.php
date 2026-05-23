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
        $codeVal = $req->post('code');
        $nameVal = $req->post('name');
        $symbolVal = $req->post('symbol', '');
        $statusVal = $req->post('status', 'active');
        $decVal = $req->post('decimal_places', '2');

        $code = is_string($codeVal) ? trim($codeVal) : '';
        $name = is_string($nameVal) ? trim($nameVal) : '';
        $symbol = is_string($symbolVal) ? trim($symbolVal) : '';
        $status = is_string($statusVal) ? trim($statusVal) : 'active';
        $dec = is_int($decVal) || is_string($decVal) ? (int)$decVal : 2;

        if ($code !== '' && $name !== '') {
            $svc = $this->c->get(CurrencyService::class);
            if (!$svc instanceof CurrencyService) {
                throw new \RuntimeException('CurrencyService unavailable');
            }
            $svc->upsert(
                strtoupper($code),
                $name,
                $symbol,
                $status,
                max(0, min(8, $dec))
            );
        }

        $this->session->flashSuccess('Currency saved');
        return Response::redirect('/admin/settings#tab-payment');
    }
}
