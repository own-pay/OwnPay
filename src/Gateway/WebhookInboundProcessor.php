<?php

declare(strict_types=1);

namespace OwnPay\Gateway;

use OwnPay\Core\Database;
use OwnPay\Service\Payment\PaymentService;
use OwnPay\Service\System\AuditLogger;
use OwnPay\Repository\WebhookEventRepository;
use OwnPay\Repository\WebhookEndpointRepository;
use RuntimeException;

/**
 * WebhookInboundProcessor — secure inbound webhook handler.
 *
 * Validates HMAC signatures, deduplicates events, and routes
 * payment status updates through the PaymentService state machine.
 *
 * Webhook headers (outbound from gateway → our system):
 *   X-OP-Signature:  HMAC-SHA256 of "{timestamp}.{body}"
 *   X-OP-Timestamp:  Unix timestamp
 *   X-OP-Event-Id:   Unique event UUID for deduplication
 *   X-OP-Event:      Event type (e.g. "payment.completed")
 */
final class WebhookInboundProcessor
{
    private const MAX_TIMESTAMP_SKEW = 300; // 5 minutes

    private Database $db;
    private PaymentService $paymentService;
    private WebhookEventRepository $webhookEvents;
    private AuditLogger $audit;

    public function __construct(
        ?Database $db = null,
        ?PaymentService $paymentService = null,
        ?WebhookEventRepository $webhookEvents = null,
        ?AuditLogger $audit = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->paymentService = $paymentService ?? new PaymentService();
        $this->webhookEvents = $webhookEvents ?? new WebhookEventRepository();
        $this->audit = $audit ?? new AuditLogger();
    }

    /**
     * Process an inbound webhook request.
     *
     * @param string $rawBody     Raw request body (php://input)
     * @param array  $headers     Request headers (normalized keys)
     * @param string $secret      Webhook signing secret for this endpoint
     * @param int    $merchantId  Merchant ID
     * @return array ['accepted' => bool, 'message' => string, 'event_id' => string]
     */
    public function process(
        string $rawBody,
        array $headers,
        string $secret,
        int $merchantId
    ): array {
        // 1. Extract headers
        $signature = $headers['x-op-signature'] ?? $headers['X-OP-Signature'] ?? '';
        $timestamp = $headers['x-op-timestamp'] ?? $headers['X-OP-Timestamp'] ?? '';
        $eventId = $headers['x-op-event-id'] ?? $headers['X-OP-Event-Id'] ?? '';
        $eventType = $headers['x-op-event'] ?? $headers['X-OP-Event'] ?? '';

        // 2. Validate required headers
        if (empty($signature) || empty($timestamp) || empty($eventId)) {
            return [
                'accepted' => false,
                'message' => 'Missing required webhook headers (X-OP-Signature, X-OP-Timestamp, X-OP-Event-Id).',
                'event_id' => $eventId,
            ];
        }

        // 3. Validate timestamp freshness (SEC-03)
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_SKEW) {
            return [
                'accepted' => false,
                'message' => 'Webhook timestamp expired or invalid.',
                'event_id' => $eventId,
            ];
        }

        // 4. Verify HMAC signature (SEC-03)
        $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
        if (!hash_equals($expectedSignature, $signature)) {
            error_log("[WebhookInbound] Signature mismatch for event {$eventId}");
            return [
                'accepted' => false,
                'message' => 'Invalid webhook signature.',
                'event_id' => $eventId,
            ];
        }

        // 5. Deduplication — check if event already processed
        $existingEvent = $this->webhookEvents->findByEventId($eventId);
        if ($existingEvent !== null) {
            return [
                'accepted' => true,
                'message' => 'Event already processed (idempotent).',
                'event_id' => $eventId,
            ];
        }

        // 6. Parse payload
        $payload = json_decode($rawBody, true);
        if ($payload === null) {
            return [
                'accepted' => false,
                'message' => 'Invalid JSON payload.',
                'event_id' => $eventId,
            ];
        }

        // 7. Record the event
        $this->webhookEvents->insert([
            'merchant_id' => $merchantId,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => $rawBody,
            'source_ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'status' => 'processing',
        ]);

        // 8. Route event to handler
        try {
            $this->routeEvent($eventType, $payload, $merchantId);

            // Mark as processed
            $this->webhookEvents->updateStatusByEventId($eventId, 'processed');

            $this->audit->log(
                $merchantId,
                'webhook.inbound_processed',
                'webhook_event',
                $eventId,
                'system',
                'webhook_processor',
                null,
                ['event_type' => $eventType]
            );

            return [
                'accepted' => true,
                'message' => 'Event processed successfully.',
                'event_id' => $eventId,
            ];
        } catch (\Throwable $e) {
            error_log("[WebhookInbound] Processing failed for {$eventId}: " . $e->getMessage());

            $this->webhookEvents->updateStatusByEventId($eventId, 'failed');

            return [
                'accepted' => false,
                'message' => 'Event processing failed.',
                'event_id' => $eventId,
            ];
        }
    }

    /**
     * Route a webhook event to the appropriate handler.
     */
    private function routeEvent(string $eventType, array $payload, int $merchantId): void
    {
        switch ($eventType) {
            case 'payment.completed':
                $this->handlePaymentCompleted($payload, $merchantId);
                break;

            case 'payment.failed':
                $this->handlePaymentStatusChange($payload, $merchantId, 'failed');
                break;

            case 'payment.canceled':
                $this->handlePaymentStatusChange($payload, $merchantId, 'canceled');
                break;

            case 'refund.completed':
                $this->handleRefundCompleted($payload, $merchantId);
                break;

            case 'dispute.created':
                $this->handleDisputeCreated($payload, $merchantId);
                break;

            default:
                error_log("[WebhookInbound] Unknown event type: {$eventType}");
                break;
        }
    }

    /**
     * Handle payment.completed — transition to completed + post ledger.
     */
    private function handlePaymentCompleted(array $payload, int $merchantId): void
    {
        $reference = $payload['data']['reference'] ?? $payload['data']['transaction_id'] ?? '';
        if (empty($reference)) {
            throw new RuntimeException('Missing reference in payment.completed payload.');
        }

        $txn = $this->paymentService->getTransaction($reference)
            ?? $this->paymentService->getTransactionByReference($reference);

        if ($txn === null) {
            throw new RuntimeException("Transaction not found: {$reference}");
        }

        if ($txn['status'] !== 'completed') {
            $this->paymentService->transitionStatus((int) $txn['id'], 'completed');
        }
    }

    /**
     * Handle generic status change (failed, canceled).
     */
    private function handlePaymentStatusChange(array $payload, int $merchantId, string $status): void
    {
        $reference = $payload['data']['reference'] ?? $payload['data']['transaction_id'] ?? '';
        if (empty($reference)) {
            return;
        }

        $txn = $this->paymentService->getTransaction($reference)
            ?? $this->paymentService->getTransactionByReference($reference);

        if ($txn !== null && $txn['status'] !== $status) {
            $this->paymentService->transitionStatus((int) $txn['id'], $status);
        }
    }

    /**
     * Handle refund.completed event.
     */
    private function handleRefundCompleted(array $payload, int $merchantId): void
    {
        $reference = $payload['data']['reference'] ?? $payload['data']['transaction_id'] ?? '';
        if (empty($reference)) {
            return;
        }

        $txn = $this->paymentService->getTransaction($reference)
            ?? $this->paymentService->getTransactionByReference($reference);

        if ($txn !== null && $txn['status'] === 'completed') {
            $this->paymentService->transitionStatus((int) $txn['id'], 'refunded');
        }
    }

    /**
     * Handle dispute.created event — log for now, full DisputeService handles lifecycle.
     */
    private function handleDisputeCreated(array $payload, int $merchantId): void
    {
        $this->audit->log(
            $merchantId,
            'dispute.webhook_received',
            'transaction',
            $payload['data']['reference'] ?? 'unknown',
            'system',
            'webhook_processor',
            null,
            $payload['data'] ?? []
        );
    }

    /**
     * Extract normalized headers from $_SERVER.
     */
    public static function extractHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('_', '-', substr($key, 5));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }
}
