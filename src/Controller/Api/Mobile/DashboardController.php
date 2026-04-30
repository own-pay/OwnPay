<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

final class DashboardController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);

        $today = $db->fetchOne(
            "SELECT COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) as revenue, COUNT(*) as total, COUNT(CASE WHEN status='pending' THEN 1 END) as pending FROM op_transactions WHERE merchant_id = :mid AND DATE(created_at) = CURDATE()",
            ['mid' => $mid]
        );

        $recent = $db->fetchAll(
            "SELECT trx_id, amount, currency, status, gateway, created_at FROM op_transactions WHERE merchant_id = :mid ORDER BY created_at DESC LIMIT 5",
            ['mid' => $mid]
        );

        $unreadNotifs = (int) ($db->fetchOne(
            "SELECT COUNT(*) as cnt FROM op_notifications WHERE merchant_id = :mid AND device_id = :did AND read_at IS NULL",
            ['mid' => $mid, 'did' => (int) $req->getAttribute('device_id')]
        )['cnt'] ?? 0);

        return Response::json([
            'success' => true,
            'today' => $today,
            'recent_transactions' => $recent,
            'unread_notifications' => $unreadNotifs,
            'server_time' => date('c'),
        ], 200, ['X-API-Version' => $this->c->get('config.app')['version'] ?? '0.1.0']);
    }
}
