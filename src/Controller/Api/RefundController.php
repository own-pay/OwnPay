<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\RefundService;
use OwnPay\Event\EventManager;
use OwnPay\Service\System\InputSanitizer;

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
     * Constructor.
     *
     * @param Container $c The service container instance.
     * @param RefundService $refunds Service layer managing refund lifecycles.
     * @param EventManager $events The system-wide event manager.
     */
    public function __construct(Container $c, RefundService $refunds, EventManager $events)
    {
        $this->c = $c;
        $this->refunds = $refunds;
        $this->events = $events;
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
            return Response::apiSuccess($result, null, 201);
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
        $refund = $db->fetchOne(
            "SELECT r.* FROM op_refunds r
              JOIN op_transactions t ON t.id = r.transaction_id
              WHERE t.trx_id = :trx_id AND r.merchant_id = :mid
              ORDER BY r.created_at DESC LIMIT 1",
            ['trx_id' => $trxId, 'mid' => $mid]
        );

        if (!is_array($refund)) {
            return Response::apiError('REFUND_NOT_FOUND', 'Refund not found', null, 404);
        }

        $data = [
            'id'             => $refund['id'] ?? null,
            'uuid'           => $refund['uuid'] ?? null,
            'transaction_id' => $refund['transaction_id'] ?? null,
            'amount'         => $refund['amount'] ?? null,
            'reason'         => $refund['reason'] ?? null,
            'status'         => $refund['status'] ?? null,
            'processed_at'   => $refund['processed_at'] ?? null,
            'created_at'     => $refund['created_at'] ?? null,
        ];

        return Response::apiSuccess($data);
    }
}
