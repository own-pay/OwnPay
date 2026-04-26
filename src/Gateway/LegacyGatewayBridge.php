<?php

declare(strict_types=1);

namespace OwnPay\Gateway;

use OwnPay\Core\Database;
use OwnPay\Service\PaymentService;
use OwnPay\Service\LedgerService;
use OwnPay\Service\AuditLogger;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\GatewayConfigRepository;

/**
 * LegacyGatewayBridge — translates legacy op_transaction events into
 * the new op_* service layer.
 *
 * This runs in parallel with the legacy flow. When a legacy gateway callback
 * updates op_transaction, this bridge syncs the event to op_transactions
 * through PaymentService.
 *
 * Usage:
 *   $bridge = new LegacyGatewayBridge();
 *   $bridge->syncTransactionCreated($legacyTxnRow);
 *   $bridge->syncStatusChange($reference, 'completed', $gatewayResponse);
 */
final class LegacyGatewayBridge
{
    /**
     * Legacy status → new FSM status mapping.
     */
    private const STATUS_MAP = [
        'initiated' => 'initiated',
        'pending' => 'pending',
        'completed' => 'completed',
        'cancelled' => 'canceled',
        'canceled' => 'canceled',
        'failed' => 'failed',
        'refunded' => 'refunded',
        'expired' => 'failed',
        // Legacy statuses that don't map cleanly
        'processing' => 'pending',
        'waiting' => 'pending',
    ];

    private Database $db;
    private PaymentService $paymentService;
    private TransactionRepository $transactions;
    private GatewayConfigRepository $gatewayConfigs;
    private LedgerService $ledger;
    private AuditLogger $audit;

    public function __construct(
        ?Database $db = null,
        ?PaymentService $paymentService = null,
        ?TransactionRepository $transactions = null,
        ?GatewayConfigRepository $gatewayConfigs = null,
        ?LedgerService $ledger = null,
        ?AuditLogger $audit = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->paymentService = $paymentService ?? new PaymentService();
        $this->transactions = $transactions ?? new TransactionRepository();
        $this->gatewayConfigs = $gatewayConfigs ?? new GatewayConfigRepository();
        $this->ledger = $ledger ?? new LedgerService();
        $this->audit = $audit ?? new AuditLogger();
    }

    /**
     * Map a legacy status string to the new FSM status.
     */
    public function mapStatus(string $legacyStatus): string
    {
        $normalized = strtolower(trim($legacyStatus));
        return self::STATUS_MAP[$normalized] ?? 'pending';
    }

    /**
     * Sync a newly created legacy transaction into op_transactions.
     *
     * Call this after a legacy INSERT into op_transaction succeeds.
     *
     * @param array $legacyTxn The legacy op_transaction row
     * @return array|null The new op_transactions row, or null on failure
     */
    public function syncTransactionCreated(array $legacyTxn): ?array
    {
        try {
            $merchantId = (int) ($legacyTxn['brand_id'] ?? 0);
            $amount = $legacyTxn['amount'] ?? '0.0000';
            $currency = $legacyTxn['currency'] ?? 'BDT';
            $reference = $legacyTxn['ref'] ?? '';

            if ($merchantId === 0 || $reference === '') {
                error_log("[LegacyBridge] Cannot sync: missing brand_id or ref");
                return null;
            }

            // Check if already synced (idempotent)
            $existing = $this->transactions->findByReference($reference);
            if ($existing !== null) {
                return $existing;
            }

            // Parse customer info
            $customerInfo = [];
            if (!empty($legacyTxn['customer_info'])) {
                $customerInfo = json_decode($legacyTxn['customer_info'], true) ?: [];
            }

            // Create payment intent + transaction via PaymentService
            $intent = $this->paymentService->createIntent(
                $merchantId,
                number_format((float) $amount, 4, '.', ''),
                $currency,
                $customerInfo,
                json_decode($legacyTxn['metadata'] ?? '{}', true) ?: []
            );

            // Determine gateway config
            $gatewayConfigId = 0;
            if (!empty($legacyTxn['gateway_id'])) {
                $config = $this->gatewayConfigs->findByGatewayId(
                    $legacyTxn['gateway_id'],
                    $merchantId
                );
                $gatewayConfigId = $config ? (int) $config['id'] : 0;
            }

            // Map the legacy status
            $newStatus = $this->mapStatus($legacyTxn['status'] ?? 'initiated');

            // Process payment through service
            $txn = $this->paymentService->processPayment(
                (int) $intent['id'],
                $gatewayConfigId,
                $newStatus,
                [
                    'legacy_ref' => $reference,
                    'legacy_trx_id' => $legacyTxn['trx_id'] ?? '',
                    'sender' => $legacyTxn['sender'] ?? '',
                    'sender_type' => $legacyTxn['sender_type'] ?? '',
                ]
            );

            $this->audit->log(
                $merchantId,
                'legacy_bridge.synced',
                'transaction',
                $txn['public_id'],
                'system',
                'legacy_bridge',
                null,
                ['legacy_ref' => $reference]
            );

            return $txn;
        } catch (\Throwable $e) {
            error_log("[LegacyBridge] syncTransactionCreated failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Sync a legacy status change to the new op_transactions FSM.
     *
     * Call this after a legacy UPDATE to op_transaction.status succeeds.
     *
     * @param string $reference       Legacy op_transaction.ref
     * @param string $legacyStatus    New legacy status
     * @param array  $gatewayResponse Gateway callback data
     * @return array|null Updated op_transactions row, or null
     */
    public function syncStatusChange(
        string $reference,
        string $legacyStatus,
        array $gatewayResponse = []
    ): ?array {
        try {
            $txn = $this->transactions->findByReference($reference);
            if ($txn === null) {
                error_log("[LegacyBridge] syncStatusChange: ref '{$reference}' not found in op_transactions");
                return null;
            }

            $newStatus = $this->mapStatus($legacyStatus);

            // Skip if already in this state
            if ($txn['status'] === $newStatus) {
                return $txn;
            }

            // Transition through the state machine
            $updated = $this->paymentService->transitionStatus(
                (int) $txn['id'],
                $newStatus
            );

            return $updated;
        } catch (\Throwable $e) {
            error_log("[LegacyBridge] syncStatusChange failed for ref '{$reference}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Batch sync all legacy completed transactions that haven't been bridged yet.
     *
     * Useful for initial migration or catch-up.
     *
     * @param int $limit Max rows to process per batch
     * @return int Number of rows synced
     */
    public function batchSync(int $limit = 100): int
    {
        // Database::execute() with emulate_prepares=false requires typed bind for LIMIT,
        // so we use getPdo() for bindValue with explicit PDO::PARAM_INT on the LIMIT param.
        $pdo = $this->db->getPdo();

        // Find legacy transactions not yet synced
        $stmt = $pdo->prepare("
            SELECT pt.*
            FROM op_transaction pt
            LEFT JOIN op_transactions at2 ON at2.reference = pt.ref
            WHERE at2.id IS NULL
            ORDER BY pt.id ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $synced = 0;
        foreach ($rows as $row) {
            if ($this->syncTransactionCreated($row) !== null) {
                $synced++;
            }
        }

        return $synced;
    }
}
