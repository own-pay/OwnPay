<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Service\System\PaginationService;
use OwnPay\Event\EventManager;

final class TransactionController
{
    private Container $c;
    private TransactionRepository $txns;
    private EventManager $events;

    public function __construct(Container $c, TransactionRepository $txns, EventManager $events)
    {
        $this->c = $c;
        $this->txns = $txns;
        $this->events = $events;
    }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $page = max(1, (int) $req->get('page', '1'));
        $filters = [
            'q'       => $req->get('q', ''),
            'status'  => $req->get('status', ''),
            'gateway' => $req->get('gateway', ''),
        ];

        $repo = $this->txns->forTenant($mid);
        $total = $repo->countFiltered($filters);
        $pagination = PaginationService::calculate($page, $total, 25);
        $transactions = $repo->listFiltered($filters, $pagination['limit'], $pagination['offset']);

        $db = $this->c->get(\OwnPay\Core\Database::class);
        $gateways = $db->fetchAll("SELECT DISTINCT slug, name FROM op_manual_gateways WHERE merchant_id = :mid UNION SELECT DISTINCT slug, name FROM op_gateway_configs gc JOIN op_gateways g ON g.id = gc.gateway_id WHERE gc.merchant_id = :mid", ['mid' => $mid]);

        return $this->render('admin/transactions/index.twig', [
            'transactions' => $transactions,
            'pagination'   => $pagination,
            'filters'      => $filters,
            'gateways'     => $gateways,
            'active_page'  => 'transactions',
        ]);
    }

    public function show(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $txn = $this->txns->forTenant($mid)->findScoped($id);

        if ($txn === null) {
            $_SESSION['flash_error'] = 'Transaction not found';
            return Response::redirect('/admin/transactions');
        }

        $db = $this->c->get(\OwnPay\Core\Database::class);
        $smsData = $db->fetchAll("SELECT * FROM op_sms_parsed WHERE transaction_id = :tid", ['tid' => $id]);
        $auditLog = $db->fetchAll("SELECT * FROM op_audit_log WHERE entity_type = 'transaction' AND entity_id = :eid ORDER BY created_at DESC", ['eid' => $id]);

        return $this->render('admin/transactions/edit.twig', [
            'txn'        => $txn,
            'sms_data'   => $smsData,
            'audit_log'  => $auditLog,
            'active_page' => 'transactions',
        ]);
    }

    public function updateStatus(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $newStatus = $req->post('status', '');

        if (!in_array($newStatus, ['completed', 'canceled', 'refunded'], true)) {
            $_SESSION['flash_error'] = 'Invalid status';
            return Response::redirect("/admin/transactions/{$id}");
        }

        $txn = $this->txns->forTenant($mid)->findScoped($id);
        if ($txn === null) {
            $_SESSION['flash_error'] = 'Transaction not found';
            return Response::redirect('/admin/transactions');
        }

        $this->events->doAction('transaction.status.before', $txn, $newStatus);
        $this->txns->forTenant($mid)->updateScoped($id, ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->events->doAction('transaction.status.changed', array_merge($txn, ['status' => $newStatus]));

        $_SESSION['flash_success'] = "Transaction marked {$newStatus}";
        return Response::redirect("/admin/transactions/{$id}");
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? '';
        $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay';
        $data['current_user'] = $_SESSION['user'] ?? [];
        $data['flash_success'] = $_SESSION['flash_success'] ?? null;
        $data['flash_error'] = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
        return Response::html($twig->render($tpl, $data));
    }
}
