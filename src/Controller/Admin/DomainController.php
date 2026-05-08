<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Domain\DomainService;

final class DomainController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private DomainService $domains;

    public function __construct(Container $c, AdminSession $session, DomainService $domains)
    {
        $this->c = $c;
        $this->session = $session;
        $this->domains = $domains;
    }

    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $repo = $this->c->get(\OwnPay\Repository\DomainRepository::class);
        $list = $repo->forTenant($mid)->listAllScoped();

        foreach ($list as &$d) {
            $m = $this->c->get(\OwnPay\Repository\MerchantRepository::class)->find($d['merchant_id']);
            $d['merchant_name'] = $m['name'] ?? '—';
        }

        return $this->renderAdminPage('admin/domains/index.twig', [
            'domains'     => $list,
            'active_page' => 'domains',
            'server_ip'   => gethostbyname($_SERVER['HTTP_HOST'] ?? '127.0.0.1'),
        ]);
    }

    public function store(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $domain = $req->post('domain', '');
        if (empty($domain)) {
            $this->session->flashError('Domain required');
            return Response::redirect('/admin/domains');
        }

        $result = $this->domains->map($mid, $domain);
        if (!empty($result['success'])) {
            $this->session->flashSuccess('Domain added. ' . ($result['instructions'] ?? 'Configure DNS then verify.'));
        } else {
            $this->session->flashError($result['error'] ?? 'Failed to add domain');
        }
        return Response::redirect('/admin/domains');
    }

    public function verify(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $result = $this->domains->verify($id, $mid);
        if (!empty($result['success'])) {
            $this->session->flashSuccess('DNS verified!');
        } else {
            $this->session->flashError($result['error'] ?? 'DNS not yet pointing correctly');
        }
        return Response::redirect('/admin/domains');
    }

    public function delete(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $this->domains->remove($id, $mid);
        $this->session->flashSuccess('Domain removed');
        return Response::redirect('/admin/domains');
    }
}
