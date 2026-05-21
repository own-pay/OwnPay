<?php

declare(strict_types=1);

namespace OwnPay\Gateway;

use OwnPay\Core\Database;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Service\Payment\LedgerService;
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

    private Database $db;
    private TransactionService $transactionService;
    private TransactionRepository $transactionRepo;
    private AuditLogger $audit;
    private Logger $logger;
    private LedgerService $ledgerService;

    public function __construct(
        Database $db,
        TransactionService $transactionService,
        TransactionRepository $transactionRepo,
        AuditLogger $audit,
        Logger $logger,
        LedgerService $ledgerService
    ) {
        $this->db = $db;
        $this->transactionService = $transactionService;
        $this->transactionRepo = $transactionRepo;
        $this->audit = $audit;
        $this->logger = $logger;
        $this->ledgerService = $ledgerService;
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
        $payloadHash = hash('sha256', $rawBody);

        // Dedup
        $existing = $this->db->fetchOne(
            "SELECT id FROM op_webhook_deliveries WHERE merchant_id = :mid AND direction = 'inbound' AND payload_hash = :hash LIMIT 1",
            ['mid' => $merchantId, 'hash' => $payloadHash]
        );
        if ($existing !== null) {
            return $this->result(true, 'Event already processed (idempotent).', $eventId);
        }

        // Parse
        $payload = json_decode($rawBody, true);
        if ($payload === null) {
            return $this->result(false, 'Invalid JSON payload.', $eventId);
        }

        // Record + route
        $this->recordEvent($merchantId, $eventId, $eventType, $payloadHash);
        return $this->executeEvent($eventType, $payload, $merchantId, $eventId, $payloadHash);
    }

    // ── Extracted Methods ─────────────────────────────────────────

    /**
     * Extract webhook-specific headers.
     */
    private function extractWebhookHeaders(array $headers): array
    {
        // Normalize all header keys to lowercase for case-insensitive lookup
        $normalized = [];
        foreach ($headers as $k => $v) {
            $normalized[strtolower($k)] = $v;
        }

        return [
            'signature' => $normalized['x-op-signature'] ?? '',
            'timestamp' => $normalized['x-op-timestamp'] ?? '',
            'eventId'   => $normalized['x-op-event-id'] ?? '',
            'eventType' => $normalized['x-op-event'] ?? '',
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
    private function recordEvent(int $merchantId, string $eventId, string $eventType, string $payloadHash): void
    {
        $this->db->insert(
            "INSERT INTO op_webhook_deliveries (merchant_id, gateway, event, direction, status, payload_hash, attempt, created_at) 
             VALUES (:mid, :gw, :evt, 'inbound', 'received', :hash, 1, NOW(6))",
            [
                'mid' => $merchantId,
                'gw' => 'system',
                'evt' => $eventType,
                'hash' => $payloadHash
            ]
        );
    }

    /**
     * Execute event handler with error recovery.
     */
    private function executeEvent(string $eventType, array $payload, int $merchantId, string $eventId, string $payloadHash): array
    {
        try {
            $this->routeEvent($eventType, $payload, $merchantId);
            $this->updateDeliveryStatus($merchantId, $payloadHash, 'delivered');
            /** @phpstan-ignore-next-line */
            $this->audit->log($merchantId, 'webhook.inbound_processed', 'webhook_event', $eventId, 'system', 'webhook_processor', null, ['event_type' => $eventType]);
            return $this->result(true, 'Event processed successfully.', $eventId);
        } catch (\Throwable $e) {
            $this->logger->error("Processing failed for {$eventId}: " . $e->getMessage());
            $this->updateDeliveryStatus($merchantId, $payloadHash, 'failed', substr($e->getMessage(), 0, 500));
            return $this->result(false, 'Event processing failed.', $eventId);
        }
    }

    /**
     * Update status in op_webhook_deliveries.
     */
    private function updateDeliveryStatus(int $merchantId, string $payloadHash, string $status, ?string $error = null): void
    {
        $this->db->execute(
            "UPDATE op_webhook_deliveries 
             SET status = :status, error = :error
             WHERE merchant_id = :mid AND direction = 'inbound' AND payload_hash = :hash
             ORDER BY created_at DESC LIMIT 1",
            [
                'status' => $status,
                'error' => $error,
                'mid' => $merchantId,
                'hash' => $payloadHash
            ]
        );
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
            $updatedTxn = $this->transactionRepo->forTenant($merchantId)->findScoped((int) $txn['id']);
            if ($updatedTxn !== null) {
                $this->ledgerService->recordPaymentReceived(
                    $merchantId,
                    (int) $updatedTxn['id'],
                    (string) $updatedTxn['amount'],
                    (string) ($updatedTxn['fee'] ?? '0.00'),
                    (string) $updatedTxn['currency']
                );
            }
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
            $amount = (string) ($payload['data']['refund_amount'] ?? $payload['data']['amount'] ?? $txn['amount']);
            $this->ledgerService->recordRefund(
                $merchantId,
                (int) $txn['id'],
                $amount,
                $txn['currency']
            );
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
