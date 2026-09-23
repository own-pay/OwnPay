<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Service\Payment\ManualPaymentVerificationService;

/**
 * Covers the server-side auto-verification path for manual (MFS / bank transfer) payments:
 * a customer-submitted TrxID is only allowed to settle a `pending_review` transaction when
 * the brand's own SMS inbox holds exactly one unmatched notification for that TrxID whose
 * amount matches to the cent.
 */
final class ManualPaymentAutoVerifyTest extends IntegrationTestCase
{
    private Database $db;
    private ManualPaymentVerificationService $service;

    private int $brandA = 99995;
    private int $brandB = 99996;
    private string $deviceA = 'manual-verify-device-a';
    private string $deviceB = 'manual-verify-device-b';

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();

        $c = new \OwnPay\Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($c);
        $c->instance(Database::class, $this->db);

        $this->service = $c->get(ManualPaymentVerificationService::class);

        $this->cleanupData();
        $this->setupBrands();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanupData();
        }
        parent::tearDown();
    }

    private function cleanupData(): void
    {
        $this->db->execute(
            "DELETE FROM op_sms_parsed WHERE device_id IN (:d1, :d2)",
            ['d1' => $this->deviceA, 'd2' => $this->deviceB]
        );
        $this->db->execute("DELETE FROM op_ledger_transactions WHERE merchant_id IN (99995, 99996)");
        $this->db->execute("DELETE FROM op_ledger_accounts WHERE merchant_id IN (99995, 99996)");
        $this->db->execute("DELETE FROM op_transactions WHERE merchant_id IN (99995, 99996)");
        $this->db->execute("DELETE FROM op_merchants WHERE id IN (99995, 99996)");
    }

    private function setupBrands(): void
    {
        foreach ([[$this->brandA, 'manual-verify-test-1'], [$this->brandB, 'manual-verify-test-2']] as [$mid, $slug]) {
            $exists = $this->db->fetchOne("SELECT id FROM op_merchants WHERE id = :mid LIMIT 1", ['mid' => $mid]);
            if ($exists === null) {
                $this->db->execute(
                    "INSERT INTO op_merchants (id, uuid, name, slug, email, status, is_platform, settings)
                     VALUES (:mid, :uuid, :name, :slug, :email, 'active', 0, '{}')",
                    [
                        'mid'   => $mid,
                        'uuid'  => 'mv-merchant-uuid-' . $mid,
                        'name'  => 'Manual Verify Test ' . $mid,
                        'slug'  => $slug,
                        'email' => $slug . '@test.com',
                    ]
                );
            }
        }
    }

    /**
     * Seeds a transaction for a brand.
     *
     * @param string $createdAt SQL expression for created_at (defaults to NOW(6)).
     * @param array<string, mixed>|null $metadata Optional decoded metadata.
     */
    private function seedTransaction(
        int $merchantId,
        string $uuid,
        string $amount,
        string $status = 'pending_review',
        string $createdAt = 'NOW(6)',
        ?array $metadata = null
    ): int {
        $this->db->execute(
            "INSERT INTO op_transactions
                (merchant_id, uuid, trx_id, amount, fee, net_amount, currency, gateway_slug, method, status, metadata, created_at)
             VALUES
                (:mid, :uuid, :trx, :amt, 0.00, :amt2, 'BDT', 'bkash-personal', 'manual', :status, :meta, {$createdAt})",
            [
                'mid'    => $merchantId,
                'uuid'   => $uuid,
                'trx'    => $uuid,
                'amt'    => $amount,
                'amt2'   => $amount,
                'status' => $status,
                'meta'   => $metadata === null ? null : (string) json_encode($metadata),
            ]
        );

        return (int) $this->db->fetchColumn("SELECT id FROM op_transactions WHERE uuid = :uuid", ['uuid' => $uuid]);
    }

    /**
     * Seeds a parsed SMS notification.
     *
     * @param string $receivedAt SQL expression for received_at (defaults to NOW(6)).
     */
    private function seedSms(
        int $merchantId,
        string $deviceId,
        ?string $trxId,
        string $amount,
        string $receivedAt = 'NOW(6)'
    ): int {
        $this->db->execute(
            "INSERT INTO op_sms_parsed
                (merchant_id, device_id, sender, body, amount, trx_id, gateway_slug, parser_type, match_status, received_at)
             VALUES
                (:mid, :did, 'bKash', 'Payment received notification', :amt, :trx, 'bKash', 'regex', 'pending', {$receivedAt})",
            [
                'mid' => $merchantId,
                'did' => $deviceId,
                'amt' => $amount,
                'trx' => $trxId,
            ]
        );

        return (int) $this->db->fetchColumn(
            "SELECT id FROM op_sms_parsed WHERE device_id = :did ORDER BY id DESC LIMIT 1",
            ['did' => $deviceId]
        );
    }

    public function testExactTrxIdAndAmountCompletesTransaction(): void
    {
        $trxRef = 'MVTRX1001';
        $txId = $this->seedTransaction($this->brandA, 'mv-txn-uuid-1001', '500.00');
        $smsId = $this->seedSms($this->brandA, $this->deviceA, $trxRef, '500.00');

        $verified = $this->service->attemptVerification($this->brandA, $txId, $trxRef);

        $this->assertTrue($verified);
        $tx = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = :id", ['id' => $txId]);
        $this->assertSame('completed', $tx['status']);

        $sms = $this->db->fetchOne("SELECT * FROM op_sms_parsed WHERE id = :id", ['id' => $smsId]);
        $this->assertSame('matched', $sms['match_status']);
        $this->assertSame($txId, (int) $sms['transaction_id']);

        $ledger = $this->db->fetchOne(
            "SELECT * FROM op_ledger_transactions WHERE merchant_id = :mid",
            ['mid' => $this->brandA]
        );
        $this->assertNotNull($ledger, 'ledger entry should be posted for the auto-verified payment');
    }

    public function testAmountMismatchDoesNotCompleteTransaction(): void
    {
        $trxRef = 'MVTRX1002';
        $txId = $this->seedTransaction($this->brandA, 'mv-txn-uuid-1002', '500.00');
        $smsId = $this->seedSms($this->brandA, $this->deviceA, $trxRef, '499.00');

        $verified = $this->service->attemptVerification($this->brandA, $txId, $trxRef);

        $this->assertFalse($verified);
        $tx = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = :id", ['id' => $txId]);
        $this->assertSame('pending_review', $tx['status']);

        $sms = $this->db->fetchOne("SELECT * FROM op_sms_parsed WHERE id = :id", ['id' => $smsId]);
        $this->assertSame('pending', $sms['match_status']);
    }

    public function testConvertedAmountMetadataIsAuthoritative(): void
    {
        $trxRef = 'MVTRX1003';
        $txId = $this->seedTransaction(
            $this->brandA,
            'mv-txn-uuid-1003',
            '100.00',
            'pending_review',
            'NOW(6)',
            ['converted_amount' => '500.00']
        );
        $this->seedSms($this->brandA, $this->deviceA, $trxRef, '500.00');

        $verified = $this->service->attemptVerification($this->brandA, $txId, $trxRef);

        $this->assertTrue($verified);
        $tx = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = :id", ['id' => $txId]);
        $this->assertSame('completed', $tx['status']);

        // converted_amount is authoritative for the match only: the ledger must still post
        // the transaction's own gross amount, matching the gateway callback path.
        $cashEntry = $this->db->fetchOne(
            "SELECT e.amount FROM op_ledger_entries e
             JOIN op_ledger_accounts a ON a.id = e.account_id
             JOIN op_ledger_transactions t ON t.id = e.ledger_transaction_id
             WHERE t.merchant_id = :mid AND a.name = 'CASH' AND e.type = 'debit'
             ORDER BY e.id DESC LIMIT 1",
            ['mid' => $this->brandA]
        );
        $this->assertNotNull($cashEntry, 'cash ledger entry should exist');
        $cashAmount = is_scalar($cashEntry['amount'] ?? null) ? (string) $cashEntry['amount'] : '';
        $this->assertSame(0, bccomp($cashAmount, '100.00', 2), 'ledger must post the transaction amount, not converted_amount');
    }

    public function testCrossTenantSmsIsNotMatched(): void
    {
        $trxRef = 'MVTRX1004';
        $txId = $this->seedTransaction($this->brandA, 'mv-txn-uuid-1004', '500.00');
        $this->seedSms($this->brandB, $this->deviceB, $trxRef, '500.00');

        $verified = $this->service->attemptVerification($this->brandA, $txId, $trxRef);

        $this->assertFalse($verified);
        $tx = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = :id", ['id' => $txId]);
        $this->assertSame('pending_review', $tx['status']);
    }

    public function testAmbiguousTrxIdIsNotMatched(): void
    {
        $trxRef = 'MVTRX1005';
        $txId = $this->seedTransaction($this->brandA, 'mv-txn-uuid-1005', '500.00');
        $this->seedSms($this->brandA, $this->deviceA, $trxRef, '500.00');
        $this->seedSms($this->brandA, $this->deviceA, $trxRef, '500.00');

        $verified = $this->service->attemptVerification($this->brandA, $txId, $trxRef);

        $this->assertFalse($verified);
        $tx = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = :id", ['id' => $txId]);
        $this->assertSame('pending_review', $tx['status']);
    }

    public function testTerminalTransactionIsNotCompleted(): void
    {
        $trxRef = 'MVTRX1006';
        $txId = $this->seedTransaction($this->brandA, 'mv-txn-uuid-1006', '500.00', 'completed');
        $this->seedSms($this->brandA, $this->deviceA, $trxRef, '500.00');

        $verified = $this->service->attemptVerification($this->brandA, $txId, $trxRef);

        $this->assertFalse($verified);
        $tx = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = :id", ['id' => $txId]);
        $this->assertSame('completed', $tx['status']);
    }

    public function testStaleSmsOutsideMatchWindowIsNotMatched(): void
    {
        $trxRef = 'MVTRX1007';
        $txId = $this->seedTransaction($this->brandA, 'mv-txn-uuid-1007', '500.00', 'pending_review', 'NOW(6)');
        $this->seedSms($this->brandA, $this->deviceA, $trxRef, '500.00', 'DATE_SUB(NOW(6), INTERVAL 2 HOUR)');

        $verified = $this->service->attemptVerification($this->brandA, $txId, $trxRef);

        $this->assertFalse($verified);
        $tx = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = :id", ['id' => $txId]);
        $this->assertSame('pending_review', $tx['status']);
    }

    public function testOneSmsCannotSettleTwoTransactions(): void
    {
        $trxRef = 'MVTRX1008';
        $firstTxId = $this->seedTransaction($this->brandA, 'mv-txn-uuid-1008a', '500.00');
        $secondTxId = $this->seedTransaction($this->brandA, 'mv-txn-uuid-1008b', '500.00');
        $this->seedSms($this->brandA, $this->deviceA, $trxRef, '500.00');

        $this->assertTrue($this->service->attemptVerification($this->brandA, $firstTxId, $trxRef));
        $this->assertFalse($this->service->attemptVerification($this->brandA, $secondTxId, $trxRef));

        $first = $this->db->fetchOne("SELECT status FROM op_transactions WHERE id = :id", ['id' => $firstTxId]);
        $second = $this->db->fetchOne("SELECT status FROM op_transactions WHERE id = :id", ['id' => $secondTxId]);
        $this->assertSame('completed', $first['status']);
        $this->assertSame('pending_review', $second['status']);

        $ledgerCount = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM op_ledger_transactions WHERE merchant_id = :mid",
            ['mid' => $this->brandA]
        );
        $this->assertSame(1, (int) $ledgerCount, 'a single SMS must post exactly one ledger transaction');
    }

    public function testListenerEntrypointCompletesTransaction(): void
    {
        $trxRef = 'MVTRX1009';
        $txId = $this->seedTransaction($this->brandA, 'mv-txn-uuid-1009', '500.00');
        $this->seedSms($this->brandA, $this->deviceA, $trxRef, '500.00');

        $txn = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = :id", ['id' => $txId]);
        $this->assertNotNull($txn);
        $this->service->onManualVerifySubmitted($txn, [
            'sender_number'  => '01700000000',
            'transaction_id' => $trxRef,
        ]);

        $after = $this->db->fetchOne("SELECT status FROM op_transactions WHERE id = :id", ['id' => $txId]);
        $this->assertSame('completed', $after['status']);
    }

    /**
     * Confirms the event wiring itself: with the app "installed", dispatching
     * `checkout.manual_verify.submitted` through the container triggers auto-verification.
     */
    public function testSubmittedEventWiringCompletesTransaction(): void
    {
        $marker = dirname(__DIR__, 2) . '/storage/.installed';
        $createdMarker = !file_exists($marker);
        if ($createdMarker) {
            file_put_contents($marker, '');
        }

        try {
            $c = new \OwnPay\Container();
            $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
            $bootstrap($c);
            $c->instance(Database::class, $this->db);

            $events = $c->get(\OwnPay\Event\EventManager::class);
            $events->doAction('system.boot');

            $trxRef = 'MVTRX1010';
            $txId = $this->seedTransaction($this->brandA, 'mv-txn-uuid-1010', '500.00');
            $this->seedSms($this->brandA, $this->deviceA, $trxRef, '500.00');

            $txn = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = :id", ['id' => $txId]);
            $this->assertNotNull($txn);
            $events->doAction('checkout.manual_verify.submitted', $txn, [
                'sender_number'  => '01700000000',
                'transaction_id' => $trxRef,
            ]);

            $after = $this->db->fetchOne("SELECT status FROM op_transactions WHERE id = :id", ['id' => $txId]);
            $this->assertSame('completed', $after['status'], 'manual_verify.submitted listener should auto-complete');
        } finally {
            if ($createdMarker && file_exists($marker)) {
                unlink($marker);
            }
        }
    }
}
