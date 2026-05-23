<?php

declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Repository\SmsDataRepository;
use OwnPay\Service\System\PaginationService;

/**
 * Controller for managing brand-specific SMS data logs in the admin portal.
 */
final class SmsDataController
{
    use AdminPageTrait;

    /**
     * The dependency injection container.
     */
    private Container $c;

    /**
     * The SMS data repository instance.
     */
    private SmsDataRepository $smsRepo;

    /**
     * SmsDataController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param SmsDataRepository $smsRepo The SMS data repository instance.
     */
    public function __construct(Container $c, SmsDataRepository $smsRepo)
    {
        $this->c       = $c;
        $this->smsRepo = $smsRepo;
    }

    /**
     * Render the list of SMS data logs for the active brand.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with the rendered page.
     * @throws \Exception If the brand context or pagination calculation fails.
     */
    public function index(Request $req): Response
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

        $pageVal = $req->query('page', '1');
        $page = max(1, is_scalar($pageVal) && is_numeric($pageVal) ? (int) $pageVal : 1);
        $statusVal = $req->query('status', '');
        $status = is_string($statusVal) && $statusVal !== '' ? $statusVal : null;

        $repo   = $this->smsRepo->forTenant($mid);
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $result  = $repo->listPaginated($perPage, $offset, $status);

        $pagination = PaginationService::calculate($page, $perPage, $result['total']);

        return $this->renderAdminPage('admin/sms-data.twig', [
            'sms_data'    => $result['items'],
            'filters'     => ['status' => $status ?? ''],
            'pagination'  => $pagination,
            'active_page' => 'sms-data',
        ]);
    }
}

