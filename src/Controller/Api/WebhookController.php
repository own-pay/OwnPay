<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Notification\WebhookDispatcher;

final class WebhookController
{
    private Container $c;
    private WebhookDispatcher $webhooks;
    public function __construct(Container $c, WebhookDispatcher $webhooks) { $this->c = $c; $this->webhooks = $webhooks; }

    public function test(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $result = $this->webhooks->sendTest($mid);
        return Response::json(['success' => $result['success'], 'status_code' => $result['status_code'] ?? null, 'response_time_ms' => $result['response_time_ms'] ?? null]);
    }

    public function deliveries(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $deliveries = $db->fetchAll(
            "SELECT id, event, url, status_code, response_time_ms, attempt, status, created_at FROM op_webhook_deliveries WHERE merchant_id = :mid ORDER BY created_at DESC LIMIT 50",
            ['mid' => $mid]
        );
        return Response::json(['success' => true, 'data' => $deliveries]);
    }
}
