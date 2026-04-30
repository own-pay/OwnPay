<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\System\InputSanitizer;

final class MerchantController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function index(Request $req): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $merchants = $db->fetchAll("SELECT m.*, d.domain FROM op_merchants m LEFT JOIN op_custom_domains d ON d.merchant_id = m.id AND d.dns_verified = 1 ORDER BY m.created_at DESC");
        return $this->render('admin/merchants/index.twig', ['merchants' => $merchants, 'active_page' => 'merchants']);
    }

    public function create(Request $req): Response
    {
        if ($req->method() === 'GET') return $this->render('admin/merchants/edit.twig', ['merchant' => [], 'active_page' => 'merchants']);
        $data = $req->post();
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $db->insert("INSERT INTO op_merchants (business_name, email, phone, status, created_at) VALUES (:name, :email, :phone, :status, NOW())", [
            'name' => InputSanitizer::string($data['business_name']), 'email' => $data['email'] ?? '', 'phone' => $data['phone'] ?? '', 'status' => $data['status'] ?? 'active',
        ]);
        $_SESSION['flash_success'] = 'Merchant created';
        return Response::redirect('/admin/merchants');
    }

    public function edit(Request $req, int $id): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $merchant = $db->fetchOne("SELECT m.*, d.domain, d.dns_verified FROM op_merchants m LEFT JOIN op_custom_domains d ON d.merchant_id = m.id ORDER BY d.created_at DESC LIMIT 1 WHERE m.id = :id", ['id' => $id]);
        // Fix: subquery approach
        $merchant = $db->fetchOne("SELECT * FROM op_merchants WHERE id = :id", ['id' => $id]);
        if (!$merchant) { $_SESSION['flash_error'] = 'Not found'; return Response::redirect('/admin/merchants'); }
        $domain = $db->fetchOne("SELECT domain, dns_verified FROM op_custom_domains WHERE merchant_id = :mid ORDER BY created_at DESC LIMIT 1", ['mid' => $id]);
        if ($domain) { $merchant['domain'] = $domain['domain']; $merchant['dns_verified'] = $domain['dns_verified']; }

        if ($req->method() === 'GET') return $this->render('admin/merchants/edit.twig', ['merchant' => $merchant, 'active_page' => 'merchants']);
        $data = $req->post();
        $db->update("UPDATE op_merchants SET business_name = :name, email = :email, phone = :phone, status = :status WHERE id = :id", [
            'name' => InputSanitizer::string($data['business_name']), 'email' => $data['email'] ?? '', 'phone' => $data['phone'] ?? '', 'status' => $data['status'] ?? 'active', 'id' => $id,
        ]);
        $_SESSION['flash_success'] = 'Merchant updated';
        return Response::redirect('/admin/merchants');
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
