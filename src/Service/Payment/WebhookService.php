<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\WebhookRepository;
use OwnPay\Repository\CommLogRepository;

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
     * WebhookService constructor.
     *
     * @param WebhookRepository $webhooks Webhook endpoints lookup repository.
     * @param CommLogRepository $commLog Communication logs repository.
     * @param EventManager $events System event dispatcher.
     */
    public function __construct(
        WebhookRepository $webhooks,
        CommLogRepository $commLog,
        EventManager $events
    ) {
        $this->webhooks = $webhooks;
        $this->commLog = $commLog;
        $this->events = $events;
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
        $hooks = $this->webhooks->forTenant($merchantId)->listActiveForEvent($eventType);

        foreach ($hooks as $hook) {
            $this->deliver($hook, $eventType, $payload);
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
     * @return bool True if the delivery was successful (HTTP status 200-299), false otherwise.
     */
    public function deliver(array $webhook, string $eventType, array $payload): bool
    {
        $url = $webhook['url'];

        // SSRF check
        if (!$this->isUrlSafe($url)) {
            $this->events->doAction('webhook.delivery.failed', $webhook, 'SSRF blocked');
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

        $secret = (string) ($webhook['secret'] ?? '');
        $signature = hash_hmac('sha256', $body, $secret);

        $logId = $this->commLog->log(
            $webhook['merchant_id'] ?? null,
            'webhook',
            $url,
            $eventType,
            $body,
            'internal',
            'queued'
        );

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
                    'X-Timestamp: ' . time(),
                    'X-Event: ' . $eventType,
                    'User-Agent: OwnPay-Webhook/0.1.0',
                ],
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $this->commLog->markSent((int) $logId);
                $this->events->doAction('webhook.delivery.success', $webhook, $httpCode);
                return true;
            }

            $this->commLog->markFailed((int) $logId, "HTTP {$httpCode}: {$error}");
            $this->events->doAction('webhook.delivery.failed', $webhook, "HTTP {$httpCode}");
            return false;

        } catch (\Throwable $e) {
            $this->commLog->markFailed((int) $logId, $e->getMessage());
            $this->events->doAction('webhook.delivery.failed', $webhook, $e->getMessage());
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
