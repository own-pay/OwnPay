<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

final class SmsQueueController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $queue = $db->fetchAll("SELECT id, `to`, body, status, attempt, created_at, sent_at FROM op_comm_log WHERE channel='sms' AND merchant_id = :mid ORDER BY created_at DESC LIMIT 100", ['mid' => $mid]);
        return Response::json(['success' => true, 'data' => $queue]);
    }

    public function retry(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $db->update("UPDATE op_comm_log SET status = 'pending', attempt = 0 WHERE id = :id AND merchant_id = :mid AND channel = 'sms'", ['id' => $id, 'mid' => $mid]);
        return Response::json(['success' => true, 'message' => 'Queued for retry']);
    }
}
