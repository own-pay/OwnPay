<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\MobileNotificationRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\System\PaginationService;

/**
 * Controller for viewing mobile push notification logs within the admin portal.
 */
final class MobileNotificationLogController
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
     * The mobile notification repository.
     */
    private MobileNotificationRepository $notifications;

    /**
     * MobileNotificationLogController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param AdminSession $session The admin session manager.
     * @param MobileNotificationRepository $notifications The mobile notification repository.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        MobileNotificationRepository $notifications
    ) {
        $this->c = $c;
        $this->session = $session;
        $this->notifications = $notifications;
    }

    /**
     * Display a paginated list of mobile notifications log filtered by the active brand.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with the notifications log page.
     * @throws \Exception If rendering fails.
     */
    public function index(Request $req): Response
    {
        $mid = $this->resolveMerchant($req);

        $pageVal = $req->query('page', '1');
        $page = max(1, is_scalar($pageVal) && is_numeric($pageVal) ? (int) $pageVal : 1);

        $typeVal = $req->query('type', '');
        $qVal = $req->query('q', '');

        $type = is_string($typeVal) ? trim($typeVal) : '';
        $q = is_string($qVal) ? trim($qVal) : '';

        $where = "1=1";
        $params = [];

        if ($type !== '') {
            $where .= " AND type = :type";
            $params['type'] = $type;
        }

        if ($q !== '') {
            $where .= " AND (title LIKE :q OR body LIKE :q OR device_uuid LIKE :q)";
            $params['q'] = '%' . $q . '%';
        }

        $repo = $this->notifications->forTenant($mid);
        $total = $repo->countScoped($where, $params);
        $pagination = PaginationService::calculate($page, 25, $total);

        // Fetch paginated scoped records
        $result = $repo->paginateScoped($page, 25, $where, $params, 'id DESC');
        $items = $result['items'] ?? [];

        return $this->renderAdminPage('admin/devices/notifications.twig', [
            'notifications' => $items,
            'pagination'    => $pagination,
            'filters'       => [
                'type' => $type,
                'q'    => $q,
            ],
            'active_page'   => 'push-logs',
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
