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
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->json();

        if (empty($body['transaction_id'])) {
            return Response::json(['success' => false, 'error' => 'transaction_id required'], 422);
        }

        try {
            $result = $this->refunds->create($mid, [
                'transaction_id' => (int) $body['transaction_id'],
                'amount'         => isset($body['amount']) ? InputSanitizer::decimal($body['amount']) : null,
                'reason'         => InputSanitizer::string($body['reason'] ?? ''),
            ]);
            $this->events->doAction('refund.created', $result);
            return Response::json(['success' => true, 'refund' => $result], 201);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->c->get(\OwnPay\Service\System\Logger::class)->error('Refund failed', ['error' => $e->getMessage()]);
            return Response::json(['success' => false, 'error' => 'Refund processing failed'], 500);
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
        $trxId = trim($req->param('trx_id'));
        $mid = (int) $req->getAttribute('merchant_id');

        if ($trxId === '') {
            return Response::json(['success' => false, 'error' => 'Transaction ID required'], 422);
        }

        // Query transaction record context to resolve matching refund attributes.
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $refund = $db->fetchOne(
            "SELECT r.* FROM op_refunds r
             JOIN op_transactions t ON t.id = r.transaction_id
             WHERE t.trx_id = :trx_id AND r.merchant_id = :mid
             ORDER BY r.created_at DESC LIMIT 1",
            ['trx_id' => $trxId, 'mid' => $mid]
        );

        if (!$refund) {
            return Response::json(['success' => false, 'error' => 'Refund not found'], 404);
        }

        return Response::json(['success' => true, 'refund' => [
            'id'             => $refund['id'],
            'uuid'           => $refund['uuid'],
            'transaction_id' => $refund['transaction_id'],
            'amount'         => $refund['amount'],
            'reason'         => $refund['reason'] ?? null,
            'status'         => $refund['status'],
            'processed_at'   => $refund['processed_at'] ?? null,
            'created_at'     => $refund['created_at'],
        ]]);
    }
}
