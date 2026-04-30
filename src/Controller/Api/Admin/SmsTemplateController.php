<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

final class SmsTemplateController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $templates = $db->fetchAll("SELECT id, event, body, enabled, created_at FROM op_sms_templates WHERE merchant_id = :mid ORDER BY event", ['mid' => $mid]);
        return Response::json(['success' => true, 'data' => $templates]);
    }

    public function update(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->jsonBody();
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $db->update("UPDATE op_sms_templates SET body = :body, enabled = :en WHERE id = :id AND merchant_id = :mid", [
            'body' => $body['body'] ?? '', 'en' => ($body['enabled'] ?? true) ? 1 : 0, 'id' => $id, 'mid' => $mid,
        ]);
        return Response::json(['success' => true]);
    }
}
