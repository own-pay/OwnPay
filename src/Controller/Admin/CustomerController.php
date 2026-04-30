<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\System\PaginationService;

final class CustomerController
{
    private Container $c;

    public function __construct(Container $c) { $this->c = $c; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $page = max(1, (int) $req->get('page', '1'));
        $q = $req->get('q', '');

        $where = "WHERE c.merchant_id = :mid";
        $params = ['mid' => $mid];
        if ($q !== '') {
            $where .= " AND (c.name LIKE :q OR c.email LIKE :q OR c.phone LIKE :q)";
            $params['q'] = "%{$q}%";
        }

        $total = (int) ($db->fetchOne("SELECT COUNT(*) as cnt FROM op_customers c {$where}", $params)['cnt'] ?? 0);
        $pagination = PaginationService::calculate($page, $total);

        $customers = $db->fetchAll(
            "SELECT c.*, COUNT(t.id) as txn_count, COALESCE(SUM(CASE WHEN t.status='completed' THEN t.amount ELSE 0 END),0) as total_spent, t.currency
             FROM op_customers c
             LEFT JOIN op_transactions t ON t.customer_id = c.id
             {$where}
             GROUP BY c.id
             ORDER BY c.created_at DESC
             LIMIT {$pagination['limit']} OFFSET {$pagination['offset']}",
            $params
        );

        return $this->render('admin/customers.twig', [
            'customers'   => $customers,
            'filters'     => ['q' => $q],
            'pagination'  => $pagination,
            'active_page' => 'customers',
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
