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
    /** @phpstan-ignore property.onlyWritten */
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
        $page = max(1, (int) $req->query('page', '1'));
        $perPage = min(100, max(1, (int) $req->query('per_page', '25')));
        $filters = [
            'status'  => $req->query('status', ''),
            'gateway' => $req->query('gateway', ''),
            'from'    => $req->query('from', ''),
            'to'      => $req->query('to', ''),
        ];

        $repo = $this->txns->forTenant($mid);
        $total = $repo->countFiltered($filters);
        $pagination = PaginationService::calculate($page, $perPage, $total);
        /** @phpstan-ignore-next-line */
        $transactions = $repo->listFiltered($filters, $pagination['per_page'], $pagination['offset']);

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

    /**
     * GET /api/v1/transactions/{trx_id}
     * Lookup by TXN-XXXX format, NOT database ID.
     */
    public function show(Request $req): Response
    {
        $trxId = trim($req->param('trx_id'));
        $mid = (int) $req->getAttribute('merchant_id');

        if ($trxId === '') {
            return Response::json(['success' => false, 'error' => 'Transaction ID required'], 422);
        }

        $txn = $this->txns->forTenant($mid)->findByTrxId($trxId);

        if ($txn === null) {
            return Response::json(['success' => false, 'error' => 'Transaction not found'], 404);
        }

        return Response::json(['success' => true, 'data' => $this->safeFields($txn)]);
    }

    /**
     * Whitelist output fields — maps actual DB columns.
     * op_transactions has gateway_slug (not gateway), no customer_name/email.
     */
    private function safeFields(array $t): array
    {
        return [
            'id'          => $t['id'],
            'trx_id'      => $t['trx_id'],
            'amount'      => $t['amount'],
            'currency'    => $t['currency'],
            'fee'         => $t['fee'] ?? '0.00',
            'net_amount'  => $t['net_amount'] ?? null,
            'status'      => $t['status'],
            'gateway'     => $t['gateway_slug'] ?? null,
            'method'      => $t['method'] ?? null,
            'reference'   => $t['reference'] ?? null,
            'created_at'  => $t['created_at'],
            'updated_at'  => $t['updated_at'] ?? null,
        ];
    }
}
