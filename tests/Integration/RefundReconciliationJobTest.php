<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Cron\RefundReconciliationJob;
use OwnPay\Event\EventManager;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Service\System\AuditLogger;
use OwnPay\Service\System\Logger;

final class RefundReconciliationJobTest extends IntegrationTestCase
{
    private Database $db;
    private RefundReconciliationJob $job;
    private EventManager $events;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            return;
        }

        $this->db = Database::getInstance();

        $this->db->execute("DELETE FROM op_refunds");
        $this->db->execute("DELETE FROM op_transactions");

        $merchant = $this->db->fetchOne("SELECT id FROM op_merchants WHERE id = 1 LIMIT 1");
        if ($merchant === null) {
            $this->db->execute(
                "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
                 VALUES (1, 'merchant-uuid-1', 'Test Merchant', 'test-merchant-1', 'test1@example.com', 'active', '{}')"
            );
        }

        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status)
             VALUES (7001, 1, 'tx-uuid-7001', 'OP-RECON-1', 'stripe', 100.00, 5.00, 95.00, 'BDT', 'completed')"
        );

        $this->events = new EventManager();
        $this->job = new RefundReconciliationJob(
            $this->db,
            $this->events,
            new AuditLogger(new AuditLogRepository($this->db), $this->events),
            new Logger('test')
        );
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_refunds");
            $this->db->execute("DELETE FROM op_transactions");
        }
        parent::tearDown();
    }

    private function insertRefund(int $id, string $status, string $createdAtExpr): void
    {
        $this->db->execute(
            "INSERT INTO op_refunds (id, merchant_id, transaction_id, uuid, amount, reason, status, created_at)
             VALUES (:id, 1, 7001, :uuid, 40.00, 'test', :status, {$createdAtExpr})",
            ['id' => $id, 'uuid' => "refund-uuid-{$id}", 'status' => $status]
        );
    }

    public function testStalePendingRefundIsAutoFailed(): void
    {
        $this->insertRefund(8001, 'pending', "DATE_SUB(NOW(), INTERVAL 25 HOUR)");
        $this->insertRefund(8002, 'pending', "DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $this->insertRefund(8003, 'completed', "DATE_SUB(NOW(), INTERVAL 48 HOUR)");

        $fired = [];
        $this->events->addAction('payment.refund.reconciliation_failed', function (...$args) use (&$fired): void {
            $fired[] = $args[0] ?? null;
        });

        $result = $this->job->run();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['total']);

        $stale = $this->db->fetchOne("SELECT status FROM op_refunds WHERE id = 8001");
        $this->assertNotNull($stale);
        $this->assertSame('failed', $stale['status'], 'Stale pending refund must be auto-failed');

        $recent = $this->db->fetchOne("SELECT status FROM op_refunds WHERE id = 8002");
        $this->assertNotNull($recent);
        $this->assertSame('pending', $recent['status'], 'Recent pending refund must be left alone');

        $completed = $this->db->fetchOne("SELECT status FROM op_refunds WHERE id = 8003");
        $this->assertNotNull($completed);
        $this->assertSame('completed', $completed['status'], 'Terminal refunds must never be touched');

        $this->assertCount(1, $fired, 'Notification event must fire once per auto-failed refund');

        $auditRow = $this->db->fetchOne(
            "SELECT id FROM op_audit_logs WHERE action = 'refund.reconciliation_failed' AND entity_id = 8001 ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($auditRow, 'Auto-fail must leave an audit trail');
    }

    public function testIdempotentAcrossRuns(): void
    {
        $this->insertRefund(8004, 'pending', "DATE_SUB(NOW(), INTERVAL 30 HOUR)");

        $first = $this->job->run();
        $second = $this->job->run();

        $this->assertSame(1, $first['failed']);
        $this->assertSame(0, $second['failed'], 'A second run must not re-fail or re-audit the same refund');
    }
}
