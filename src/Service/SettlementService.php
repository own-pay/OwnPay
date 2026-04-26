<?php

declare(strict_types=1);

namespace OwnPay\Service;

use OwnPay\Core\Database;
use OwnPay\Core\UuidGenerator;
use OwnPay\Repository\SettlementRepository;
use OwnPay\Repository\TransactionRepository;
use InvalidArgumentException;

/**
 * SettlementService — batch settlement processing.
 *
 * Aggregates completed transactions into settlement batches,
 * calculates merchant fees, and posts settlement ledger entries.
 */
final class SettlementService
{
    private Database $db;
    private SettlementRepository $settlements;
    private TransactionRepository $transactions;
    private LedgerService $ledger;
    private AuditLogger $audit;

    public function __construct(
        ?Database $db = null,
        ?SettlementRepository $settlements = null,
        ?TransactionRepository $transactions = null,
        ?LedgerService $ledger = null,
        ?AuditLogger $audit = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->settlements = $settlements ?? new SettlementRepository();
        $this->transactions = $transactions ?? new TransactionRepository();
        $this->ledger = $ledger ?? new LedgerService();
        $this->audit = $audit ?? new AuditLogger();
    }

    /**
     * Create a settlement batch for a merchant.
     *
     * Gathers all completed, unsettled transactions and creates
     * a settlement record with aggregated totals.
     *
     * @param int    $merchantId
     * @param string $currency   ISO 4217 currency code
     * @return array Settlement record
     */
    public function createBatch(int $merchantId, string $currency = 'BDT'): array
    {
        return $this->db->transactional(function () use ($merchantId, $currency) {
            // Find completed, unsettled transactions
            $rows = $this->db->fetchAll("
                SELECT id, amount, currency
                FROM op_transactions
                WHERE merchant_id = :mid
                  AND currency = :cur
                  AND status = 'completed'
                  AND settlement_id IS NULL
                ORDER BY created_at ASC
            ", [':mid' => $merchantId, ':cur' => $currency]);

            if (empty($rows)) {
                throw new InvalidArgumentException('No unsettled transactions found.');
            }

            // Calculate totals
            $grossAmount = '0.0000';
            $txnIds = [];
            foreach ($rows as $row) {
                $grossAmount = bcadd($grossAmount, $row['amount'], 4);
                $txnIds[] = (int) $row['id'];
            }

            // Calculate fees from fee rules
            $feeAmount = $this->calculateFees($merchantId, $grossAmount, $currency);
            $netAmount = bcsub($grossAmount, $feeAmount, 4);

            // Create settlement
            $settlementId = $this->settlements->insert([
                'merchant_id' => $merchantId,
                'gross_amount' => $grossAmount,
                'fee_amount' => $feeAmount,
                'net_amount' => $netAmount,
                'currency' => $currency,
                'transaction_count' => count($txnIds),
                'status' => 'pending',
            ]);

            // Link transactions to this settlement
            $placeholders = implode(',', array_fill(0, count($txnIds), '?'));
            $this->db->execute("
                UPDATE op_transactions
                SET settlement_id = ?, updated_at = NOW(6)
                WHERE id IN ({$placeholders})
            ", array_merge([$settlementId], $txnIds));

            $settlement = $this->settlements->findById($settlementId);

            // Post ledger entry
            $this->ledger->postSettlement(
                $merchantId,
                $settlement['public_id'],
                $netAmount,
                $feeAmount,
                $currency
            );

            // Audit
            $this->audit->log(
                $merchantId,
                'settlement.created',
                'settlement',
                $settlement['public_id'],
                'system',
                'settlement_service',
                null,
                [
                    'gross_amount' => $grossAmount,
                    'fee_amount' => $feeAmount,
                    'net_amount' => $netAmount,
                    'transaction_count' => count($txnIds),
                ]
            );

            return $settlement;
        });
    }

    /**
     * Mark a settlement as completed (paid out).
     */
    public function complete(int $settlementId, array $payoutDetails = []): array
    {
        $settlement = $this->settlements->findById($settlementId);
        if ($settlement === null) {
            throw new InvalidArgumentException("Settlement #{$settlementId} not found.");
        }

        if ($settlement['status'] !== 'pending') {
            throw new InvalidArgumentException("Settlement is not in 'pending' status.");
        }

        $this->settlements->updateStatus($settlementId, 'completed');

        $this->audit->log(
            (int) $settlement['merchant_id'],
            'settlement.completed',
            'settlement',
            $settlement['public_id'],
            'system',
            'settlement_service',
            ['status' => 'pending'],
            ['status' => 'completed', 'payout' => $payoutDetails]
        );

        return $this->settlements->findById($settlementId);
    }

    /**
     * Calculate fees for a settlement based on op_fee_rules.
     *
     * @param int    $merchantId
     * @param string $grossAmount  Total gross amount
     * @param string $currency
     * @return string Fee amount (DECIMAL string)
     */
    private function calculateFees(int $merchantId, string $grossAmount, string $currency): string
    {
        // Look up fee rule for this merchant + currency
        $rule = $this->db->fetchOne("
            SELECT fee_type, fee_value, min_fee, max_fee
            FROM op_fee_rules
            WHERE (merchant_id = :mid OR merchant_id IS NULL)
              AND currency = :cur
              AND is_active = 1
            ORDER BY merchant_id DESC
            LIMIT 1
        ", [':mid' => $merchantId, ':cur' => $currency]);

        if (!$rule) {
            // Default: 2% fee
            return bcmul($grossAmount, '0.02', 4);
        }

        if ($rule['fee_type'] === 'percentage') {
            $fee = bcmul($grossAmount, bcdiv($rule['fee_value'], '100', 8), 4);
        } else {
            // flat fee
            $fee = $rule['fee_value'];
        }

        // Apply min/max bounds
        if (!empty($rule['min_fee']) && bccomp($fee, $rule['min_fee'], 4) < 0) {
            $fee = $rule['min_fee'];
        }
        if (!empty($rule['max_fee']) && bccomp($fee, $rule['max_fee'], 4) > 0) {
            $fee = $rule['max_fee'];
        }

        return $fee;
    }
}
