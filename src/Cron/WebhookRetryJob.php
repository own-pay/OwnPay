<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\Payment\WebhookService;

/**
 * Webhook retry job â€” retries failed webhook deliveries.
 *
 * Exponential backoff: 1min, 5min, 30min, 2h, 12h (max 5 attempts).
 */
final class WebhookRetryJob
{
    private WebhookService $webhookService;
    private \OwnPay\Core\Database $db;

    private const MAX_RETRIES = 5;
    private const BACKOFF_SECONDS = [60, 300, 1800, 7200, 43200];

    public function __construct(WebhookService $webhookService, \OwnPay\Core\Database $db)
    {
        $this->webhookService = $webhookService;
        $this->db = $db;
    }

    public function run(): array
    {
        $failedDeliveries = $this->db->fetchAll(
            "SELECT cl.*, we.url, we.secret, we.merchant_id
             FROM op_comm_log cl
             JOIN op_webhook_endpoints we ON we.id = cl.entity_id
             WHERE cl.channel = 'webhook'
               AND cl.status = 'failed'
               AND cl.retry_count < :max_retries
               AND cl.next_retry_at <= NOW()
             ORDER BY cl.created_at ASC
             LIMIT 50",
            ['max_retries' => self::MAX_RETRIES]
        );

        $retried = 0;
        $succeeded = 0;

        foreach ($failedDeliveries as $delivery) {
            $retryCount = (int) ($delivery['retry_count'] ?? 0);

            $webhook = [
                'url'         => $delivery['url'],
                'secret'      => $delivery['secret'],
                'merchant_id' => $delivery['merchant_id'],
            ];

            $eventData = json_decode($delivery['content'] ?? '{}', true) ?: [];
            $success = $this->webhookService->deliver($webhook, $delivery['event_type'] ?? '', $eventData);

            if ($success) {
                $succeeded++;
            } else {
                // Schedule next retry with backoff
                $nextBackoff = self::BACKOFF_SECONDS[$retryCount] ?? end(self::BACKOFF_SECONDS);
                $this->db->update(
                    "UPDATE op_comm_log SET retry_count = :rc, next_retry_at = DATE_ADD(NOW(), INTERVAL :secs SECOND) WHERE id = :id",
                    ['rc' => $retryCount + 1, 'secs' => $nextBackoff, 'id' => $delivery['id']]
                );
            }

            $retried++;
        }

        return ['retried' => $retried, 'succeeded' => $succeeded];
    }
}
