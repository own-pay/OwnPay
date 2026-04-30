<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;

/**
 * Dashboard controller.
 */
final class DashboardController
{
    private Container $c;
    private EventManager $events;

    public function __construct(Container $c, EventManager $events)
    {
        $this->c = $c;
        $this->events = $events;
    }

    public function index(Request $req): Response
    {
        $merchantId = (int) $req->getAttribute('merchant_id');
        $range = $req->get('range', 'today');
        $db = $this->c->get(\OwnPay\Core\Database::class);

        $dateFilter = match ($range) {
            'today' => "AND DATE(t.created_at) = CURDATE()",
            '7d'    => "AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '30d'   => "AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => '',
        };

        $stats = $db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END), 0) as total_revenue,
                COUNT(CASE WHEN status='completed' THEN 1 END) as completed_count,
                COUNT(CASE WHEN status='pending' THEN 1 END) as pending_count
             FROM op_transactions t
             WHERE t.merchant_id = :mid {$dateFilter}",
            ['mid' => $merchantId]
        );

        $stats['customer_count'] = $db->fetchOne(
            "SELECT COUNT(*) as cnt FROM op_customers WHERE merchant_id = :mid",
            ['mid' => $merchantId]
        )['cnt'] ?? 0;

        $recent = $db->fetchAll(
            "SELECT t.*, c.name as customer_name
             FROM op_transactions t
             LEFT JOIN op_customers c ON c.id = t.customer_id
             WHERE t.merchant_id = :mid
             ORDER BY t.created_at DESC LIMIT 10",
            ['mid' => $merchantId]
        );

        return $this->render('admin/dashboard.twig', [
            'stats'               => $stats,
            'recent_transactions' => $recent,
            'range'               => $range,
            'active_page'         => 'dashboard',
        ]);
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? '';
        $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay';
        $data['current_user'] = $_SESSION['user'] ?? [];
        return Response::html($twig->render($tpl, $data));
    }
}
