<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Http\JsonResponse;
use OwnPay\Middleware\BearerAuthMiddleware;
use OwnPay\Repository\WebhookRepository;
use OwnPay\Service\AuditLogger;

/**
 * POST   /v1/webhooks        — Register a webhook endpoint
 * GET    /v1/webhooks         — List webhook endpoints
 * DELETE /v1/webhooks/{id}    — Delete a webhook endpoint
 */
final class WebhookController
{
    private WebhookRepository $webhooks;
    private AuditLogger $audit;

    public function __construct()
    {
        $this->webhooks = new WebhookRepository();
        $this->audit = new AuditLogger();
    }

    /**
     * POST /v1/webhooks
     */
    public function create(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('manage_webhooks');

        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        $url = $body['url'] ?? null;
        $events = $body['events'] ?? [];

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            JsonResponse::error('INVALID_URL', 'A valid "url" field is required.', 400);
            return;
        }

        if (empty($events) || !is_array($events)) {
            JsonResponse::error('INVALID_EVENTS', 'The "events" field must be a non-empty array.', 400);
            return;
        }

        // Generate a signing secret for this endpoint
        $signingSecret = 'whsec_' . bin2hex(random_bytes(24));

        $id = $this->webhooks->insert([
            'merchant_id' => $merchant['merchant_id'],
            'url' => $url,
            'events' => json_encode($events),
            'signing_secret' => $signingSecret,
            'status' => 'active',
        ]);

        $webhook = $this->webhooks->findById($id);

        $this->audit->log(
            $merchant['merchant_id'],
            'webhook.created',
            'webhook',
            $webhook['public_id'],
            'api_key',
            $merchant['key_prefix'],
            null,
            ['url' => $url, 'events' => $events]
        );

        JsonResponse::created([
            'id' => $webhook['public_id'],
            'url' => $url,
            'events' => $events,
            'signing_secret' => $signingSecret, // Shown ONCE
            'status' => 'active',
            'created_at' => $webhook['created_at'],
            '_warning' => 'Store the signing_secret securely. It will not be shown again.',
        ]);
    }

    /**
     * GET /v1/webhooks
     */
    public function index(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('manage_webhooks');

        $rows = $this->webhooks->findWhere(
            '`merchant_id` = :mid',
            ['mid' => $merchant['merchant_id']],
            'created_at DESC'
        );

        $items = array_map(fn(array $row) => [
            'id' => $row['public_id'],
            'url' => $row['url'],
            'events' => json_decode($row['events'] ?? '[]', true),
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            // signing_secret is never returned after creation
        ], $rows);

        JsonResponse::success($items);
    }

    /**
     * DELETE /v1/webhooks/{id}
     */
    public function destroy(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('manage_webhooks');

        $publicId = $params['id'] ?? '';
        $webhook = $this->webhooks->findByPublicId($publicId);

        if ($webhook === null || (int) $webhook['merchant_id'] !== $merchant['merchant_id']) {
            JsonResponse::error('NOT_FOUND', 'Webhook endpoint not found.', 404);
            return;
        }

        $this->webhooks->delete((int) $webhook['id']);

        $this->audit->log(
            $merchant['merchant_id'],
            'webhook.deleted',
            'webhook',
            $publicId,
            'api_key',
            $merchant['key_prefix'],
            ['url' => $webhook['url']],
            null
        );

        JsonResponse::noContent();
    }
}
