<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\RefundRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\Payment\RefundService;
use OwnPay\Service\System\PaginationService;

/**
 * Controller for managing customer refunds in the admin panel.
 */
final class RefundController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private RefundRepository $refunds;
    private RefundService $refundService;

    /**
     * RefundController constructor.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        RefundRepository $refunds,
        RefundService $refundService
    ) {
        $this->c = $c;
        $this->session = $session;
        $this->refunds = $refunds;
        $this->refundService = $refundService;
    }

    /**
     * Render the paginated refunds index page.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $isGlobal = $brand->isGlobalView();
        $mid = $brand->getActiveBrandId();
        if ($mid === null && !$isGlobal) {
            throw new \RuntimeException('No active brand found.');
        }

        $pageVal = $req->query('page', '1');
        $page = max(1, is_numeric($pageVal) ? (int)$pageVal : 1);

        $qVal = $req->query('q', '');
        $q = is_string($qVal) ? trim($qVal) : '';

        $statusVal = $req->query('status', '');
        $status = is_string($statusVal) ? trim($statusVal) : '';

        $extraWhere = '1=1';
        $params = [];

        if ($q !== '') {
            $extraWhere .= ' AND (reason LIKE :q OR uuid LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        if ($status !== '') {
            $extraWhere .= ' AND status = :status';
            $params['status'] = $status;
        }

        $scopedRepo = $isGlobal ? $this->refunds->forAllTenants() : $this->refunds->forTenant($mid);
        $total = $scopedRepo->countScoped($extraWhere, $params);
        $pagination = PaginationService::calculate($page, 25, $total);

        $results = $scopedRepo->paginateScoped($page, 25, $extraWhere, $params, 'id DESC');
        $refundsList = $results['items'] ?? [];

        return $this->renderAdminPage('admin/refunds/index.twig', [
            'refunds'     => $refundsList,
            'pagination'  => $pagination,
            'filters'     => [
                'q'      => $q,
                'status' => $status
            ],
            'active_page' => 'refunds'
        ]);
    }

    /**
     * Trigger a refund for a transaction.
     */
    public function store(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $txnIdVal = $req->post('transaction_id');
        $txnId = is_numeric($txnIdVal) ? (int)$txnIdVal : 0;

        $amountVal = $req->post('amount');
        $amount = (is_numeric($amountVal) || is_string($amountVal)) ? (string)$amountVal : null;
        if ($amount === '') {
            $amount = null;
        }

        $reasonVal = $req->post('reason');
        $reason = is_string($reasonVal) ? trim($reasonVal) : '';

        try {
            $this->refundService->create($mid, [
                'transaction_id' => $txnId,
                'amount'         => $amount,
                'reason'         => $reason
            ]);
            $this->session->flashSuccess('Refund processed successfully.');
        } catch (\Throwable $e) {
            $this->session->flashError('Refund failed: ' . $e->getMessage());
        }

        return Response::redirect('/admin/transactions/' . $txnId);
    }
}
