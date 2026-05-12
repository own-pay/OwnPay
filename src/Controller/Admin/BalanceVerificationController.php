<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\ReconciliationService;
use OwnPay\Repository\TransactionRepository;

final class BalanceVerificationController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private ReconciliationService $recon;
    private TransactionRepository $txnRepo;

    public function __construct(Container $c, AdminSession $session, ReconciliationService $recon, TransactionRepository $txnRepo)
    {
        $this->c       = $c;
        $this->session = $session;
        $this->recon   = $recon;
        $this->txnRepo = $txnRepo;
    }

    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $currencies = $this->txnRepo->getDistinctCurrencies($mid);
        $results = [];
        foreach ($currencies as $cur) {
            $results[] = array_merge(['currency' => $cur['currency']], $this->recon->reconcile($mid, $cur['currency']));
        }

        $gateways = $this->txnRepo->getDistinctGateways($mid);

        return $this->renderAdminPage('admin/balance-verification.twig', [
            'balance_results' => $results,
            'gateways'        => $gateways,
            'active_page'     => 'balance-verification',
        ]);
    }

    public function run(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        $currency = $req->post('currency', 'BDT');

        try {
            $result = $this->recon->reconcile($mid, $currency);
            $this->session->flashSuccess(sprintf(
                'Verification complete — %s: Expected %.2f, Actual %.2f',
                $currency, $result['expected'] ?? 0, $result['actual'] ?? 0
            ));
        } catch (\Throwable $e) {
            $this->session->flashError('Verification failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/balance-verification');
    }
}
