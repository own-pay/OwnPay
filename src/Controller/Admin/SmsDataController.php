<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Repository\SmsDataRepository;
use OwnPay\Service\System\PaginationService;

final class SmsDataController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private SmsDataRepository $smsRepo;

    public function __construct(Container $c, AdminSession $session, SmsDataRepository $smsRepo)
    {
        $this->c       = $c;
        $this->session = $session;
        $this->smsRepo = $smsRepo;
    }

    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $page   = max(1, (int) $req->query('page', '1'));
        $status = $req->query('status', '') ?: null;

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
