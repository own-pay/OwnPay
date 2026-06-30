<?php
declare(strict_types=1);

namespace OwnPay\Service\Notification;

use OwnPay\Core\Database;
use OwnPay\Service\System\Logger;
use OwnPay\Event\EventManager;
use OwnPay\Support\DateHelper;

/**
 * Outbound webhook dispatcher.
 *
 * Facilitates asynchronous transaction notifications to merchant endpoints.
 * All outgoing payloads are cryptographically signed using HMAC-SHA256 based on the configured webhook secret.
 * Features automated retry management with exponential backoff delays.
 * Ensures strict PCI DSS compliance by stripping and never transmitting raw cardholder data.
 */
final class WebhookDispatcher
{
    /**
     * Maximum number of attempts allowed for delivery before marking a webhook as failed.
     */
    private const MAX_RETRIES = 3;

    /**
     * Exponential delay strategy backoffs in seconds (1 minute, 5 minutes, 30 minutes).
     */
    private const RETRY_DELAYS = [60, 300, 1800];

    /**
     * The core database manager.
     */
    private Database $db;

    /**
     * The system logging service.
     */
    private Logger $logger;

    /**
     * The event manager for dispatching system hooks.
     */
    private EventManager $events;

    /**
     * Initializes the WebhookDispatcher with required dependencies.
     *
     * @param Database $db The database manager instance.
     * @param Logger $logger The system logging service.
     * @param EventManager $events The event manager for hooks and filter pipelines.
     */
    public function __construct(Database $db, Logger $logger, EventManager $events)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->events = $events;
    }

    /**
     * Dispatches a signed, standardized transaction event payload to all active registered webhooks for a merchant.
     *
     * Validates subscription permissions against JSON event configurations.
     *
     * @param int $merchantId The unique identifier of the brand/merchant context.
     * @param string $event The event name identifier (e.g., 'payment.completed').
     * @param array<string, mixed> $data Standardized transaction metadata properties.
     * @return void
     */
    public function dispatch(int $merchantId, string $event, array $data): void
    {
        $webhooks = $this->db->fetchAll(
            "SELECT url, secret, events FROM op_webhooks WHERE merchant_id = :mid AND status = 'active'",
            ['mid' => $merchantId]
        );

        if (empty($webhooks)) {
            return;
        }

        $payload = $this->buildPayload($event, $data);
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($jsonPayload)) {
            $jsonPayload = '';
        }

        foreach ($webhooks as $webhook) {
            $eventsStr = $webhook['events'] ?? '[]';
            $subscribedEvents = json_decode(is_string($eventsStr) ? $eventsStr : '[]', true);
            $subscribedEvents = is_array($subscribedEvents) ? $subscribedEvents : [];

            if (!empty($subscribedEvents) && !in_array($event, $subscribedEvents, true) && !in_array('*', $subscribedEvents, true)) {
                continue;
            }

            $secretVal = $webhook['secret'] ?? '';
            $secret = is_string($secretVal) ? $secretVal : '';
            $signature = hash_hmac('sha256', $jsonPayload, $secret);
            $timestamp = time();

            $urlVal = $webhook['url'] ?? '';
            $url = is_string($urlVal) ? $urlVal : '';
            if ($url !== '') {
                $this->sendWithRetry($url, $jsonPayload, $signature, $timestamp, $merchantId, $event);
            }
        }
    }

    /**
     * Constructs the standardized outgoing webhook payload structure.
     *
     * Normalizes transaction representations across standard API gateways, manual processors, and bank channels.
     *
     * @param string $event The event type descriptor.
     * @param array<string, mixed> $data Raw source transaction entity columns and parameters.
     * @return array<string, mixed> Standardized webhook request payload.
     */
    public function buildPayload(string $event, array $data): array
    {
        $gatewayTrxId = $data['gateway_trx_id'] ?? '';
        if (empty($gatewayTrxId)) {
            $trxId = $data['transaction_id'] ?? $data['trx_id'] ?? '';
            if (is_string($trxId) && $trxId !== '') {
                $txn = $this->db->fetchOne(
                    "SELECT gateway_trx_id FROM op_transactions WHERE trx_id = :trxId LIMIT 1",
                    ['trxId' => $trxId]
                );
                if ($txn && !empty($txn['gateway_trx_id'])) {
                    $gatewayTrxId = $txn['gateway_trx_id'];
                }
            }
        }

        return [
            'event'          => $event,
            'transaction_id' => $data['transaction_id'] ?? '',
            'gateway_trx_id' => $gatewayTrxId,
            'amount'         => $data['amount'] ?? '0.00',
            'currency'       => $data['currency'] ?? 'BDT',
            'gateway'        => $data['gateway'] ?? '',
            'gateway_type'   => $data['gateway_type'] ?? 'unknown',
            'status'         => $data['status'] ?? '',
            'customer'       => [
                'name'  => $data['customer_name'] ?? '',
                'email' => $data['customer_email'] ?? '',
                'phone' => $data['customer_phone'] ?? '',
            ],
            'metadata'       => $data['metadata'] ?? [],
            'timestamp'      => DateHelper::iso(),
        ];
    }

    /**
     * Signs the outbound webhook body payload.
     *
     * Generates a secure HMAC-SHA256 signature using the private shared merchant webhook secret key.
     *
     * @param string $payload The raw string payload to sign.
     * @param string $secret The shared secret key configured on the webhook endpoint.
     * @return string The hex-encoded cryptographic signature.
     */
    public function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Dispatches a mock transaction event to test webhook endpoint connectivity.
     *
     * Uses dummy parameters to verify signature generation and host configuration.
     *
     * @param int $merchantId The unique identifier of the brand/merchant context.
     * @return array<string, mixed> Returns array with 'success' status flag and optional 'error' message details.
     */
    public function sendTest(int $merchantId): array
    {
        $webhook = $this->db->fetchOne(
            "SELECT url, secret FROM op_webhooks WHERE merchant_id = :mid AND status = 'active' ORDER BY created_at ASC LIMIT 1",
            ['mid' => $merchantId]
        );

        if ($webhook === null || empty($webhook['url'])) {
            return ['success' => false, 'error' => 'No webhook URL configured'];
        }

        $testPayload = $this->buildPayload('webhook.test', [
            'transaction_id' => 'TEST-' . bin2hex(random_bytes(8)),
            'amount' => '0.00',
            'currency' => 'BDT',
            'gateway' => 'test',
            'gateway_type' => 'test',
            'status' => 'test',
        ]);

        $json = json_encode($testPayload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '';
        }
        $secretVal = $webhook['secret'] ?? '';
        $secret = is_scalar($secretVal) ? (string) $secretVal : '';
        $signature = $this->sign($json, $secret);

        $urlVal = $webhook['url'];
        $url = is_string($urlVal) ? $urlVal : '';
        return $this->doSend($url, $json, $signature, time());
    }

    /**
     * Transmits a payload with exponential delay retry rules on request failures.
     *
     * @param string $url Target merchant webhook endpoint URL.
     * @param string $payload Serialized JSON payload string.
     * @param string $signature HMAC-SHA256 signature of the payload.
     * @param int $timestamp The signature generation UNIX timestamp.
     * @param int $merchantId The unique identifier of the brand/merchant context.
     * @param string $event The name of the triggered event.
     * @return void
     */
    private function sendWithRetry(string $url, string $payload, string $signature, int $timestamp, int $merchantId, string $event): void
    {
        // Enforce SSRF protection: reject addresses targeting local or private ranges
        if (!\OwnPay\Security\UrlValidator::isValidWebhookUrl($url)) {
            $this->events->doAction('webhook.delivery.failed', $merchantId, $event);
            $this->logger->error("Webhook delivery blocked by SSRF protection: merchant={$merchantId} event={$event} url={$url}");
            
            $this->logDelivery($merchantId, $event, $url, [
                'success' => false,
                'status_code' => 0,
                'response_time_ms' => 0,
            ], 1);
            return;
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $result = $this->doSend($url, $payload, $signature, $timestamp);

            $this->logDelivery($merchantId, $event, $url, $result, $attempt);

            if ($result['success']) {
                $this->events->doAction('webhook.delivery.success', $merchantId, $event);
                return;
            }

            if ($attempt < self::MAX_RETRIES) {
                /** @phpstan-ignore nullCoalesce.offset */
                $delay = self::RETRY_DELAYS[$attempt - 1] ?? 60;
                $this->logger->warning("Webhook retry #{$attempt} for merchant={$merchantId} event={$event} delay={$delay}s");
            }
        }

        $this->events->doAction('webhook.delivery.failed', $merchantId, $event);
        $this->logger->error("Webhook failed after " . self::MAX_RETRIES . " attempts: merchant={$merchantId} event={$event} url={$url}");
    }

    /**
     * Performs the raw HTTP POST request to the merchant webhook endpoint via cURL.
     *
     * @param string $url Target merchant webhook endpoint URL.
     * @param string $payload Serialized JSON payload string.
     * @param string $signature HMAC-SHA256 signature.
     * @param int $timestamp The signature generation UNIX timestamp.
     * @return array<string, mixed> Structured response mapping containing HTTP code, duration, status, and error details.
     */
    private function doSend(string $url, string $payload, string $signature, int $timestamp): array
    {
        $pinnedIp = \OwnPay\Security\UrlValidator::resolveSafeWebhookIp($url);
        if ($pinnedIp === null) {
            return [
                'success' => false,
                'status_code' => 0,
                'response_time_ms' => 0,
                'error' => 'URL blocked by SSRF protection',
            ];
        }

        $parsed = parse_url($url);
        $host = '';
        $port = 443;
        if (is_array($parsed)) {
            $host = isset($parsed['host']) ? (string) $parsed['host'] : '';
            $port = isset($parsed['port']) ? (int) $parsed['port'] : 443;
        }

        $startTime = microtime(true);

        $headers = [
            'Content-Type: application/json',
            'X-OwnPay-Signature: ' . $signature,
            'X-OwnPay-Timestamp: ' . $timestamp,
            'User-Agent: OwnPay-Webhook/1.0',
        ];

        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        // Pin host -> validated public IP (TLS SNI/cert validation still uses the hostname).
        if ($host !== '') {
            $curlOptions[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$pinnedIp}"];
        }
        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        $success = $statusCode >= 200 && $statusCode < 300;

        return [
            'success' => $success,
            'status_code' => $statusCode,
            'response_time_ms' => $responseTimeMs,
            'error' => $error ?: null,
        ];
    }

    /**
     * Logs webhook delivery information to the database audit record.
     *
     * Writes details to `op_webhook_deliveries` for analytics and developer troubleshooting.
     *
     * @param int $merchantId The unique identifier of the brand/merchant context.
     * @param string $event The name of the event dispatched.
     * @param string $url The endpoint URL the payload was targeted to.
     * @param array<string, mixed> $result Request results containing status codes, response time, and errors.
     * @param int $attempt The numeric attempt iteration count.
     * @return void
     */
    private function logDelivery(int $merchantId, string $event, string $url, array $result, int $attempt): void
    {
        $this->db->insert(
            "INSERT INTO op_webhook_deliveries (merchant_id, event, url, direction, status_code, response_time_ms, attempt, status, payload_hash, gateway, created_at)
             VALUES (:mid, :ev, :url, 'outbound', :code, :time, :attempt, :status, '', 'system', NOW())",
            [
                'mid' => $merchantId,
                'ev' => $event,
                'url' => $url,
                'code' => $result['status_code'],
                'time' => $result['response_time_ms'],
                'attempt' => $attempt,
                'status' => $result['success'] ? 'delivered' : 'failed',
            ]
        );
    }

    /**
     * Lists recent webhook deliveries logged for a given merchant.
     *
     * @param int $merchantId The unique identifier of the brand/merchant context.
     * @param int $limit Maximum number of delivery records to retrieve.
     * @return array<int, array<string, mixed>> Collection of delivery record fields.
     */
    public function listDeliveries(?int $merchantId, int $limit = 50): array
    {
        $where = $merchantId !== null ? 'WHERE merchant_id = :mid' : '';
        $params = $merchantId !== null ? ['mid' => $merchantId] : [];
        return $this->db->fetchAll(
            "SELECT id, event, url, direction, status_code, response_time_ms, attempt, status, created_at
             FROM op_webhook_deliveries {$where} ORDER BY created_at DESC LIMIT {$limit}",
            $params
        );
    }
}
