<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Service\System\PaginationService;

/**
 * Transaction API — list and show transactions.
 * OWASP: Tenant-scoped queries, field whitelisting.
 */
final class TransactionController
{
    private Container $c;
    private TransactionRepository $txns;

    public function __construct(Container $c, TransactionRepository $txns)
    {
        $this->c = $c;
        $this->txns = $txns;
    }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $page = max(1, (int) $req->get('page', '1'));
        $perPage = min(100, max(1, (int) $req->get('per_page', '25')));
        $filters = [
            'status'  => $req->get('status', ''),
            'gateway' => $req->get('gateway', ''),
            'from'    => $req->get('from', ''),
            'to'      => $req->get('to', ''),
        ];

        $repo = $this->txns->forTenant($mid);
        $total = $repo->countFiltered($filters);
        $pagination = PaginationService::calculate($page, $total, $perPage);
        $transactions = $repo->listFiltered($filters, $pagination['limit'], $pagination['offset']);

        // OWASP: Whitelist output fields
        $safe = array_map(fn($t) => $this->safeFields($t), $transactions);

        return Response::json([
            'success' => true,
            'data'    => $safe,
            'meta'    => [
                'page'        => $pagination['page'],
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $pagination['total_pages'],
            ],
        ]);
    }

    public function show(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $txn = $this->txns->forTenant($mid)->findScoped($id);

        if ($txn === null) {
            return Response::json(['success' => false, 'error' => 'Transaction not found'], 404);
        }

        return Response::json(['success' => true, 'data' => $this->safeFields($txn)]);
    }

    private function safeFields(array $t): array
    {
        return [
            'id'          => $t['id'],
            'trx_id'      => $t['trx_id'],
            'amount'      => $t['amount'],
            'currency'    => $t['currency'],
            'fee'         => $t['fee'] ?? '0.00',
            'status'      => $t['status'],
            'gateway'     => $t['gateway'],
            'customer'    => ['name' => $t['customer_name'] ?? null, 'email' => $t['customer_email'] ?? null],
            'created_at'  => $t['created_at'],
            'updated_at'  => $t['updated_at'] ?? null,
        ];
    }
}
