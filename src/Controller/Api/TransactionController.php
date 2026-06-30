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
     */
    /** @phpstan-ignore property.onlyWritten */
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
        $midVal = $req->getAttribute('merchant_id');
        $mid = is_int($midVal) || is_string($midVal) ? (int)$midVal : 0;
        $pageVal = $req->query('page', '1');
        $page = max(1, is_int($pageVal) || is_string($pageVal) ? (int)$pageVal : 1);
        $perPageVal = $req->query('per_page', '25');
        $perPage = min(100, max(1, is_int($perPageVal) || is_string($perPageVal) ? (int)$perPageVal : 25));

        $statusVal = $req->query('status', '');
        $gatewayVal = $req->query('gateway', '');
        $fromVal = $req->query('from', '');
        $toVal = $req->query('to', '');

        /** @var array{status?: string, gateway?: string, q?: string, date_from?: string, date_to?: string} $filters */
        $filters = [];
        if (is_string($statusVal) && $statusVal !== '') {
            $filters['status'] = $statusVal;
        }
        if (is_string($gatewayVal) && $gatewayVal !== '') {
            $filters['gateway'] = $gatewayVal;
        }
        if (is_string($fromVal) && $fromVal !== '') {
            $filters['date_from'] = $fromVal;
        }
        if (is_string($toVal) && $toVal !== '') {
            $filters['date_to'] = $toVal;
        }

        $repo = $this->txns->forTenant($mid);
        $total = $repo->countFiltered($filters);
        $pagination = PaginationService::calculate($page, $perPage, $total);
        $transactions = $repo->listFiltered($filters, $pagination['per_page'], $pagination['offset']);

        // Whitelist fields to filter sensitive internal transaction data.
        $safe = array_map(fn($t) => $this->safeFields($t), $transactions);

        return Response::apiSuccess($safe, [
            'page'        => $pagination['page'],
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $pagination['total_pages'],
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
        $trxIdVal = $req->param('trx_id');
        $trxId = trim($trxIdVal);
        $midVal = $req->getAttribute('merchant_id');
        $mid = is_int($midVal) || is_string($midVal) ? (int)$midVal : 0;

        if ($trxId === '') {
            return Response::apiError('TRANSACTION_ID_REQUIRED', 'Transaction ID required', 'trx_id', 422);
        }

        $txn = $this->txns->forTenant($mid)->findByTrxId($trxId);
        if ($txn === null) {
            $txn = $this->txns->forTenant($mid)->findByGatewayTrxId($trxId);
        }

        if ($txn === null) {
            if (str_starts_with($trxId, 'OP-') || str_starts_with($trxId, 'OP_')) {
                return Response::apiError('TRANSACTION_NOT_FOUND', 'Transaction not found', null, 404);
            } else {
                return Response::apiError('TRANSACTION_NOT_FOUND', 'Transaction not found using the gateway transaction ID. It may be an incomplete, pending, or failed payment. Try querying with the OwnPay transaction ID.', null, 404);
            }
        }

        return Response::apiSuccess($this->safeFields($txn));
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
            'gateway_trx_id' => $t['gateway_trx_id'] ?? null,
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
