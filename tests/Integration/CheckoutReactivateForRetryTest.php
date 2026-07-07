<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\PaymentIntentRepository;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression coverage for the checkout back-navigation business-logic gap: after a customer
 * picks a gateway and goes back (either immediately, or from the external gateway's own page)
 * WITHOUT completing payment, revisiting the plain checkout URL must let them pick again instead
 * of being stuck on a permanent "pending" status screen.
 *
 * `reactivateForRetry()` is the low-level primitive both controllers' show() call: it reverts a
 * transaction/intent from `processing` back to `pending` ONLY - never any other status, so it
 * can never resurrect a completed/failed/cancelled/awaiting_verification row.
 */
final class CheckoutReactivateForRetryTest extends IntegrationTestCase
{
    private Database $db;
    private TransactionRepository $txnRepo;
    private PaymentIntentRepository $intentRepo;
    private int $merchantId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::$dbAvailable) {
            return;
        }
        $this->db = Database::getInstance();
        $this->txnRepo = new TransactionRepository($this->db);
        $this->intentRepo = new PaymentIntentRepository($this->db);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_transactions WHERE trx_id LIKE 'zzretry-%'");
        $this->db->execute("DELETE FROM op_payment_intents WHERE token LIKE 'zzretry-%'");
    }

    private function insertTransaction(string $trxId, string $status, string $gateway = 'bkash-api', ?int $intentId = null): int
    {
        $this->db->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, payment_intent_id, gateway_slug, amount, net_amount, currency, status, method)
             VALUES (:mid, UUID(), :trx, :pi, :gw, 100.00, 100.00, 'BDT', :status, 'api')",
            ['mid' => $this->merchantId, 'trx' => $trxId, 'pi' => $intentId, 'gw' => $gateway, 'status' => $status]
        );
        $row = $this->db->fetchOne("SELECT id FROM op_transactions WHERE trx_id = :t", ['t' => $trxId]);
        $this->assertIsArray($row);
        return (int) $row['id'];
    }

    private function insertIntent(string $token, string $status): int
    {
        $this->db->execute(
            "INSERT INTO op_payment_intents (merchant_id, uuid, token, amount, currency, status, expires_at)
             VALUES (:mid, UUID(), :token, 100.00, 'BDT', :status, DATE_ADD(NOW(), INTERVAL 1 DAY))",
            ['mid' => $this->merchantId, 'token' => $token, 'status' => $status]
        );
        $row = $this->db->fetchOne("SELECT id FROM op_payment_intents WHERE token = :t", ['t' => $token]);
        $this->assertIsArray($row);
        return (int) $row['id'];
    }

    public function testTransactionRevertsFromProcessingToPending(): void
    {
        $this->insertTransaction('zzretry-txn-1', 'processing');

        $reverted = $this->txnRepo->reactivateForRetry('zzretry-txn-1');

        $this->assertTrue($reverted);
        $row = $this->db->fetchOne("SELECT status FROM op_transactions WHERE trx_id = :t", ['t' => 'zzretry-txn-1']);
        $this->assertSame('pending', $row['status']);
    }

    #[DataProvider('nonProcessingTransactionStatuses')]
    public function testTransactionUntouchedForNonProcessingStatuses(string $status): void
    {
        $this->insertTransaction('zzretry-txn-2', $status);

        $reverted = $this->txnRepo->reactivateForRetry('zzretry-txn-2');

        $this->assertFalse($reverted, "Must not revert a '{$status}' transaction");
        $row = $this->db->fetchOne("SELECT status FROM op_transactions WHERE trx_id = :t", ['t' => 'zzretry-txn-2']);
        $this->assertSame($status, $row['status']);
    }

    public static function nonProcessingTransactionStatuses(): array
    {
        return [
            ['pending'], ['completed'], ['failed'], ['cancelled'], ['expired'],
            ['awaiting_verification'], ['pending_review'], ['callback_processing'],
        ];
    }

    public function testIntentRevertsFromProcessingToPending(): void
    {
        $this->insertIntent('zzretry-intent-1', 'processing');

        $reverted = $this->intentRepo->reactivateForRetry('zzretry-intent-1');

        $this->assertTrue($reverted);
        $row = $this->db->fetchOne("SELECT status FROM op_payment_intents WHERE token = :t", ['t' => 'zzretry-intent-1']);
        $this->assertSame('pending', $row['status']);
    }

    #[DataProvider('nonProcessingIntentStatuses')]
    public function testIntentUntouchedForNonProcessingStatuses(string $status): void
    {
        $this->insertIntent('zzretry-intent-2', $status);

        $reverted = $this->intentRepo->reactivateForRetry('zzretry-intent-2');

        $this->assertFalse($reverted, "Must not revert a '{$status}' intent");
        $row = $this->db->fetchOne("SELECT status FROM op_payment_intents WHERE token = :t", ['t' => 'zzretry-intent-2']);
        $this->assertSame($status, $row['status']);
    }

    public static function nonProcessingIntentStatuses(): array
    {
        return [['pending'], ['completed'], ['failed'], ['cancelled'], ['expired']];
    }

    public function testTransactionLinkedToIntentRevertsByIntentId(): void
    {
        $intentId = $this->insertIntent('zzretry-intent-3', 'processing');
        $this->insertTransaction('zzretry-txn-3', 'processing', 'bkash-api', $intentId);

        $reverted = $this->txnRepo->reactivateForRetryByIntentId($intentId);

        $this->assertTrue($reverted);
        $row = $this->db->fetchOne("SELECT status FROM op_transactions WHERE trx_id = :t", ['t' => 'zzretry-txn-3']);
        $this->assertSame('pending', $row['status']);
    }

    public function testTransactionLinkedToIntentUntouchedWhenNotProcessing(): void
    {
        $intentId = $this->insertIntent('zzretry-intent-4', 'completed');
        $this->insertTransaction('zzretry-txn-4', 'completed', 'bkash-api', $intentId);

        $reverted = $this->txnRepo->reactivateForRetryByIntentId($intentId);

        $this->assertFalse($reverted);
        $row = $this->db->fetchOne("SELECT status FROM op_transactions WHERE trx_id = :t", ['t' => 'zzretry-txn-4']);
        $this->assertSame('completed', $row['status']);
    }
}
