<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Domain\DomainService;

/**
 * Class DomainController
 *
 * Coordinates administrative custom domain configuration, validation, mapping, verification, and deletion.
 *
 * @package OwnPay\Controller\Admin
 */
final class DomainController
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
     * @var DomainService The custom domain management service.
     */
    private DomainService $domains;

    /**
     * DomainController constructor.
     *
     * @param Container     $c       The dependency injection container.
     * @param AdminSession  $session The administrative session service.
     * @param DomainService $domains The custom domain management service.
     */
    public function __construct(Container $c, AdminSession $session, DomainService $domains)
    {
        $this->c = $c;
        $this->session = $session;
        $this->domains = $domains;
    }

    /**
     * Renders the custom domains overview list page for the active brand.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The custom domains dashboard view response.
     */
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

        $host = $req->header('Host') ?: '127.0.0.1';
        if (str_contains($host, ']')) {
            if (preg_match('/^\[(.*?)\]/', $host, $matches)) {
                $host = $matches[1];
            }
        } else {
            $parts = explode(':', $host);
            $host = $parts[0];
        }

        return $this->renderAdminPage('admin/domains/index.twig', [
            'domains'     => $list,
            'active_page' => 'domains',
            'server_ip'   => gethostbyname($host),
        ]);
    }

    /**
     * Maps a new custom domain to the active brand.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
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
            $this->session->flashSuccess('Domain added. ' . $result['instructions']);
        } else {
            $this->session->flashError($result['error']);
        }
        return Response::redirect('/admin/domains');
    }

    /**
     * Triggers DNS checks (TXT & A records) to verify domain mappings.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function verify(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $result = $this->domains->verify($id, $mid);
        if (!empty($result['success'])) {
            $msg = 'DNS verified!';
            if (!empty($result['warning'])) {
                $msg .= ' ⚠️ ' . $result['warning'];
            }
            $this->session->flashSuccess($msg);
        } else {
            $this->session->flashError($result['error']);
        }
        return Response::redirect('/admin/domains');
    }

    /**
     * Removes an existing custom domain mapping.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
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
