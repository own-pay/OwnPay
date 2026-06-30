<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\PaymentIntentRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\System\PaginationService;

/**
 * Controller for managing payment intents within the admin portal.
 */
final class PaymentIntentController
{
    use AdminPageTrait;

    /**
     * The dependency injection container.
     */
    private Container $c;

    /**
     * The admin session manager.
     */
    private AdminSession $session;

    /**
     * The payment intent repository.
     */
    private PaymentIntentRepository $intents;

    /**
     * PaymentIntentController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param AdminSession $session The admin session manager.
     * @param PaymentIntentRepository $intents The payment intent repository.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        PaymentIntentRepository $intents
    ) {
        $this->c = $c;
        $this->session = $session;
        $this->intents = $intents;
    }

    /**
     * Display a paginated list of payment intents filtered by the active brand and status/search query.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with the payment intents index page.
     * @throws \Exception If rendering fails.
     */
    public function index(Request $req): Response
    {
        $mid = $this->resolveMerchant($req);

        $pageVal = $req->query('page', '1');
        $page = max(1, is_scalar($pageVal) && is_numeric($pageVal) ? (int) $pageVal : 1);

        $statusVal = $req->query('status', '');
        $qVal = $req->query('q', '');

        $status = is_string($statusVal) ? trim($statusVal) : '';
        $q = is_string($qVal) ? trim($qVal) : '';

        $where = "1=1";
        $params = [];

        if ($status !== '') {
            $where .= " AND status = :status";
            $params['status'] = $status;
        }

        if ($q !== '') {
            $where .= " AND (uuid LIKE :q OR description LIKE :q OR currency LIKE :q)";
            $params['q'] = '%' . $q . '%';
        }

        $repo = $this->intents->forTenant($mid);
        $total = $repo->countScoped($where, $params);
        $pagination = PaginationService::calculate($page, 25, $total);

        // Fetch paginated scoped records
        $result = $repo->paginateScoped($page, 25, $where, $params, 'id DESC');
        $items = $result['items'] ?? [];

        return $this->renderAdminPage('admin/payment-intents/index.twig', [
            'payment_intents' => $items,
            'pagination'      => $pagination,
            'filters'         => [
                'status' => $status,
                'q'      => $q,
            ],
            'active_page'     => 'payment-intents',
        ]);
    }

    /**
     * Show details for a specific payment intent.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response.
     */
    public function show(Request $req): Response
    {
        $id = (int) $req->param('id');
        $mid = $this->resolveMerchant($req);

        $intent = $this->intents->forTenant($mid)->findScoped($id);
        if ($intent === null) {
            $this->session->flashError('Payment intent not found');
            return Response::redirect('/admin/payment-intents');
        }

        return $this->renderAdminPage('admin/payment-intents/show.twig', [
            'intent'      => $intent,
            'active_page' => 'payment-intents',
        ]);
    }

    /**
     * Resolve the active merchant ID from the request.
     */
    private function resolveMerchant(Request $req): int
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $mid = 0;
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }
        return $mid;
    }
}
