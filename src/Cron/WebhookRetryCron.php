<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\Payment\WebhookService;
use OwnPay\Repository\WebhookEventRepository;
use OwnPay\Core\Database;

/**
 * Class WebhookRetryCron
 *
 * Enterprise cron job executing webhook delivery retries for failed event notifications.
 *
 * @package OwnPay\Cron
 */
final class WebhookRetryCron implements CronJobInterface
{
    /**
     * @var WebhookService
     */
    private WebhookService $webhookService;

    /**
     * @var WebhookEventRepository
     */
    private WebhookEventRepository $webhookEvents;

    /**
     * @var Database
     */
    private Database $db;

    /**
     * WebhookRetryCron constructor.
     */
    public function __construct(
        WebhookService $webhookService,
        WebhookEventRepository $webhookEvents,
        Database $db
    ) {
        $this->webhookService = $webhookService;
        $this->webhookEvents = $webhookEvents;
        $this->db = $db;
    }

    /**
     * Runs the webhook retry cycle.
     *
     * @return array{retried: int, succeeded: int} Status metrics for retried and succeeded attempts.
     */
    public function run(): array
    {
        $pending = $this->webhookEvents->findPendingRetries(50);

        $retried = 0;
        $succeeded = 0;

        foreach ($pending as $event) {
            if (!isset($event['id']) || !is_scalar($event['id']) ||
                !isset($event['webhook_id']) || !is_scalar($event['webhook_id']) ||
                !isset($event['payload']) || !is_string($event['payload']) ||
                !isset($event['event_type']) || !is_string($event['event_type'])) {
                continue;
            }

            $eventId = (int)$event['id'];
            $webhookId = (int)$event['webhook_id'];

            // Fetch the parent webhook details to get the destination URL and secret
            $webhook = $this->db->fetchOne(
                "SELECT * FROM op_webhooks WHERE id = :id LIMIT 1",
                ['id' => $webhookId]
            );

            if (!is_array($webhook)) {
                continue;
            }

            $eventData = json_decode($event['payload'], true);
            if (!is_array($eventData)) {
                $eventData = [];
            }

            $success = $this->webhookService->deliver(
                $webhook,
                (string)$event['event_type'],
                $eventData,
                $eventId
            );

            if ($success) {
                $succeeded++;
            }
            $retried++;
        }

        return ['retried' => $retried, 'succeeded' => $succeeded];
    }
}
