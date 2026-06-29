<?php

declare(strict_types=1);

namespace OwnPay\Gateway;

use OwnPay\Core\Database;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Service\Payment\LedgerService;
use OwnPay\Service\System\AuditLogger;
use OwnPay\Service\System\Logger;
use OwnPay\Repository\TransactionRepository;
use RuntimeException;

/**
 * Service for securely processing incoming webhook events.
 *
 * Validates cryptographic signatures, performs timestamp skew checks, prevents event
 * duplication using database-level uniqueness, and transitions payment records.
 */
final class WebhookInboundProcessor
{
    /**
     * Maximum allowed timestamp skew in seconds to prevent replay attacks.
     */
    private const MAX_TIMESTAMP_SKEW = 300;

    /**
     * Database interface instance.
     *
     * @var Database
     */
    private Database $db;

    /**
     * Transaction state manipulation service.
     *
     * @var TransactionService
     */
    private TransactionService $transactionService;

    /**
     * Transaction database repository.
     *
     * @var TransactionRepository
     */
    private TransactionRepository $transactionRepo;

    /**
     * System audit logger instance.
     *
     * @var AuditLogger
     */
    private AuditLogger $audit;

    /**
     * System application logger instance.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Double-entry bookkeeping ledger service.
     *
     * @var LedgerService
     */
    private LedgerService $ledgerService;

    /**
     * Initializes the webhook processor with necessary system dependencies.
     *
     * @param Database $db Database interface instance.
     * @param TransactionService $transactionService Transaction state manipulation service.
     * @param TransactionRepository $transactionRepo Transaction database repository.
     * @param AuditLogger $audit System audit logger instance.
     * @param Logger $logger System application logger instance.
     * @param LedgerService $ledgerService Double-entry bookkeeping ledger service.
     */
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
     * Processes an incoming webhook request payload.
     *
     * @param string $rawBody The raw, unmodified HTTP request body.
     * @param array<string, string> $headers Raw incoming HTTP headers.
     * @param string $secret Signature secret key configured for the webhook route.
     * @param int $merchantId Active brand/store identifier context.
     * @return array{accepted: bool, message: string, event_id: string} Verification and processing results.
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

        $existing = $this->db->fetchOne(
            "SELECT id FROM op_webhook_deliveries WHERE merchant_id = :mid AND direction = 'inbound' AND payload_hash = :hash LIMIT 1",
            ['mid' => $merchantId, 'hash' => $payloadHash]
        );
        if ($existing !== null) {
            return $this->result(true, 'Event already processed (idempotent).', $eventId);
        }

        // Parse
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->result(false, 'Invalid JSON payload.', $eventId);
        }
        $payloadChecked = [];
        foreach ($payload as $k => $v) {
            $payloadChecked[(string) $k] = $v;
        }

        try {
            $this->recordEvent($merchantId, $eventId, $eventType, $payloadHash);
        } catch (\PDOException $e) {
            if (self::isDuplicateKeyError($e)) {
                return $this->result(true, 'Event already processed (idempotent).', $eventId);
            }
            throw $e;
        }

        return $this->executeEvent($eventType, $payloadChecked, $merchantId, $eventId, $payloadHash);
    }

    /**
     * Determines whether a PDO exception represents a MySQL duplicate-key violation (errno 1062).
     *
     * SQLSTATE 23000 alone is insufficient - it also covers foreign-key
     * violations, which must propagate as real errors.
     *
     * @param \PDOException $e The caught PDO exception.
     * @return bool True when the failure is a unique-constraint conflict.
     */
    private static function isDuplicateKeyError(\PDOException $e): bool
    {
        return isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062;
    }

    /**
     * Extracts and normalizes webhook-specific request headers.
     *
     * Normalizes header names to lowercase to guarantee case-insensitive retrieval.
     *
     * @param array<string, string> $headers All request headers.
     * @return array{signature: string, timestamp: string, eventId: string, eventType: string} Normalized webhook meta.
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
     * Validates headers format, checks timestamp freshness, and evaluates the HMAC signature.
     *
     * @param array{signature: string, timestamp: string, eventId: string, eventType: string} $wh Extracted header elements.
     * @param string $rawBody Raw HTTP body payload.
     * @param string $secret Configured webhook signing secret.
     * @return array{accepted: bool, message: string, event_id: string}|null Null if request is valid, error payload array otherwise.
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
     * Persists inbound webhook metadata in the database for debugging and audit trails.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param string $eventId Unique identifier code of the webhook event.
     * @param string $eventType Category/type of the incoming event (e.g. 'payment.completed').
     * @param string $payloadHash SHA-256 hash representation of the raw payload body.
     * @return void
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
     * Executes the routing logic inside a managed catch block to handle process errors gracefully.
     *
     * @param string $eventType Category/type of the incoming event.
     * @param array<string, mixed> $payload Parsed payload parameters.
     * @param int $merchantId Active brand/store identifier context.
     * @param string $eventId Unique identifier code of the webhook event.
     * @param string $payloadHash SHA-256 hash representation of the raw payload body.
     * @return array{accepted: bool, message: string, event_id: string} Result status description.
     */
    private function executeEvent(string $eventType, array $payload, int $merchantId, string $eventId, string $payloadHash): array
    {
        try {
            $this->routeEvent($eventType, $payload, $merchantId);
            $this->updateDeliveryStatus($merchantId, $payloadHash, 'delivered');
            $this->audit->log($merchantId, null, 'webhook.inbound_processed', 'webhook_event', $eventId, null, ['event_type' => $eventType]);
            return $this->result(true, 'Event processed successfully.', $eventId);
        } catch (\Throwable $e) {
            $this->logger->error("Processing failed for {$eventId}: " . $e->getMessage());
            $this->updateDeliveryStatus($merchantId, $payloadHash, 'failed', substr($e->getMessage(), 0, 500));
            return $this->result(false, 'Event processing failed.', $eventId);
        }
    }

    /**
     * Updates the delivery record status and logs error context when execution fails.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param string $payloadHash SHA-256 hash representation of the raw payload body.
     * @param string $status State transition label (e.g. 'delivered', 'failed').
     * @param string|null $error Explanatory exception log string.
     * @return void
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
     * Generates a standard process result array.
     *
     * @param bool $accepted Boolean indicating processing validation state.
     * @param string $message Explanatory response string.
     * @param string $eventId Identifier code of the webhook event.
     * @return array{accepted: bool, message: string, event_id: string} Standard webhook outcome layout.
     */
    private function result(bool $accepted, string $message, string $eventId): array
    {
        return ['accepted' => $accepted, 'message' => $message, 'event_id' => $eventId];
    }

    /**
     * Routes the parsed webhook payload based on the event classification code.
     *
     * @param string $eventType Category/type of the incoming event.
     * @param array<string, mixed> $payload Parsed payload parameters.
     * @param int $merchantId Active brand/store identifier context.
     * @return void
     */
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

    /**
     * Processes completed payments, updating repositories and registering journal entries.
     *
     * Ensures transaction is not already resolved before running database updates.
     *
     * @param array<string, mixed> $payload Event data content.
     * @param int $merchantId Active brand/store identifier context.
     * @return void
     */
    private function handlePaymentCompleted(array $payload, int $merchantId): void
    {
        $txn = $this->resolveTransaction($payload, $merchantId);
        if (!isset($txn['id']) || !is_scalar($txn['id'])) {
            return;
        }
        $txnId = (int) $txn['id'];

        $this->db->transaction(function () use ($payload, $merchantId, $txnId): void {
            $txn = $this->db->fetchOne(
                "SELECT * FROM op_transactions WHERE id = :id AND merchant_id = :mid LIMIT 1 FOR UPDATE",
                ['id' => $txnId, 'mid' => $merchantId]
            );
            if ($txn === null) {
                return;
            }

            $data = $payload['data'] ?? null;
            $eventAmountVal = is_array($data) ? ($data['amount'] ?? null) : null;
            $eventAmountStr = is_scalar($eventAmountVal) ? (string) $eventAmountVal : '';
            $storedAmountStr = isset($txn['amount']) && is_scalar($txn['amount']) ? (string) $txn['amount'] : '';
            $convertedStr = '';
            $metaRaw = $txn['metadata'] ?? null;
            $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;
            if (is_array($meta) && isset($meta['converted_amount']) && is_scalar($meta['converted_amount'])) {
                $convertedStr = (string) $meta['converted_amount'];
            }
            $amountMatches = false;
            if (is_numeric($eventAmountStr)) {
                if (is_numeric($storedAmountStr) && bccomp($eventAmountStr, $storedAmountStr, 2) === 0) {
                    $amountMatches = true;
                } elseif (is_numeric($convertedStr) && bccomp($eventAmountStr, $convertedStr, 2) === 0) {
                    $amountMatches = true;
                }
            }
            if (!$amountMatches) {
                $this->logger->warning("payment.completed rejected: amount missing or mismatched for transaction {$txnId}");
                return;
            }

            $currentStatus = isset($txn['status']) && is_scalar($txn['status']) ? (string) $txn['status'] : '';
            if (in_array($currentStatus, ['pending', 'processing', 'callback_processing'], true)) {
                $this->transactionService->complete($txnId, $merchantId);
                $this->ledgerService->recordPaymentReceived(
                    $merchantId,
                    $txnId,
                    $storedAmountStr !== '' ? $storedAmountStr : '0.00',
                    isset($txn['fee']) && is_scalar($txn['fee']) ? (string) $txn['fee'] : '0.00',
                    isset($txn['currency']) && is_scalar($txn['currency']) ? (string) $txn['currency'] : 'BDT'
                );
            }
        });
    }

    /**
     * Manages terminal payment state updates such as cancellations or processing failures.
     *
     * @param array<string, mixed> $payload Event data content.
     * @param int $merchantId Active brand/store identifier context.
     * @param string $status The target state to request.
     * @return void
     */
    private function handlePaymentStatusChange(array $payload, int $merchantId, string $status): void
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data)) return;
        $referenceVal = $data['reference'] ?? $data['transaction_id'] ?? null;
        if (!is_string($referenceVal) || empty($referenceVal)) return;

        $txn = $this->findTransaction($referenceVal, $merchantId);
        if ($txn === null || !isset($txn['id']) || !is_scalar($txn['id']) || (isset($txn['status']) && $txn['status'] === $status)) return;
        
        $txnId = (int) $txn['id'];

        match ($status) {
            'failed'   => $this->transactionService->fail($txnId, $merchantId, 'Webhook status update'),
            'canceled' => $this->transactionService->cancel($txnId, $merchantId),
            default    => $this->transactionRepo->forTenant($merchantId)->updateScoped($txnId, ['status' => $status]),
        };
    }

    /**
     * Records refund events, modifying transaction states and issuing counter-ledger entries.
     *
     * @param array<string, mixed> $payload Event data content.
     * @param int $merchantId Active brand/store identifier context.
     * @return void
     */
    private function handleRefundCompleted(array $payload, int $merchantId): void
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data)) return;
        $referenceVal = $data['reference'] ?? $data['transaction_id'] ?? null;
        if (!is_string($referenceVal) || empty($referenceVal)) return;

        $txn = $this->findTransaction($referenceVal, $merchantId);
        if ($txn === null || !isset($txn['id']) || !is_scalar($txn['id'])) {
            return;
        }
        $txnId = (int) $txn['id'];

        $this->db->transaction(function () use ($data, $merchantId, $txnId): void {
            $txn = $this->db->fetchOne(
                "SELECT * FROM op_transactions WHERE id = :id AND merchant_id = :mid LIMIT 1 FOR UPDATE",
                ['id' => $txnId, 'mid' => $merchantId]
            );
            if ($txn === null || !isset($txn['status']) || $txn['status'] !== 'completed') {
                return;
            }
            if (!isset($txn['currency']) || !is_scalar($txn['currency'])) {
                return;
            }
            $txnCurrency = (string) $txn['currency'];

            $txnAmountStr = isset($txn['amount']) && is_scalar($txn['amount']) ? (string) $txn['amount'] : '';
            $refundAmountVal = $data['refund_amount'] ?? $data['amount'] ?? $txn['amount'] ?? null;
            $amount = is_scalar($refundAmountVal) ? (string) $refundAmountVal : '';
            if (!is_numeric($amount) || !is_numeric($txnAmountStr)
                || bccomp($amount, '0', 2) <= 0
                || bccomp($amount, $txnAmountStr, 2) > 0
            ) {
                $this->logger->warning("refund.completed rejected: invalid refund amount for transaction {$txnId}");
                return;
            }

            $this->transactionRepo->forTenant($merchantId)->updateScoped($txnId, ['status' => 'refunded']);

            // Find or create a refund record in op_refunds to get a unique refund ID for the ledger
            $refundRepo = $this->db->fetchOne(
                "SELECT id FROM op_refunds WHERE transaction_id = :txid AND merchant_id = :mid AND amount = :amt LIMIT 1",
                ['txid' => $txnId, 'mid' => $merchantId, 'amt' => $amount]
            );

            if ($refundRepo !== null && isset($refundRepo['id'])) {
                $idVal = $refundRepo['id'];
                $refundId = is_scalar($idVal) ? (int) $idVal : 0;
                $this->db->execute(
                    "UPDATE op_refunds SET status = 'completed', processed_at = NOW() WHERE id = :id",
                    ['id' => $refundId]
                );
            } else {
                $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
                $this->db->execute(
                    "INSERT INTO op_refunds (merchant_id, transaction_id, uuid, amount, reason, status, processed_at)
                     VALUES (:mid, :txid, :uuid, :amt, 'Refund completed via webhook', 'completed', NOW())",
                    ['mid' => $merchantId, 'txid' => $txnId, 'uuid' => $uuid, 'amt' => $amount]
                );
                $refundId = (int) $this->db->lastInsertId();
            }

            $this->ledgerService->recordRefund(
                $merchantId,
                $refundId,
                $txnId,
                $amount,
                $txnCurrency
            );
        });
    }

    /**
     * Log details of newly received disputes to the audit trail.
     *
     * @param array<string, mixed> $payload Event data content.
     * @param int $merchantId Active brand/store identifier context.
     * @return void
     */
    private function handleDisputeCreated(array $payload, int $merchantId): void
    {
        $data = $payload['data'] ?? null;
        $data = is_array($data) ? $data : null;
        $referenceVal = $data['reference'] ?? null;
        $reference = is_scalar($referenceVal) ? (string) $referenceVal : 'unknown';
        $this->audit->log($merchantId, null, 'dispute.webhook_received', 'transaction', $reference, null, $data);
    }

    /**
     * Resolves a transaction record based on payment reference, throwing if missing or invalid.
     *
     * @param array<string, mixed> $payload Event data content.
     * @param int $merchantId Active brand/store identifier context.
     * @return array<string, mixed> Resolved database transaction row parameters.
     * @throws RuntimeException If transaction reference is invalid or missing in context.
     */
    private function resolveTransaction(array $payload, int $merchantId): array
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data)) throw new RuntimeException('Missing data in payload.');
        $referenceVal = $data['reference'] ?? $data['transaction_id'] ?? null;
        if (!is_string($referenceVal) || empty($referenceVal)) throw new RuntimeException('Missing reference in payload.');
        $txn = $this->findTransaction($referenceVal, $merchantId);
        if ($txn === null) throw new RuntimeException("Transaction not found: {$referenceVal}");
        return $txn;
    }

    /**
     * Searches database transaction registries for specific transaction ID or references.
     *
     * @param string $reference Unique reference key or transaction ID code.
     * @param int $merchantId Active brand/store identifier context.
     * @return array<string, mixed>|null Database row array or null if not matching.
     */
    private function findTransaction(string $reference, int $merchantId): ?array
    {
        $repo = $this->transactionRepo->forTenant($merchantId);
        return $repo->findByTrxId($reference) ?? $repo->findBy('reference', $reference);
    }

    /**
     * Reconstructs incoming request headers from PHP global environment variables.
     *
     * Normalizes server parameters (HTTP_ prefix) to build a consistent header key map.
     *
     * @return array<string, string> Extracted and normalized header set.
     */
    public static function extractHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_scalar($value)) {
                $headers[str_replace('_', '-', substr($key, 5))] = (string) $value;
            }
        }
        return $headers;
    }
}
