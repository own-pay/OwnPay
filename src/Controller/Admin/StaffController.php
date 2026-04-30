<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Auth\AuthService;
use OwnPay\Service\System\InputSanitizer;

final class StaffController
{
    private Container $c;
    private AuthService $auth;

    public function __construct(Container $c, AuthService $auth) { $this->c = $c; $this->auth = $auth; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $staff = $db->fetchAll("SELECT * FROM op_users WHERE merchant_id = :mid ORDER BY name", ['mid' => $mid]);
        return $this->render('admin/staff/index.twig', ['staff' => $staff, 'active_page' => 'staff']);
    }

    public function create(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        if ($req->method() === 'GET') {
            return $this->render('admin/staff/edit.twig', ['user' => [], 'available_permissions' => $this->getPermissions(), 'active_page' => 'staff']);
        }
        $data = $req->post();
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $db->insert("INSERT INTO op_users (merchant_id, name, email, password, role, permissions, status, created_at) VALUES (:mid, :name, :email, :pw, :role, :perms, 'active', NOW())", [
            'mid' => $mid, 'name' => InputSanitizer::string($data['name']), 'email' => $data['email'],
            'pw' => password_hash($data['password'], PASSWORD_ARGON2ID), 'role' => $data['role'] ?? 'staff',
            'perms' => json_encode($data['permissions'] ?? []),
        ]);
        $_SESSION['flash_success'] = 'Staff created';
        return Response::redirect('/admin/staff');
    }

    public function edit(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $user = $db->fetchOne("SELECT * FROM op_users WHERE id = :id AND merchant_id = :mid", ['id' => $id, 'mid' => $mid]);
        if (!$user) { $_SESSION['flash_error'] = 'Not found'; return Response::redirect('/admin/staff'); }
        $user['permissions'] = json_decode($user['permissions'] ?? '[]', true);

        if ($req->method() === 'GET') {
            return $this->render('admin/staff/edit.twig', ['user' => $user, 'available_permissions' => $this->getPermissions(), 'active_page' => 'staff']);
        }
        $data = $req->post();
        $update = ['name' => InputSanitizer::string($data['name']), 'email' => $data['email'], 'role' => $data['role'] ?? 'staff', 'permissions' => json_encode($data['permissions'] ?? [])];
        if (!empty($data['password'])) { $update['password'] = password_hash($data['password'], PASSWORD_ARGON2ID); }

        $sets = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($update)));
        $update['id'] = $id; $update['mid'] = $mid;
        $db->update("UPDATE op_users SET {$sets} WHERE id = :id AND merchant_id = :mid", $update);

        $_SESSION['flash_success'] = 'Staff updated';
        return Response::redirect('/admin/staff');
    }

    private function getPermissions(): array
    {
        return ['transactions.view','transactions.edit','invoices.manage','payment_links.manage','customers.view','gateways.manage','staff.manage','settings.manage','reports.view','sms.view','devices.manage','domains.manage','plugins.manage','system.update'];
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
