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
 * Refund API.
 * OWASP: Validate amount <= original, tenant-scoped.
 */
final class RefundController
{
    private Container $c;
    private RefundService $refunds;
    private EventManager $events;

    public function __construct(Container $c, RefundService $refunds, EventManager $events)
    {
        $this->c = $c;
        $this->refunds = $refunds;
        $this->events = $events;
    }

    public function create(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->jsonBody();

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
            $this->c->get(\OwnPay\Core\Logger::class)->error('Refund failed', ['error' => $e->getMessage()]);
            return Response::json(['success' => false, 'error' => 'Refund processing failed'], 500);
        }
    }

    public function show(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $refund = $this->refunds->find($mid, $id);
        if (!$refund) return Response::json(['success' => false, 'error' => 'Not found'], 404);
        return Response::json(['success' => true, 'refund' => $refund]);
    }
}
