<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\RefundService;
use OwnPay\Event\EventManager;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Repository\RefundRepository;
use OwnPay\Service\System\PaginationService;

/**
 * Refund API Controller
 *
 * Exposes endpoints to initiate and query transaction refunds. Enforces validation
 * checks ensuring refund parameters align with original transaction constraints.
 */
final class RefundController
{
    /**
     * @var Container The service container instance.
     */
    private Container $c;

    /**
     * @var RefundService Service layer managing refund lifecycles.
     */
    private RefundService $refunds;

    /**
     * @var EventManager The system-wide event manager.
     */
    private EventManager $events;

    /**
     * @var RefundRepository The refund data repository.
     */
    private RefundRepository $repo;

    /**
     * Constructor.
     *
     * @param Container $c The service container instance.
     * @param RefundService $refunds Service layer managing refund lifecycles.
     * @param EventManager $events The system-wide event manager.
     * @param RefundRepository $repo The refund data repository.
     */
    public function __construct(Container $c, RefundService $refunds, EventManager $events, RefundRepository $repo)
    {
        $this->c = $c;
        $this->refunds = $refunds;
        $this->events = $events;
        $this->repo = $repo;
    }

    /**
     * Request a refund for a specific transaction.
     *
     * POST /api/v1/refunds
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response detailing refund creation.
     */
    public function create(Request $req): Response
    {
        $midVal = $req->getAttribute('merchant_id');
        $mid = is_int($midVal) || is_string($midVal) ? (int)$midVal : 0;
        $body = $req->json();
        if (!is_array($body)) {
            $body = [];
        }

        $trxIdInput = $body['trx_id'] ?? $body['transaction_id'] ?? null;
        if ($trxIdInput === null || (!is_int($trxIdInput) && !is_string($trxIdInput) && !is_float($trxIdInput)) || trim((string)$trxIdInput) === '') {
            return Response::apiError('TRANSACTION_ID_REQUIRED', 'transaction_id or trx_id is required', 'transaction_id', 422);
        }

        $trxIdentifier = trim((string)$trxIdInput);

        $db = $this->c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return Response::apiError('DATABASE_UNAVAILABLE', 'Database service unavailable', null, 500);
        }

        $txn = null;
        if (ctype_digit($trxIdentifier)) {
            $txn = $db->fetchOne(
                "SELECT id FROM op_transactions WHERE id = :id AND merchant_id = :mid",
                ['id' => (int)$trxIdentifier, 'mid' => $mid]
            );
        }
        if (!$txn) {
            $txn = $db->fetchOne(
                "SELECT id FROM op_transactions WHERE trx_id = :trx_id AND merchant_id = :mid",
                ['trx_id' => $trxIdentifier, 'mid' => $mid]
            );
        }

        if (!is_array($txn) || !isset($txn['id']) || !is_scalar($txn['id'])) {
            return Response::apiError('TRANSACTION_NOT_FOUND', 'Transaction not found', 'transaction_id', 404);
        }

        $transactionId = (int)$txn['id'];

        $amountVal = $body['amount'] ?? null;
        $reasonVal = $body['reason'] ?? null;

        $amountStr = (is_string($amountVal) || is_numeric($amountVal)) ? (string) $amountVal : '';
        $reasonStr = is_string($reasonVal) ? $reasonVal : '';

        try {
            $result = $this->refunds->create($mid, [
                'transaction_id' => $transactionId,
                'amount'         => ($amountStr !== '') ? InputSanitizer::decimal($amountStr) : null,
                'reason'         => InputSanitizer::string($reasonStr),
            ]);
            $this->events->doAction('refund.created', $result);
            return Response::apiSuccess($this->safeFields($result), null, 201);
        } catch (\InvalidArgumentException $e) {
            return Response::apiError('INVALID_REFUND_PARAMETERS', $e->getMessage(), null, 400);
        } catch (\Throwable $e) {
            $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
            if ($logger instanceof \OwnPay\Service\System\Logger) {
                $logger->error('Refund failed', ['error' => $e->getMessage()]);
            }
            return Response::apiError('REFUND_PROCESSING_FAILED', 'Refund processing failed', null, 500);
        }
    }

    /**
     * Lookup a single refund using the original transaction reference string.
     *
     * GET /api/v1/refunds/{trx_id}
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response detailing the refund or an error message.
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

        $db = $this->c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return Response::apiError('DATABASE_UNAVAILABLE', 'Database service unavailable', null, 500);
        }
        $txn = $db->fetchOne(
            "SELECT id FROM op_transactions 
             WHERE (trx_id = :trx_id OR gateway_trx_id = :gw_trx_id) AND merchant_id = :mid 
             LIMIT 1",
            ['trx_id' => $trxId, 'gw_trx_id' => $trxId, 'mid' => $mid]
        );

        if (!is_array($txn) || !isset($txn['id'])) {
            if (str_starts_with($trxId, 'OP-') || str_starts_with($trxId, 'OP_')) {
                return Response::apiError('TRANSACTION_NOT_FOUND', 'Transaction not found', null, 404);
            } else {
                return Response::apiError('TRANSACTION_NOT_FOUND', 'Transaction not found using the gateway transaction ID. It may be an incomplete, pending, or failed payment. Try querying with the OwnPay transaction ID.', null, 404);
            }
        }

        $transactionId = is_int($txn['id']) || is_string($txn['id']) ? (int)$txn['id'] : 0;

        $refund = $db->fetchOne(
            "SELECT r.*, t.trx_id FROM op_refunds r
             LEFT JOIN op_transactions t ON t.id = r.transaction_id
             WHERE r.transaction_id = :txn_id AND r.merchant_id = :mid
             ORDER BY r.created_at DESC LIMIT 1",
            ['txn_id' => $transactionId, 'mid' => $mid]
        );

        if (!is_array($refund)) {
            return Response::apiError('REFUND_NOT_FOUND', 'Refund not found', null, 404);
        }

        return Response::apiSuccess($this->safeFields($refund));
    }

    /**
     * Retrieve a filtered, paginated list of refunds.
     *
     * GET /api/v1/refunds
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response detailing the paginated refund list.
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
        $trxIdVal = $req->query('trx_id', '');
        $txnIdVal = $req->query('transaction_id', '');
        $fromVal = $req->query('from', '');
        $toVal = $req->query('to', '');

        /** @var array{status?: string, trx_id?: string, transaction_id?: int|string, date_from?: string, date_to?: string} $filters */
        $filters = [];
        if (is_string($statusVal) && $statusVal !== '') {
            $filters['status'] = $statusVal;
        }
        if (is_string($trxIdVal) && $trxIdVal !== '') {
            $filters['trx_id'] = $trxIdVal;
        }
        if ((is_int($txnIdVal) || is_string($txnIdVal)) && (string)$txnIdVal !== '') {
            $filters['transaction_id'] = $txnIdVal;
        }
        if (is_string($fromVal) && $fromVal !== '') {
            $filters['date_from'] = $fromVal;
        }
        if (is_string($toVal) && $toVal !== '') {
            $filters['date_to'] = $toVal;
        }

        $scopedRepo = $this->repo->forTenant($mid);
        $total = $scopedRepo->countFiltered($filters);

        $pagination = PaginationService::calculate($page, $perPage, $total);
        $refunds = $scopedRepo->listFiltered($filters, $pagination['per_page'], $pagination['offset']);

        $safe = array_map(fn($r) => $this->safeFields($r), $refunds);

        return Response::apiSuccess($safe, [
            'page'        => $pagination['page'],
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $pagination['total_pages'],
        ]);
    }

    /**
     * Map refund data to a safe output schema.
     *
     * @param array<string, mixed> $r The database refund record array.
     * @return array<string, mixed> The safe representation.
     */
    private function safeFields(array $r): array
    {
        return [
            'id'             => $r['id'] ?? null,
            'uuid'           => $r['uuid'] ?? null,
            'transaction_id' => $r['transaction_id'] ?? null,
            'trx_id'         => $r['trx_id'] ?? null,
            'gateway_trx_id' => $r['gateway_trx_id'] ?? null,
            'amount'         => $r['amount'] ?? null,
            'reason'         => $r['reason'] ?? null,
            'status'         => $r['status'] ?? null,
            'processed_at'   => $r['processed_at'] ?? null,
            'created_at'     => $r['created_at'] ?? null,
        ];
    }
}
