<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

final class SmsTemplateAdminController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $templates = $db->fetchAll("SELECT * FROM op_sms_templates WHERE merchant_id = :mid ORDER BY event", ['mid' => $mid]);
        $queue = $db->fetchAll("SELECT * FROM op_comm_log WHERE channel = 'sms' AND merchant_id = :mid ORDER BY created_at DESC LIMIT 50", ['mid' => $mid]);
        $stats = $db->fetchOne("SELECT COUNT(CASE WHEN status='pending' THEN 1 END) as pending FROM op_comm_log WHERE channel='sms' AND merchant_id = :mid", ['mid' => $mid]);

        return $this->render('admin/sms-center/index.twig', [
            'sms_templates' => $templates, 'sms_queue' => $queue, 'queue_stats' => $stats ?? [], 'active_page' => 'sms-center',
        ]);
    }

    public function edit(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $tpl = $db->fetchOne("SELECT * FROM op_sms_templates WHERE id = :id AND merchant_id = :mid", ['id' => $id, 'mid' => $mid]);
        if (!$tpl) { $_SESSION['flash_error'] = 'Not found'; return Response::redirect('/admin/sms-center'); }

        if ($req->method() === 'POST') {
            $db->update("UPDATE op_sms_templates SET body = :body, enabled = :en WHERE id = :id AND merchant_id = :mid", [
                'body' => $req->post('body', ''), 'en' => $req->post('enabled') ? 1 : 0, 'id' => $id, 'mid' => $mid,
            ]);
            $_SESSION['flash_success'] = 'Template updated';
            return Response::redirect('/admin/sms-center');
        }

        return $this->render('admin/sms-center/edit.twig', ['template' => $tpl, 'active_page' => 'sms-center']);
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
