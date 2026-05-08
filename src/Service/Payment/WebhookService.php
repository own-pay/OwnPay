<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\WebhookRepository;
use OwnPay\Repository\CommLogRepository;

/**
 * Webhook service â€” dispatches outbound webhooks to merchant endpoints.
 *
 * Fires: webhook.delivery.success, webhook.delivery.failed
 * Per security skill: HMAC signing, timeout, no private IPs.
 */
final class WebhookService
{
    private WebhookRepository $webhooks;
    private CommLogRepository $commLog;
    private EventManager $events;

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
     * Dispatch event to all matching merchant webhooks.
     */
    public function dispatch(int $merchantId, string $eventType, array $payload): void
    {
        $hooks = $this->webhooks->forTenant($merchantId)->listActiveForEvent($eventType);

        foreach ($hooks as $hook) {
            $this->deliver($hook, $eventType, $payload);
        }
    }

    /**
     * Deliver single webhook with HMAC signature.
     */
    public function deliver(array $webhook, string $eventType, array $payload): bool
    {
        $url = $webhook['url'];

        // SSRF check
        if (!$this->isUrlSafe($url)) {
            $this->events->doAction('webhook.delivery.failed', $webhook, 'SSRF blocked');
            return false;
        }

        $body = json_encode([
            'event' => $eventType,
            'data'  => $payload,
            'timestamp' => time(),
        ]);

        $signature = hash_hmac('sha256', $body, $webhook['secret']);

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
     * Basic SSRF prevention for webhook URLs.
     */
    private function isUrlSafe(string $url): bool
    {
        return \OwnPay\Security\UrlValidator::isValidWebhookUrl($url);
    }
}
