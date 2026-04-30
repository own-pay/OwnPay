<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\System\PaginationService;

final class SmsDataController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $page = max(1, (int) $req->get('page', '1'));
        $status = $req->get('status', '');

        $where = "WHERE merchant_id = :mid";
        $params = ['mid' => $mid];
        if ($status === 'matched') { $where .= " AND transaction_id IS NOT NULL"; }
        elseif ($status === 'unmatched') { $where .= " AND transaction_id IS NULL"; }

        $total = (int) ($db->fetchOne("SELECT COUNT(*) as cnt FROM op_sms_parsed {$where}", $params)['cnt'] ?? 0);
        $pagination = PaginationService::calculate($page, $total);
        $data = $db->fetchAll("SELECT * FROM op_sms_parsed {$where} ORDER BY received_at DESC LIMIT {$pagination['limit']} OFFSET {$pagination['offset']}", $params);

        return $this->render('admin/sms-data.twig', ['sms_data' => $data, 'filters' => ['status' => $status], 'pagination' => $pagination, 'active_page' => 'sms-data']);
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? ''; $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay'; $data['current_user'] = $_SESSION['user'] ?? [];
        return Response::html($twig->render($tpl, $data));
    }
}
