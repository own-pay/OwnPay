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
            'active_subpage' => 'activities',
        ]);
    }

    /**
     * Renders detailed JSON diff payload for a specific audit log entry.
     */
    public function details(Request $req): Response
    {
        $idVal = $req->param('id');
        $id = is_numeric($idVal) ? (int)$idVal : 0;

        $log = $this->auditRepo->find($id);
        if (!$log) {
            return Response::html('<div class="op-alert op-alert-danger">Audit log entry not found.</div>', 404);
        }

        // Check permission: Superadmins can review logs globally; standard staff scope to active brand ID
        if (!$this->session->isSuperadmin()) {
            $brand = $this->c->get(BrandContext::class);
            if (!$brand instanceof BrandContext) {
                throw new \RuntimeException('BrandContext service unavailable');
            }
            $brand->resolveFromRequest($req);
            $mid = $brand->getActiveBrandId();
            $logMid = isset($log['merchant_id']) && is_numeric($log['merchant_id']) ? (int)$log['merchant_id'] : null;
            if ($logMid !== $mid) {
                return Response::html('<div class="op-alert op-alert-danger">Access denied.</div>', 403);
            }
        }

        $oldValStr = isset($log['old_values']) && is_string($log['old_values']) ? $log['old_values'] : '{}';
        $newValStr = isset($log['new_values']) && is_string($log['new_values']) ? $log['new_values'] : '{}';

        $oldValues = json_decode($oldValStr, true);
        $newValues = json_decode($newValStr, true);

        // Fetch user name for header details
        $operator = 'System';
        $logUserId = isset($log['user_id']) && is_numeric($log['user_id']) ? (int)$log['user_id'] : 0;
        if ($logUserId > 0) {
            $db = $this->auditRepo->getDatabase();
            $userVal = $db->fetchOne("SELECT name FROM op_merchant_users WHERE id = :id", ['id' => $logUserId]);
            if (is_array($userVal) && isset($userVal['name']) && is_string($userVal['name'])) {
                $operator = $userVal['name'];
            }
        }

        $log['user_name'] = $operator;

        return $this->renderAdminPage('admin/activity-details.twig', [
            'log'        => $log,
            'old_values' => is_array($oldValues) ? $oldValues : [],
            'new_values' => is_array($newValues) ? $newValues : [],
        ]);
    }
}
