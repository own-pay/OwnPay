<?php

declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\WebhookRepository;
use OwnPay\Security\LogSanitizer;
use OwnPay\Security\UrlValidator;

/**
 * WebhookService — outbound webhook delivery with HMAC signing and dedup.
 *
 * Flow:
 *   1. Record event in op_webhook_events (deduplicated)
 *   2. Find all active webhook endpoints for the merchant + event type
 *   3. For each endpoint: sign payload, send via cURL, log delivery
 *   4. Retry failed deliveries up to 3 times with exponential backoff
 */
final class WebhookService
{
    private const MAX_RETRIES = 3;
    private const BACKOFF_SECONDS = [1, 5, 25]; // exponential
    private const CONNECT_TIMEOUT = 5;
    private const REQUEST_TIMEOUT = 10;

    private WebhookRepository $repo;
    private LogSanitizer $sanitizer;

    public function __construct(?WebhookRepository $repo = null, ?LogSanitizer $sanitizer = null)
    {
        $this->repo = $repo ?? new WebhookRepository();
        $this->sanitizer = $sanitizer ?? new LogSanitizer();
    }

    /**
     * Dispatch a webhook event to all matching endpoints.
     *
     * @param int    $merchantId
     * @param string $eventType  e.g. 'payment.completed', 'refund.issued'
     * @param array  $payload    Event payload data
     * @param string $sourceIp   Request origin IP
     * @return array  Summary: ['dispatched' => int, 'succeeded' => int, 'failed' => int]
     */
    public function dispatch(
        int $merchantId,
        string $eventType,
        array $payload,
        string $sourceIp = ''
    ): array {
        // 0. Sanitize payload — strip PII before sending to merchant endpoints
        $payload = $this->sanitizer->sanitizeArray($payload);

        // 1. Record event (creates a dedupe entry)
        $eventId = $this->repo->createEvent(
            $merchantId,
            $eventType,
            json_encode($payload),
            $sourceIp
        );

        // 2. Find matching endpoints
        $endpoints = $this->repo->findEndpoints($merchantId, $eventType);

        $summary = ['dispatched' => 0, 'succeeded' => 0, 'failed' => 0];

        // 3. Deliver to each endpoint
        foreach ($endpoints as $endpoint) {
            $summary['dispatched']++;

            $success = $this->deliverWithRetry(
                $endpoint,
                $eventId,
                $eventType,
                $payload
            );

            if ($success) {
                $summary['succeeded']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * Deliver a webhook with retry logic.
     */
    private function deliverWithRetry(
        array $endpoint,
        int $eventId,
        string $eventType,
        array $payload
    ): bool {
        $url = $endpoint['url'];
        $secret = $endpoint['signing_secret'] ?? '';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            // Wait before retry (skip for first attempt)
            if ($attempt > 1) {
                sleep(self::BACKOFF_SECONDS[$attempt - 1] ?? 25);
            }

            $result = $this->sendRequest($url, $body, $secret, $eventType);

            // Log delivery attempt
            $this->repo->logDelivery(
                (int) $endpoint['id'],
                $eventId,
                $url,
                json_encode($result['requestHeaders']),
                $body,
                $result['httpStatus'],
                $result['responseBody'],
                $attempt,
                $result['success']
            );

            if ($result['success']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send a single webhook HTTP request with HMAC signature.
     *
     * @return array{success: bool, httpStatus: int, responseBody: string, requestHeaders: array}
     */
    private function sendRequest(
        string $url,
        string $body,
        string $secret,
        string $eventType
    ): array {
        // SSRF guard — block private/loopback/non-http targets (F5)
        $reason = null;
        if (!UrlValidator::isSafeOutbound($url, $reason)) {
            Logger::security()->warning('outbound_webhook_blocked', [
                'url'    => $url,
                'event'  => $eventType,
                'reason' => $reason,
            ]);
            return [
                'success'         => false,
                'httpStatus'      => 0,
                'responseBody'    => "Webhook URL rejected by SSRF guard: {$reason}",
                'requestHeaders'  => [],
            ];
        }

        $timestamp = time();
        $signature = '';

        if ($secret !== '') {
            $signaturePayload = "{$timestamp}.{$body}";
            $signature = hash_hmac('sha256', $signaturePayload, $secret);
        }

        $headers = [
            'Content-Type: application/json',
            'User-Agent: OwnPay-Webhook/1.0',
            "X-OP-Event: {$eventType}",
            "X-OP-Timestamp: {$timestamp}",
            'Connection: close',
        ];

        if ($signature !== '') {
            $headers[] = "X-OP-Signature: {$signature}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FORBID_REUSE => true,
        ]);

        $responseBody = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            $responseBody = "cURL error: {$error}";
        }

        return [
            'success' => $httpStatus >= 200 && $httpStatus < 300,
            'httpStatus' => $httpStatus,
            'responseBody' => (string) $responseBody,
            'requestHeaders' => $headers,
        ];
    }
}
