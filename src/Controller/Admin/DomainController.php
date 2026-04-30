<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Domain\DomainService;

final class DomainController
{
    private Container $c;
    private DomainService $domains;

    public function __construct(Container $c, DomainService $domains) { $this->c = $c; $this->domains = $domains; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $list = $this->domains->listForMerchant($mid);
        $db = $this->c->get(\OwnPay\Core\Database::class);
        // Enrich with merchant names
        foreach ($list as &$d) {
            $m = $db->fetchOne("SELECT business_name FROM op_merchants WHERE id = :id", ['id' => $d['merchant_id']]);
            $d['merchant_name'] = $m['business_name'] ?? '—';
        }
        return $this->render('admin/domains/index.twig', ['domains' => $list, 'active_page' => 'domains']);
    }

    public function add(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $domain = $req->post('domain', '');
        if (empty($domain)) { $_SESSION['flash_error'] = 'Domain required'; return Response::redirect('/admin/domains'); }

        try {
            $this->domains->addDomain($mid, $domain);
            $_SESSION['flash_success'] = 'Domain added. Configure DNS then verify.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return Response::redirect('/admin/domains');
    }

    public function verify(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $result = $this->domains->verifyDomain($mid, $id);
        $_SESSION[$result ? 'flash_success' : 'flash_error'] = $result ? 'DNS verified!' : 'DNS not yet pointing correctly';
        return Response::redirect('/admin/domains');
    }

    public function delete(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $this->domains->removeDomain($mid, $id);
        $_SESSION['flash_success'] = 'Domain removed';
        return Response::redirect('/admin/domains');
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? ''; $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay'; $data['current_user'] = $_SESSION['user'] ?? [];
        $data['flash_success'] = $_SESSION['flash_success'] ?? null; $data['flash_error'] = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
        return Response::html($twig->render($tpl, $data));
    }
}
