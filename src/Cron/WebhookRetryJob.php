<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\Payment\WebhookService;

/**
 * Webhook retry job — retries failed webhook deliveries.
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
            "SELECT we.*, w.url, w.secret, w.merchant_id
             FROM op_webhook_events we
             JOIN op_webhooks w ON w.id = we.webhook_id
             WHERE we.status = 'failed'
               AND we.attempts < :max_retries
               AND we.next_retry_at <= NOW(6)
             ORDER BY we.next_retry_at ASC
             LIMIT 50",
            ['max_retries' => self::MAX_RETRIES]
        );

        $retried = 0;
        $succeeded = 0;

        foreach ($failedDeliveries as $delivery) {
            $attempts = (int) ($delivery['attempts'] ?? 0);

            $webhook = [
                'url'         => $delivery['url'],
                'secret'      => $delivery['secret'],
                'merchant_id' => $delivery['merchant_id'],
            ];

            $eventData = json_decode($delivery['payload'] ?? '{}', true) ?: [];
            $success = $this->webhookService->deliver($webhook, $delivery['event_type'] ?? '', $eventData);

            if ($success) {
                $succeeded++;
                $this->db->update(
                    "UPDATE op_webhook_events SET status = 'delivered', last_attempt_at = NOW(6), attempts = attempts + 1 WHERE id = :id",
                    ['id' => $delivery['id']]
                );
            } else {
                // Schedule next retry with backoff
                $nextBackoff = self::BACKOFF_SECONDS[$attempts] ?? end(self::BACKOFF_SECONDS);
                $this->db->update(
                    "UPDATE op_webhook_events 
                     SET attempts = :att, 
                         last_attempt_at = NOW(6), 
                         next_retry_at = DATE_ADD(NOW(6), INTERVAL :secs SECOND) 
                     WHERE id = :id",
                    ['att' => $attempts + 1, 'secs' => $nextBackoff, 'id' => $delivery['id']]
                );
            }

            $retried++;
        }

        return ['retried' => $retried, 'succeeded' => $succeeded];
    }
}
