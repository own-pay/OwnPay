<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\WebhookInboundProcessor;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Repository\LedgerRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Service\Payment\LedgerService;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Service\System\AuditLogger;
use OwnPay\Service\System\Logger;

class WebhookIdempotencyTest extends IntegrationTestCase
{
    private const SECRET = 'whsec_test_secret_for_idempotency';
    private const MERCHANT_ID = 1;

    private Database $db;
    private WebhookInboundProcessor $processor;
    private TransactionRepository $transactionRepo;
    private TransactionService $transactionService;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            return;
        }

        $this->db = Database::getInstance();

        // Migration 009 required for dedup_key column
        $hasDedupKey = $this->db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'op_webhook_deliveries'
               AND COLUMN_NAME = 'dedup_key'"
        );
        if ($hasDedupKey === null) {
            $this->markTestSkipped('Test DB missing migration 009 (op_webhook_deliveries.dedup_key).');
        }

        $this->db->execute("DELETE FROM op_webhook_deliveries");
        $this->db->execute("DELETE FROM op_ledger_entries");
        $this->db->execute("DELETE FROM op_ledger_transactions");
        $this->db->execute("DELETE FROM op_ledger_accounts");
        $this->db->execute("DELETE FROM op_refunds");
        $this->db->execute("DELETE FROM op_transactions");

        $merchant = $this->db->fetchOne("SELECT * FROM op_merchants WHERE id = 1 LIMIT 1");
        if ($merchant === null) {
            $this->db->execute(
                "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
                 VALUES (1, 'merchant-uuid-1', 'Test Merchant', 'test-merchant-1', 'test1@example.com', 'active', '{}')"
            );
        }

        $events = new EventManager();
        $this->transactionRepo = new TransactionRepository($this->db);
        $auditRepo = new AuditLogRepository($this->db);
        $this->transactionService = new TransactionService($this->transactionRepo, $events, $auditRepo);
        $ledgerRepo = new LedgerRepository($this->db);
        $ledgerService = new LedgerService($ledgerRepo, $events, $this->transactionRepo);

        $this->processor = new WebhookInboundProcessor(
            $this->db,
            $this->transactionService,
            $this->transactionRepo,
            new AuditLogger($auditRepo, $events),
            new Logger('test'),
            $ledgerService
        );
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_webhook_deliveries");
            $this->db->execute("DELETE FROM op_ledger_entries");
            $this->db->execute("DELETE FROM op_ledger_transactions");
            $this->db->execute("DELETE FROM op_ledger_accounts");
            $this->db->execute("DELETE FROM op_refunds");
            $this->db->execute("DELETE FROM op_transactions");
        }
        parent::tearDown();
    }

    private function signedHeaders(string $rawBody, string $eventId, string $eventType): array
    {
        $ts = (string) time();
        return [
            'X-OP-Signature' => hash_hmac('sha256', "{$ts}.{$rawBody}", self::SECRET),
            'X-OP-Timestamp' => $ts,
            'X-OP-Event-Id'  => $eventId,
            'X-OP-Event'     => $eventType,
        ];
    }

    private function insertTransaction(int $id, string $trxId, string $amount, string $status, ?string $metadata = null): void
    {
        /** @var numeric-string $amount */
        $net = bcsub($amount, '5.00', 2);
        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status, metadata)
             VALUES (:id, :mid, :uuid, :trx, 'stripe', :amt, 5.00, :net, 'BDT', :st, :meta)",
            [
                'id'   => $id,
                'mid'  => self::MERCHANT_ID,
                'uuid' => "tx-uuid-{$id}",
                'trx'  => $trxId,
                'amt'  => $amount,
                'net'  => $net,
                'st'   => $status,
                'meta' => $metadata,
            ]
        );
    }

    public function testDuplicateDeliveryIsProcessedExactlyOnce(): void
    {
        $this->insertTransaction(5001, 'OP-IDEM-1', '150.00', 'pending');

        $rawBody = json_encode(['data' => ['reference' => 'OP-IDEM-1', 'amount' => '150.00']]);
        $this->assertIsString($rawBody);
        $headers = $this->signedHeaders($rawBody, 'evt_dup_1', 'payment.completed');

        $first = $this->processor->process($rawBody, $headers, self::SECRET, self::MERCHANT_ID);
        $second = $this->processor->process($rawBody, $headers, self::SECRET, self::MERCHANT_ID);

        $this->assertTrue($first['accepted']);
        $this->assertTrue($second['accepted']);
        $this->assertStringContainsString('already processed', $second['message']);

        $deliveries = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM op_webhook_deliveries WHERE direction = 'inbound'"
        );
        $this->assertSame(1, (int) $deliveries, 'Duplicate delivery must not create a second inbound row');

        $txn = $this->transactionRepo->forTenant(self::MERCHANT_ID)->findScoped(5001);
        $this->assertNotNull($txn);
        $this->assertSame('completed', $txn['status']);

        $ledgerCount = $this->db->fetchColumn("SELECT COUNT(*) FROM op_ledger_transactions");
        $this->assertSame(1, (int) $ledgerCount, 'Payment must be posted to the ledger exactly once');
    }

    public function testUniqueIndexIsTheConcurrencyArbiter(): void
    {
        $hash = hash('sha256', 'race-payload');

        $this->db->execute(
            "INSERT INTO op_webhook_deliveries (merchant_id, gateway, event, direction, status, payload_hash, attempt, created_at)
             VALUES (1, 'system', 'payment.completed', 'inbound', 'received', :hash, 1, NOW(6))",
            ['hash' => $hash]
        );

        // Racing duplicate must die on the unique index regardless of application-level checks
        try {
            $this->db->execute(
                "INSERT INTO op_webhook_deliveries (merchant_id, gateway, event, direction, status, payload_hash, attempt, created_at)
                 VALUES (1, 'system', 'payment.completed', 'inbound', 'received', :hash, 1, NOW(6))",
                ['hash' => $hash]
            );
            $this->fail('Expected duplicate-key violation for identical inbound delivery');
        } catch (\PDOException $e) {
            $this->assertSame(1062, (int) ($e->errorInfo[1] ?? 0));
        }

        // Outbound retries legitimately reuse payload hash (dedup_key is NULL for outbound)
        $this->db->execute(
            "INSERT INTO op_webhook_deliveries (merchant_id, gateway, event, url, direction, status, payload_hash, attempt, created_at)
             VALUES (1, 'system', 'payment.completed', 'https://example.com/hook', 'outbound', 'failed', :hash, 1, NOW(6))",
            ['hash' => $hash]
        );
        $this->db->execute(
            "INSERT INTO op_webhook_deliveries (merchant_id, gateway, event, url, direction, status, payload_hash, attempt, created_at)
             VALUES (1, 'system', 'payment.completed', 'https://example.com/hook', 'outbound', 'failed', :hash, 2, NOW(6))",
            ['hash' => $hash]
        );

        $outbound = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM op_webhook_deliveries WHERE direction = 'outbound' AND payload_hash = :hash",
            ['hash' => $hash]
        );
        $this->assertSame(2, (int) $outbound);
    }

    public function testDistinctEventsForSameTransactionCompleteItOnce(): void
    {
        $this->insertTransaction(5002, 'OP-IDEM-2', '99.50', 'pending');

        $bodyA = json_encode(['data' => ['reference' => 'OP-IDEM-2', 'amount' => '99.50'], 'nonce' => 'a']);
        $bodyB = json_encode(['data' => ['reference' => 'OP-IDEM-2', 'amount' => '99.50'], 'nonce' => 'b']);
        $this->assertIsString($bodyA);
        $this->assertIsString($bodyB);

        $first = $this->processor->process($bodyA, $this->signedHeaders($bodyA, 'evt_a', 'payment.completed'), self::SECRET, self::MERCHANT_ID);
        $second = $this->processor->process($bodyB, $this->signedHeaders($bodyB, 'evt_b', 'payment.completed'), self::SECRET, self::MERCHANT_ID);

        $this->assertTrue($first['accepted']);
        $this->assertTrue($second['accepted']);

        $txn = $this->transactionRepo->forTenant(self::MERCHANT_ID)->findScoped(5002);
        $this->assertNotNull($txn);
        $this->assertSame('completed', $txn['status']);

        $ledgerCount = $this->db->fetchColumn("SELECT COUNT(*) FROM op_ledger_transactions");
        $this->assertSame(1, (int) $ledgerCount, 'Second event must not double-post the payment');
    }

    public function testFailedEventCannotDowngradeCompletedTransaction(): void
    {
        $this->insertTransaction(5003, 'OP-IDEM-3', '40.00', 'completed');

        $rawBody = json_encode(['data' => ['reference' => 'OP-IDEM-3']]);
        $this->assertIsString($rawBody);

        $result = $this->processor->process($rawBody, $this->signedHeaders($rawBody, 'evt_fail_1', 'payment.failed'), self::SECRET, self::MERCHANT_ID);
        $this->assertTrue($result['accepted']);

        $txn = $this->transactionRepo->forTenant(self::MERCHANT_ID)->findScoped(5003);
        $this->assertNotNull($txn);
        $this->assertSame('completed', $txn['status'], 'Terminal transaction must not be downgraded to failed');
    }

    public function testCompletedEventCannotResurrectFailedTransaction(): void
    {
        $this->insertTransaction(5004, 'OP-IDEM-4', '75.00', 'failed');

        $rawBody = json_encode(['data' => ['reference' => 'OP-IDEM-4', 'amount' => '75.00']]);
        $this->assertIsString($rawBody);

        $result = $this->processor->process($rawBody, $this->signedHeaders($rawBody, 'evt_res_1', 'payment.completed'), self::SECRET, self::MERCHANT_ID);
        $this->assertTrue($result['accepted']);

        $txn = $this->transactionRepo->forTenant(self::MERCHANT_ID)->findScoped(5004);
        $this->assertNotNull($txn);
        $this->assertSame('failed', $txn['status'], 'Terminal transaction must not be resurrected to completed');

        $ledgerCount = $this->db->fetchColumn("SELECT COUNT(*) FROM op_ledger_transactions");
        $this->assertSame(0, (int) $ledgerCount);
    }

    public function testRefundEventRejectsAmountExceedingCharge(): void
    {
        $this->insertTransaction(5005, 'OP-IDEM-5', '100.00', 'completed');

        $rawBody = json_encode(['data' => ['reference' => 'OP-IDEM-5', 'refund_amount' => '250.00']]);
        $this->assertIsString($rawBody);

        $result = $this->processor->process($rawBody, $this->signedHeaders($rawBody, 'evt_ref_1', 'refund.completed'), self::SECRET, self::MERCHANT_ID);
        $this->assertTrue($result['accepted']);

        $txn = $this->transactionRepo->forTenant(self::MERCHANT_ID)->findScoped(5005);
        $this->assertNotNull($txn);
        $this->assertSame('completed', $txn['status'], 'Oversized refund must not transition the transaction');

        $refunds = $this->db->fetchColumn("SELECT COUNT(*) FROM op_refunds");
        $this->assertSame(0, (int) $refunds, 'Oversized refund must not create a refund record');

        $ledgerCount = $this->db->fetchColumn("SELECT COUNT(*) FROM op_ledger_transactions");
        $this->assertSame(0, (int) $ledgerCount, 'Oversized refund must not reach the ledger');
    }

    public function testMarkCompletedIfNotTerminalGuardsTerminalStates(): void
    {
        $this->insertTransaction(5006, 'OP-IDEM-6', '10.00', 'failed');
        $this->insertTransaction(5007, 'OP-IDEM-7', '10.00', 'pending');

        $repo = $this->transactionRepo->forTenant(self::MERCHANT_ID);

        $this->assertSame(0, $repo->markCompletedIfNotTerminal(5006));
        $failed = $repo->findScoped(5006);
        $this->assertNotNull($failed);
        $this->assertSame('failed', $failed['status']);

        $this->assertSame(1, $repo->markCompletedIfNotTerminal(5007));
        $completed = $repo->findScoped(5007);
        $this->assertNotNull($completed);
        $this->assertSame('completed', $completed['status']);
        $this->assertNotNull($completed['completed_at']);
    }

    public function testFailPreservesExistingMetadata(): void
    {
        $this->insertTransaction(5008, 'OP-IDEM-8', '20.00', 'pending', '{"invoice_id": 77}');

        $this->transactionService->fail(5008, self::MERCHANT_ID, 'gateway timeout');

        $txn = $this->transactionRepo->forTenant(self::MERCHANT_ID)->findScoped(5008);
        $this->assertNotNull($txn);
        $this->assertSame('failed', $txn['status']);

        $this->assertIsString($txn['metadata']);
        $meta = json_decode($txn['metadata'], true);
        $this->assertIsArray($meta);
        $this->assertSame(77, $meta['invoice_id'] ?? null, 'fail() must merge metadata, not overwrite it - invoice linkage was lost');
        $this->assertSame('gateway timeout', $meta['failure_reason'] ?? null);
    }
}
