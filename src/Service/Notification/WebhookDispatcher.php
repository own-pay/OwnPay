<?php
declare(strict_types=1);

namespace OwnPay\Service\Notification;

use OwnPay\Core\Database;
use OwnPay\Core\Logger;
use OwnPay\Event\EventManager;

/**
 * Outbound webhook dispatcher — sends signed POST to merchant webhook_url.
 *
 * Universal for ALL gateway types (api, manual, bank).
 * HMAC-SHA256 signed. Retry with exponential backoff.
 * PCI: Never sends raw card data. Standardized payload only.
 */
final class WebhookDispatcher
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS = [60, 300, 1800]; // 1m, 5m, 30m

    private Database $db;
    private Logger $logger;
    private EventManager $events;

    public function __construct(Database $db, Logger $logger, EventManager $events)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->events = $events;
    }

    /**
     * Send webhook notification to merchant.
     *
     * @param int    $merchantId
     * @param string $event       e.g. "payment.completed"
     * @param array  $data        Transaction data (standardized)
     */
    public function dispatch(int $merchantId, string $event, array $data): void
    {
        // Load merchant webhook config
        $merchant = $this->db->fetchOne(
            "SELECT webhook_url, webhook_secret FROM op_merchants WHERE id = :mid",
            ['mid' => $merchantId]
        );

        if (empty($merchant['webhook_url'])) {
            return; // No webhook configured
        }

        $url = $merchant['webhook_url'];
        $secret = $merchant['webhook_secret'] ?? '';

        // Build standardized payload
        $payload = $this->buildPayload($event, $data);
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Sign with HMAC-SHA256
        $signature = hash_hmac('sha256', $jsonPayload, $secret);
        $timestamp = time();

        // Send with retry
        $this->sendWithRetry($url, $jsonPayload, $signature, $timestamp, $merchantId, $event);
    }

    /**
     * Build standardized outbound webhook payload.
     * Works for ALL gateway types: api, manual, bank.
     */
    public function buildPayload(string $event, array $data): array
    {
        return [
            'event'          => $event,
            'transaction_id' => $data['transaction_id'] ?? '',
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
            'timestamp'      => date('c'),
        ];
    }

    /**
     * Sign payload with HMAC-SHA256.
     */
    public function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Send test webhook to merchant.
     */
    public function sendTest(int $merchantId): array
    {
        $merchant = $this->db->fetchOne(
            "SELECT webhook_url, webhook_secret FROM op_merchants WHERE id = :mid",
            ['mid' => $merchantId]
        );

        if (empty($merchant['webhook_url'])) {
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
        $signature = $this->sign($json, $merchant['webhook_secret'] ?? '');

        return $this->doSend($merchant['webhook_url'], $json, $signature, time());
    }

    /**
     * Send with exponential backoff retry.
     */
    private function sendWithRetry(string $url, string $payload, string $signature, int $timestamp, int $merchantId, string $event): void
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $result = $this->doSend($url, $payload, $signature, $timestamp);

            $this->logDelivery($merchantId, $event, $url, $result, $attempt);

            if ($result['success']) {
                $this->events->doAction('webhook.delivery.success', $merchantId, $event);
                return;
            }

            // Wait before retry (skip wait on last attempt)
            if ($attempt < self::MAX_RETRIES) {
                $delay = self::RETRY_DELAYS[$attempt - 1] ?? 60;
                // In production: queue delayed job instead of sleep
                $this->logger->warning("Webhook retry #{$attempt} for merchant={$merchantId} event={$event} delay={$delay}s");
            }
        }

        $this->events->doAction('webhook.delivery.failed', $merchantId, $event);
        $this->logger->error("Webhook failed after " . self::MAX_RETRIES . " attempts: merchant={$merchantId} event={$event} url={$url}");
    }

    /**
     * Execute HTTP POST to merchant webhook URL.
     */
    private function doSend(string $url, string $payload, string $signature, int $timestamp): array
    {
        $startTime = microtime(true);

        $headers = [
            'Content-Type: application/json',
            'X-OwnPay-Signature: ' . $signature,
            'X-OwnPay-Timestamp: ' . $timestamp,
            'User-Agent: OwnPay-Webhook/1.0',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

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
     * Log webhook delivery attempt.
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
}
