<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\WebhookRepository;
use OwnPay\Repository\CommLogRepository;
use OwnPay\Repository\WebhookEventRepository;

/**
 * Service managing outbound webhook notifications dispatched to merchant API endpoints.
 *
 * Provides capabilities to queue, sign, and deliver transaction events. Enforces
 * SSRF domain sanitization controls and computes SHA-256 HMAC digital signatures to guarantee message integrity.
 */
final class WebhookService
{
    /**
     * @var WebhookRepository The repository storing configured merchant webhook endpoints.
     */
    private WebhookRepository $webhooks;

    /**
     * @var CommLogRepository The repository logging communication and notification attempts.
     */
    private CommLogRepository $commLog;

    /**
     * @var EventManager The system event manager.
     */
    private EventManager $events;

    /**
     * @var WebhookEventRepository The repository storing webhook events.
     */
    private WebhookEventRepository $webhookEvents;

    /**
     * WebhookService constructor.
     *
     * @param WebhookRepository $webhooks Webhook endpoints lookup repository.
     * @param CommLogRepository $commLog Communication logs repository.
     * @param EventManager $events System event dispatcher.
     * @param WebhookEventRepository $webhookEvents Webhook events persistence layer.
     */
    public function __construct(
        WebhookRepository $webhooks,
        CommLogRepository $commLog,
        EventManager $events,
        WebhookEventRepository $webhookEvents
    ) {
        $this->webhooks = $webhooks;
        $this->commLog = $commLog;
        $this->events = $events;
        $this->webhookEvents = $webhookEvents;
    }

    /**
     * Dispatches a transaction event to all configured active endpoints registered by a merchant.
     *
     * Queries matching webhooks and iterates to initiate delivery processes.
     *
     * @param int $merchantId The ID of the merchant/brand.
     * @param string $eventType The type of transaction event triggered (e.g. `payment.completed`).
     * @param array<string, mixed> $payload The structured event body fields.
     * @return void
     */
    public function dispatch(int $merchantId, string $eventType, array $payload): void
    {
        if (!isset($payload['gateway_trx_id'])) {
            $trxId = $payload['transaction_id'] ?? $payload['trx_id'] ?? $payload['id'] ?? null;
            if ($trxId && is_string($trxId)) {
                $txn = $this->webhookEvents->getDatabase()->fetchOne(
                    "SELECT gateway_trx_id FROM op_transactions WHERE trx_id = :trxId LIMIT 1",
                    ['trxId' => $trxId]
                );
                if ($txn && !empty($txn['gateway_trx_id'])) {
                    $payload['gateway_trx_id'] = $txn['gateway_trx_id'];
                }
            }
        }

        $hooks = $this->webhooks->forTenant($merchantId)->listActiveForEvent($eventType);

        foreach ($hooks as $hook) {
            $webhookId = isset($hook['id']) && is_scalar($hook['id']) ? (int)$hook['id'] : 0;
            $eventId = (int) $this->webhookEvents->create([
                'webhook_id' => $webhookId,
                'event_type' => $eventType,
                'payload'    => (string) json_encode($payload),
                'status'     => 'pending',
                'attempts'   => 0,
            ]);

            $this->deliver($hook, $eventType, $payload, $eventId);
        }
    }

    /**
     * Executes the network request to deliver a signed webhook to a single endpoint.
     *
     * Performs a preemptive SSRF validity check on the URL, calculates a sha256 HMAC signature
     * of the JSON body using the webhook secret, initializes curl, registers log entries,
     * and triggers event hooks reflecting the final response.
     *
     * @param array<string, mixed> $webhook The configuration fields of the target webhook endpoint.
     * @param string $eventType The triggered event name.
     * @param array<string, mixed> $payload The payload body parameters.
     * @param int|null $eventId Optional webhook event ID for database tracking and retries.
     * @return bool True if the delivery was successful (HTTP status 200-299), false otherwise.
     */
    public function deliver(array $webhook, string $eventType, array $payload, ?int $eventId = null): bool
    {
        $urlVal = $webhook['url'] ?? '';
        $url = is_scalar($urlVal) ? (string) $urlVal : '';

        $start = microtime(true);

        // SSRF check
        if ($url === '' || !$this->isUrlSafe($url)) {
            $this->events->doAction('webhook.delivery.failed', $webhook, 'SSRF blocked');
            if ($eventId !== null) {
                $this->webhookEvents->logDelivery($eventId, null, null, 0, 'SSRF blocked');
                $this->webhookEvents->updateRetryState($eventId, 'failed', 1, null); // SSRF block is immediately dead-lettered/quarantined
            }
            return false;
        }

        $encoded = json_encode([
            'event' => $eventType,
            'data'  => $payload,
            'timestamp' => time(),
        ]);
        if (!is_string($encoded)) {
            $encoded = '';
        }
        $body = $encoded;

        $secretVal = $webhook['secret'] ?? '';
        $secret = is_scalar($secretVal) ? (string) $secretVal : '';
        $signature = hash_hmac('sha256', $body, $secret);

        $midVal = $webhook['merchant_id'] ?? null;
        $merchantId = is_scalar($midVal) ? (int)$midVal : null;

        $logId = $this->commLog->log(
            $merchantId,
            'webhook',
            $url,
            $eventType,
            $body,
            'internal',
            'queued'
        );

        $httpCode = null;
        $response = null;
        $error = null;

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Signature: sha256=' . $signature,
                    'X-Timestamp: ' . (string) time(),
                    'X-Event: ' . $eventType,
                    'User-Agent: OwnPay-Webhook/0.1.0',
                ],
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $durationMs = (int)round((microtime(true) - $start) * 1000);

            if ($httpCode >= 200 && $httpCode < 300) {
                $this->commLog->markSent((int) $logId);
                $this->events->doAction('webhook.delivery.success', $webhook, $httpCode);
                
                if ($eventId !== null) {
                    $event = $this->webhookEvents->find($eventId);
                    $attempts = is_array($event) && isset($event['attempts']) && is_scalar($event['attempts']) ? (int) $event['attempts'] : 0;
                    
                    $this->webhookEvents->logDelivery($eventId, $httpCode, is_string($response) ? $response : null, $durationMs, null);
                    $this->webhookEvents->updateRetryState($eventId, 'delivered', $attempts + 1, null);
                }
                
                return true;
            }

            $errorMsg = $error !== '' ? $error : "HTTP status {$httpCode}";
            $this->commLog->markFailed((int) $logId, "HTTP {$httpCode}: {$errorMsg}");
            $this->events->doAction('webhook.delivery.failed', $webhook, "HTTP {$httpCode}");

            if ($eventId !== null) {
                $event = $this->webhookEvents->find($eventId);
                $attempts = is_array($event) && isset($event['attempts']) && is_scalar($event['attempts']) ? (int) $event['attempts'] : 0;
                $newAttempts = $attempts + 1;

                $this->webhookEvents->logDelivery(
                    $eventId,
                    $httpCode,
                    is_string($response) ? $response : null,
                    $durationMs,
                    "HTTP {$httpCode}: {$errorMsg}"
                );

                $intervals = [
                    1 => 300,      // 5 mins
                    2 => 900,      // 15 mins
                    3 => 3600,     // 1 hour
                    4 => 21600,    // 6 hours
                    5 => 43200,    // 12 hours
                    6 => 86400,    // 24 hours
                ];
                $seconds = $intervals[$newAttempts] ?? null;
                $nextRetry = null;
                if ($seconds !== null) {
                    $nextRetry = date('Y-m-d H:i:s', time() + $seconds);
                }

                $this->webhookEvents->updateRetryState($eventId, 'failed', $newAttempts, $nextRetry);
            }

            return false;

        } catch (\Throwable $e) {
            $durationMs = (int)round((microtime(true) - $start) * 1000);
            $this->commLog->markFailed((int) $logId, $e->getMessage());
            $this->events->doAction('webhook.delivery.failed', $webhook, $e->getMessage());

            if ($eventId !== null) {
                $event = $this->webhookEvents->find($eventId);
                $attempts = is_array($event) && isset($event['attempts']) && is_scalar($event['attempts']) ? (int) $event['attempts'] : 0;
                $newAttempts = $attempts + 1;

                $this->webhookEvents->logDelivery(
                    $eventId,
                    null,
                    null,
                    $durationMs,
                    $e->getMessage()
                );

                $intervals = [
                    1 => 300,
                    2 => 900,
                    3 => 3600,
                    4 => 21600,
                    5 => 43200,
                    6 => 86400,
                ];
                $seconds = $intervals[$newAttempts] ?? null;
                $nextRetry = null;
                if ($seconds !== null) {
                    $nextRetry = date('Y-m-d H:i:s', time() + $seconds);
                }

                $this->webhookEvents->updateRetryState($eventId, 'failed', $newAttempts, $nextRetry);
            }

            return false;
        }
    }

    /**
     * Prevents SSRF attacks by checking the webhook URL format and domain/IP destination.
     *
     * @param string $url The target delivery URL.
     * @return bool True if the target URL is approved for outgoing requests, false if blocked.
     */
    private function isUrlSafe(string $url): bool
    {
        return \OwnPay\Security\UrlValidator::isValidWebhookUrl($url);
    }
}
