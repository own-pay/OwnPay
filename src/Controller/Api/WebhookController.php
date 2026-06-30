<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Notification\WebhookDispatcher;

/**
 * Webhook API Controller
 *
 * Exposes endpoints for managing, dispatching, and reviewing merchant webhook
 * notifications and delivery histories.
 */
final class WebhookController
{
    /**
     * @var Container The service container instance.
     */
    /** @phpstan-ignore property.onlyWritten */
    private Container $c;

    /**
     * @var WebhookDispatcher Service handling outbound webhook notifications.
     */
    private WebhookDispatcher $webhooks;

    /**
     * Constructor.
     *
     * @param Container $c The service container instance.
     * @param WebhookDispatcher $webhooks Service handling outbound webhook notifications.
     */
    public function __construct(Container $c, WebhookDispatcher $webhooks)
    {
        $this->c = $c;
        $this->webhooks = $webhooks;
    }

    /**
     * Dispatch a test webhook payload to the configured merchant endpoint.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response detailing the outcome of the dispatch test.
     */
    public function test(Request $req): Response
    {
        $midVal = $req->getAttribute('merchant_id');
        $mid = is_int($midVal) || is_string($midVal) ? (int)$midVal : 0;
        $result = $this->webhooks->sendTest($mid);
        $success = isset($result['success']) && $result['success'] === true;
        
        $data = [
            'status_code'      => $result['status_code'] ?? null,
            'response_time_ms' => $result['response_time_ms'] ?? null,
        ];

        if ($success) {
            return Response::apiSuccess($data);
        } else {
            $err = $result['error'] ?? 'Webhook test failed';
            $errStr = is_string($err) ? $err : 'Webhook test failed';
            return Response::apiError('WEBHOOK_TEST_FAILED', $errStr, null, 400);
        }
    }

    /**
     * Retrieve the recent webhook delivery history for the merchant.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response listing recent webhook delivery attempts.
     */
    public function deliveries(Request $req): Response
    {
        $midVal = $req->getAttribute('merchant_id');
        $mid = is_int($midVal) || is_string($midVal) ? (int)$midVal : 0;
        $deliveries = $this->webhooks->listDeliveries($mid, 50);
        return Response::apiSuccess($deliveries);
    }
}
