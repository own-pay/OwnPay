<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Service\System\PaginationService;
use OwnPay\Service\Brand\BrandContext;

final class ActivitiesController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private AuditLogRepository $auditRepo;

    public function __construct(Container $c, AdminSession $session, AuditLogRepository $auditRepo)
    {
        $this->c         = $c;
        $this->session   = $session;
        $this->auditRepo = $auditRepo;
    }

    public function index(Request $req): Response
    {
        $isSuperadmin = $_SESSION['is_superadmin'] ?? false;

        // Superadmin sees all brands; others scoped to active brand
        $mid = null;
        if (!$isSuperadmin) {
            $brand = $this->c->get(BrandContext::class);
            $brand->resolveFromRequest($req);
            $mid = $brand->getActiveBrandId();
        }

        $page    = max(1, (int) $req->query('page', '1'));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        $logs  = $this->auditRepo->listPaginated($mid, $perPage, $offset);
        $total = $this->auditRepo->countFiltered($mid);

        $pagination = PaginationService::calculate($page, $perPage, $total);

        return $this->renderAdminPage('admin/activities.twig', [
            'logs'        => $logs,
            'pagination'  => $pagination,
            'active_page' => 'activities',
        ]);
    }
}
