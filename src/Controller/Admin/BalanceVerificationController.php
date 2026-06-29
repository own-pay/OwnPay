<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\ReconciliationService;
use OwnPay\Repository\TransactionRepository;

/**
 * Class BalanceVerificationController
 *
 * Provides administrative functionality to run reconciliation audits on ledger accounts vs transaction logs.
 *
 * @package OwnPay\Controller\Admin
 */
final class BalanceVerificationController
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
     * @var ReconciliationService The reconciliation service.
     */
    private ReconciliationService $recon;

    /**
     * @var TransactionRepository The transaction repository.
     */
    private TransactionRepository $txnRepo;

    /**
     * BalanceVerificationController constructor.
     *
     * @param Container             $c       The dependency injection container.
     * @param AdminSession          $session The administrative session service.
     * @param ReconciliationService $recon   The reconciliation service.
     * @param TransactionRepository $txnRepo The transaction repository.
     */
    public function __construct(Container $c, AdminSession $session, ReconciliationService $recon, TransactionRepository $txnRepo)
    {
        $this->c       = $c;
        $this->session = $session;
        $this->recon   = $recon;
        $this->txnRepo = $txnRepo;
    }

    /**
     * Displays the balance verification status and reconciliation summaries for all active currencies.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The balance verification overview response.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $currencies = $this->txnRepo->getDistinctCurrencies($mid);
        $results = [];
        foreach ($currencies as $cur) {
            $currencyCode = isset($cur['currency']) && is_string($cur['currency']) ? $cur['currency'] : 'BDT';
            $results[] = array_merge(['currency' => $currencyCode], $this->recon->reconcile($mid, $currencyCode));
        }

        $gateways = $this->txnRepo->getDistinctGateways($mid);

        return $this->renderAdminPage('admin/balance-verification.twig', [
            'balance_results' => $results,
            'gateways'        => $gateways,
            'active_page'     => 'balance-verification',
        ]);
    }

    /**
     * Triggers manual reconciliation verification for a specific currency context.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function run(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }
        $currencyVal = $req->post('currency', 'BDT');
        $currency = is_string($currencyVal) ? $currencyVal : 'BDT';

        try {
            $result = $this->recon->reconcile($mid, $currency);
            $this->session->flashSuccess(sprintf(
                'Verification complete - %s: Expected %.2f, Actual %.2f',
                $currency,
                (float) $result['expected_balance'],
                (float) $result['ledger_balance']
            ));
        } catch (\Throwable $e) {
            $this->session->flashError('Verification failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/balance-verification');
    }
}
