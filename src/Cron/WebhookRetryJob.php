<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\Payment\WebhookService;

/**
 * Class WebhookRetryJob
 *
 * Enterprise cron job executing webhook delivery retries for failed event notifications.
 * Processes communication log entries in the `op_comm_log` table matched with endpoint configurations
 * in the `op_webhook_endpoints` table, utilizing exponential backoff retry algorithms to guarantee delivery integrity.
 *
 * @package OwnPay\Cron
 */
final class WebhookRetryJob
{
    /**
     * @var WebhookService The WebhookService responsible for signing and dispatching webhooks.
     */
    private WebhookService $webhookService;

    /**
     * @var \OwnPay\Core\Database The database connection instance.
     */
    private \OwnPay\Core\Database $db;

    /**
     * Maximum retry attempts before marking the webhook dispatch permanently failed.
     */
    private const MAX_RETRIES = 5;

    /**
     * Exponential backoff interval sequences in seconds.
     *
     * @var array<int, int>
     */
    private const BACKOFF_SECONDS = [60, 300, 1800, 7200, 43200];

    /**
     * WebhookRetryJob constructor.
     *
     * @param WebhookService        $webhookService The WebhookService responsible for signing and dispatching webhooks.
     * @param \OwnPay\Core\Database $db             The database connection instance.
     */
    public function __construct(WebhookService $webhookService, \OwnPay\Core\Database $db)
    {
        $this->webhookService = $webhookService;
        $this->db = $db;
    }

    /**
     * Runs the webhook retry cycle.
     *
     * Selects up to 50 failed webhook records due for retry, attempts payload signature validation
     * and HTTP delivery, and updates transaction communication log statuses or schedules backoffs.
     *
     * @return array{retried: int, succeeded: int} Status metrics for retried and succeeded attempts.
     */
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
                $nextBackoff = self::BACKOFF_SECONDS[$attempts] ?? self::BACKOFF_SECONDS[count(self::BACKOFF_SECONDS) - 1];
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
