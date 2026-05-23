<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Service\System\PaginationService;

/**
 * Transaction API Controller
 *
 * Exposes endpoints to search, paginate, and detail transaction records. Implements
 * brand-level tenant isolation checks and strict output field filtering.
 */
final class TransactionController
{
    /**
     * @var Container The service container instance.
     * @phpstan-ignore property.onlyWritten
     */
    private Container $c;

    /**
     * @var TransactionRepository Repository handling transaction data operations.
     */
    private TransactionRepository $txns;

    /**
     * Constructor.
     *
     * @param Container $c The service container instance.
     * @param TransactionRepository $txns Repository handling transaction data operations.
     */
    public function __construct(Container $c, TransactionRepository $txns)
    {
        $this->c = $c;
        $this->txns = $txns;
    }

    /**
     * Retrieve a filtered, paginated list of transactions.
     *
     * GET /api/v1/transactions
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response detailing the paginated transaction list.
     */
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

        // Whitelist fields to filter sensitive internal transaction data.
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
     * Lookup a single transaction by its unique reference string.
     *
     * GET /api/v1/transactions/{trx_id}
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response detailing the transaction or an error message.
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
     * Map transaction data to a safe output schema matching database definitions.
     *
     * @param array<string, mixed> $t The database transaction record array.
     * @return array<string, mixed> The filtered, safe presentation array representation.
     */
    private function safeFields(array $t): array
    {
        return [
            'id'          => $t['id'],
            'trx_id'      => $t['trx_id'],
            'amount'      => $t['amount'],
            'currency'    => $t['currency'],
            'fee'          => $t['fee'] ?? '0.00',
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
