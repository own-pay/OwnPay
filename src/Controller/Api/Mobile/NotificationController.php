<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

final class NotificationController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $notifs = $db->fetchAll(
            "SELECT id, type, title, body, data, read_at, created_at FROM op_notifications WHERE merchant_id = :mid AND device_id = :did ORDER BY created_at DESC LIMIT 50",
            ['mid' => $mid, 'did' => (int) $req->getAttribute('device_id')]
        );
        return Response::json(['success' => true, 'data' => $notifs]);
    }

    public function ack(Request $req): Response
    {
        $body = $req->jsonBody();
        $ids = array_filter(array_map('intval', $body['ids'] ?? []));
        if (empty($ids)) return Response::json(['success' => false, 'error' => 'ids required'], 422);

        $db = $this->c->get(\OwnPay\Core\Database::class);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->update("UPDATE op_notifications SET read_at = NOW() WHERE id IN ({$placeholders}) AND merchant_id = ?", array_merge($ids, [(int) $req->getAttribute('merchant_id')]));

        return Response::json(['success' => true, 'acknowledged' => count($ids)]);
    }
}
