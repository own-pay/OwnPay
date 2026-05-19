<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Notification\WebhookDispatcher;

final class WebhookController
{
    /** @phpstan-ignore property.onlyWritten */
    private Container $c;
    private WebhookDispatcher $webhooks;

    public function __construct(Container $c, WebhookDispatcher $webhooks)
    {
        $this->c = $c;
        $this->webhooks = $webhooks;
    }

    public function test(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $result = $this->webhooks->sendTest($mid);
        $response = [
            'success'          => $result['success'],
            'status_code'      => $result['status_code'] ?? null,
            'response_time_ms' => $result['response_time_ms'] ?? null,
        ];
        if (!$result['success'] && !empty($result['error'])) {
            $response['error'] = $result['error'];
        }
        return Response::json($response, $result['success'] ? 200 : 400);
    }

    public function deliveries(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $deliveries = $this->webhooks->listDeliveries($mid, 50);
        return Response::json(['success' => true, 'data' => $deliveries]);
    }
}
