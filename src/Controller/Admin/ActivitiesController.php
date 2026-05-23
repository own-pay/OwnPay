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

/**
 * Controller orchestrating the display of audit logs and system activities within the admin portal.
 */
final class ActivitiesController
{
    use AdminPageTrait;

    private Container $c;

    /**
     * Session wrapper service for authenticated administrative operations.
     *
     * @var AdminSession
     * @phpstan-ignore property.onlyWritten
     */
    private AdminSession $session;

    /**
     * Repository containing audit trail logs.
     *
     * @var AuditLogRepository
     */
    private AuditLogRepository $auditRepo;

    /**
     * Initialises the ActivitiesController.
     *
     * @param Container $c Dependency injection container instance.
     * @param AdminSession $session Active admin session service.
     * @param AuditLogRepository $auditRepo Audit log data repository.
     */
    public function __construct(Container $c, AdminSession $session, AuditLogRepository $auditRepo)
    {
        $this->c         = $c;
        $this->session   = $session;
        $this->auditRepo = $auditRepo;
    }

    /**
     * Renders a list of system activities.
     *
     * Scopes results based on permissions: Superadmins can review logs globally,
     * while standard administrators are restricted to logs matching their active brand context.
     *
     * @param Request $req Outbound HTTP request instance.
     * @return Response HTTP response wrapper.
     */
    public function index(Request $req): Response
    {
        $isSuperadmin = $this->session->isSuperadmin();

        // Superadmins inspect all records globally; standard staff scope to active brand ID
        $mid = null;
        if (!$isSuperadmin) {
            $brand = $this->c->get(BrandContext::class);
            if (!$brand instanceof BrandContext) {
                throw new \RuntimeException('BrandContext service unavailable');
            }
            $brand->resolveFromRequest($req);
            $mid = $brand->getActiveBrandId();
        }

        $pageVal = $req->query('page', '1');
        $page = max(1, is_int($pageVal) || is_string($pageVal) ? (int)$pageVal : 1);
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
