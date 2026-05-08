<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\CurrencyService;

final class CurrencyController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;

    public function __construct(Container $c, AdminSession $session)
    {
        $this->c       = $c;
        $this->session = $session;
    }

    public function index(Request $req): Response
    {
        return Response::redirect('/admin/settings#tab-payment');
    }

    public function update(Request $req): Response
    {
        $data = $req->post();
        if (!empty($data['code']) && !empty($data['name'])) {
            $svc = $this->c->get(CurrencyService::class);
            $svc->upsert(
                strtoupper($data['code']),
                $data['name'],
                $data['symbol'] ?? '',
                $data['status'] ?? 'active'
            );
        }

        $this->session->flashSuccess('Currency saved');
        return Response::redirect('/admin/settings#tab-payment');
    }
}
