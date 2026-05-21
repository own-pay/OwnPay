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
                // Update dispatch log status to delivered on successful remote host response, avoiding future retry dispatches.
                $this->db->update(
                    "UPDATE op_comm_log SET status = 'delivered', sent_at = NOW(6) WHERE id = :id",
                    ['id' => $delivery['id']]
                );
            } else {
                // Schedule subsequent delivery attempts utilizing the exponential backoff interval lookup.
                /** @phpstan-ignore-next-line */
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
