<?php

declare(strict_types=1);

namespace OwnPay\Gateway;

use OwnPay\Core\Database;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Service\System\AuditLogger;
use OwnPay\Service\System\Logger;
use OwnPay\Repository\WebhookEventRepository;
use OwnPay\Repository\TransactionRepository;
use RuntimeException;

/**
 * WebhookInboundProcessor — secure inbound webhook handler.
 *
 * Validates HMAC signatures, deduplicates events, and routes
 * payment status updates through the PaymentService state machine.
 */
final class WebhookInboundProcessor
{
    private const MAX_TIMESTAMP_SKEW = 300;

    /** @phpstan-ignore property.onlyWritten */
    private Database $db;
    private TransactionService $transactionService;
    private TransactionRepository $transactionRepo;
    private WebhookEventRepository $webhookEvents;
    private AuditLogger $audit;
    private Logger $logger;

    public function __construct(
        Database $db,
        TransactionService $transactionService,
        TransactionRepository $transactionRepo,
        WebhookEventRepository $webhookEvents,
        AuditLogger $audit,
        Logger $logger
    ) {
        $this->db = $db;
        $this->transactionService = $transactionService;
        $this->transactionRepo = $transactionRepo;
        $this->webhookEvents = $webhookEvents;
        $this->audit = $audit;
        $this->logger = $logger;
    }

    /**
     * Process an inbound webhook request.
     * Decomposed: extractHeaders → validate → dedup → parse → route.
     */
    public function process(string $rawBody, array $headers, string $secret, int $merchantId): array
    {
        $whHeaders = $this->extractWebhookHeaders($headers);

        // Validate headers + signature
        $validation = $this->validateRequest($whHeaders, $rawBody, $secret);
        if ($validation !== null) return $validation;

        $eventId   = $whHeaders['eventId'];
        $eventType = $whHeaders['eventType'];

        // Dedup
        if ($this->webhookEvents->findByEventId($eventId) !== null) {
            return $this->result(true, 'Event already processed (idempotent).', $eventId);
        }

        // Parse
        $payload = json_decode($rawBody, true);
        if ($payload === null) {
            return $this->result(false, 'Invalid JSON payload.', $eventId);
        }

        // Record + route
        $this->recordEvent($merchantId, $eventId, $eventType, $rawBody);
        return $this->executeEvent($eventType, $payload, $merchantId, $eventId);
    }

    // ── Extracted Methods ─────────────────────────────────────────

    /**
     * Extract webhook-specific headers.
     */
    private function extractWebhookHeaders(array $headers): array
    {
        return [
            'signature' => $headers['x-op-signature'] ?? $headers['X-OP-Signature'] ?? '',
            'timestamp' => $headers['x-op-timestamp'] ?? $headers['X-OP-Timestamp'] ?? '',
            'eventId'   => $headers['x-op-event-id'] ?? $headers['X-OP-Event-Id'] ?? '',
            'eventType' => $headers['x-op-event'] ?? $headers['X-OP-Event'] ?? '',
        ];
    }

    /**
     * Validate headers, timestamp freshness, HMAC signature.
     * @return array|null null if valid, error result otherwise
     */
    private function validateRequest(array $wh, string $rawBody, string $secret): ?array
    {
        if (empty($wh['signature']) || empty($wh['timestamp']) || empty($wh['eventId'])) {
            return $this->result(false, 'Missing required webhook headers (X-OP-Signature, X-OP-Timestamp, X-OP-Event-Id).', $wh['eventId']);
        }

        if (!ctype_digit($wh['timestamp']) || abs(time() - (int) $wh['timestamp']) > self::MAX_TIMESTAMP_SKEW) {
            return $this->result(false, 'Webhook timestamp expired or invalid.', $wh['eventId']);
        }

        $expected = hash_hmac('sha256', "{$wh['timestamp']}.{$rawBody}", $secret);
        if (!hash_equals($expected, $wh['signature'])) {
            $this->logger->warning("Signature mismatch for event {$wh['eventId']}");
            return $this->result(false, 'Invalid webhook signature.', $wh['eventId']);
        }

        return null;
    }

    /**
     * Record webhook event for audit trail.
     */
    private function recordEvent(int $merchantId, string $eventId, string $eventType, string $rawBody): void
    {
        $this->webhookEvents->insert([
            'merchant_id' => $merchantId,
            'event_id'    => $eventId,
            'event_type'  => $eventType,
            'payload'     => $rawBody,
            'source_ip'   => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'status'      => 'processing',
        ]);
    }

    /**
     * Execute event handler with error recovery.
     */
    private function executeEvent(string $eventType, array $payload, int $merchantId, string $eventId): array
    {
        try {
            $this->routeEvent($eventType, $payload, $merchantId);
            $this->webhookEvents->updateStatusByEventId($eventId, 'processed');
            /** @phpstan-ignore-next-line */
            $this->audit->log($merchantId, 'webhook.inbound_processed', 'webhook_event', $eventId, 'system', 'webhook_processor', null, ['event_type' => $eventType]);
            return $this->result(true, 'Event processed successfully.', $eventId);
        } catch (\Throwable $e) {
            $this->logger->error("Processing failed for {$eventId}: " . $e->getMessage());
            $this->webhookEvents->updateStatusByEventId($eventId, 'failed');
            return $this->result(false, 'Event processing failed.', $eventId);
        }
    }

    /**
     * Build standardized result.
     */
    private function result(bool $accepted, string $message, string $eventId): array
    {
        return ['accepted' => $accepted, 'message' => $message, 'event_id' => $eventId];
    }

    // ── Event Routing ─────────────────────────────────────────────

    private function routeEvent(string $eventType, array $payload, int $merchantId): void
    {
        match ($eventType) {
            'payment.completed' => $this->handlePaymentCompleted($payload, $merchantId),
            'payment.failed'    => $this->handlePaymentStatusChange($payload, $merchantId, 'failed'),
            'payment.canceled'  => $this->handlePaymentStatusChange($payload, $merchantId, 'canceled'),
            'refund.completed'  => $this->handleRefundCompleted($payload, $merchantId),
            'dispute.created'   => $this->handleDisputeCreated($payload, $merchantId),
            default             => $this->logger->warning("Unknown event type: {$eventType}"),
        };
    }

    private function handlePaymentCompleted(array $payload, int $merchantId): void
    {
        $txn = $this->resolveTransaction($payload, $merchantId);
        if ($txn['status'] !== 'completed') {
            $this->transactionService->complete((int) $txn['id'], $merchantId);
        }
    }

    private function handlePaymentStatusChange(array $payload, int $merchantId, string $status): void
    {
        $reference = $payload['data']['reference'] ?? $payload['data']['transaction_id'] ?? '';
        if (empty($reference)) return;

        $txn = $this->findTransaction($reference, $merchantId);
        if ($txn === null || $txn['status'] === $status) return;

        match ($status) {
            'failed'   => $this->transactionService->fail((int) $txn['id'], $merchantId, 'Webhook status update'),
            'canceled' => $this->transactionService->cancel((int) $txn['id'], $merchantId),
            default    => $this->transactionRepo->forTenant($merchantId)->updateScoped((int) $txn['id'], ['status' => $status]),
        };
    }

    private function handleRefundCompleted(array $payload, int $merchantId): void
    {
        $reference = $payload['data']['reference'] ?? $payload['data']['transaction_id'] ?? '';
        if (empty($reference)) return;

        $txn = $this->findTransaction($reference, $merchantId);
        if ($txn !== null && $txn['status'] === 'completed') {
            $this->transactionRepo->forTenant($merchantId)->updateScoped((int) $txn['id'], ['status' => 'refunded']);
        }
    }

    private function handleDisputeCreated(array $payload, int $merchantId): void
    {
        /** @phpstan-ignore-next-line */
            $this->audit->log($merchantId, 'dispute.webhook_received', 'transaction', $payload['data']['reference'] ?? 'unknown', 'system', 'webhook_processor', null, $payload['data'] ?? []);
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Resolve transaction or throw.
     */
    private function resolveTransaction(array $payload, int $merchantId): array
    {
        $reference = $payload['data']['reference'] ?? $payload['data']['transaction_id'] ?? '';
        if (empty($reference)) throw new RuntimeException('Missing reference in payload.');
        $txn = $this->findTransaction($reference, $merchantId);
        if ($txn === null) throw new RuntimeException("Transaction not found: {$reference}");
        return $txn;
    }

    /**
     * Find transaction by trx_id or reference.
     */
    private function findTransaction(string $reference, int $merchantId): ?array
    {
        $repo = $this->transactionRepo->forTenant($merchantId);
        return $repo->findByTrxId($reference) ?? $repo->findBy('reference', $reference);
    }

    /**
     * Extract normalized headers from $_SERVER.
     */
    public static function extractHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        return $headers;
    }
}
