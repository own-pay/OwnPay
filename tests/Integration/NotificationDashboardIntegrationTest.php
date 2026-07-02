<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\MobileNotificationRepository;
use OwnPay\Service\Notification\MobileNotificationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
final class NotificationDashboardIntegrationTest extends IntegrationTestCase
{
    private const TEST_DEVICE_UUID = 'integ-notif-test-0000';

    private MobileNotificationRepository $notifRepo;
    private MobileNotificationService $notifService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifRepo = new MobileNotificationRepository(Database::getInstance());
        $this->notifRepo = $this->notifRepo->forTenant(1);
        $this->notifService = new MobileNotificationService($this->notifRepo);

        $pdo = Database::getInstance()->pdo();
        $pdo->exec("DELETE FROM op_mobile_notifications WHERE device_uuid = '" . self::TEST_DEVICE_UUID . "'");
        $pdo->exec("DELETE FROM op_paired_devices WHERE device_id = '" . self::TEST_DEVICE_UUID . "'");

        $pdo->prepare(
            "INSERT INTO op_paired_devices (merchant_id, device_id, device_name, platform, status, paired_at)
             VALUES (1, :uuid, 'Test Device', 'android', 'active', NOW())"
        )->execute([':uuid' => self::TEST_DEVICE_UUID]);
    }

    protected function tearDown(): void
    {
        if (!static::$dbAvailable) {
            return;
        }

        $pdo = Database::getInstance()->pdo();
        $pdo->exec("DELETE FROM op_mobile_notifications WHERE device_uuid = '" . self::TEST_DEVICE_UUID . "'");
        $pdo->exec("DELETE FROM op_paired_devices WHERE device_id = '" . self::TEST_DEVICE_UUID . "'");

        parent::tearDown();
    }

    public function testCreatePollMarkReadRoundTrip(): void
    {
        $id = (int) $this->notifRepo->queue(
            self::TEST_DEVICE_UUID,
            'payment_received',
            'Payment Received',
            'Tk 500.00 from 01712345678',
            ['amount' => 500, 'trx_id' => 'TEST001']
        );
        $this->assertGreaterThan(0, $id);

        $notifications = $this->notifRepo->pollSince(self::TEST_DEVICE_UUID);
        $found = array_filter($notifications, fn ($n) => (int)$n['id'] === $id);
        $this->assertNotEmpty($found, 'Notification should appear in poll');

        $n = reset($found);
        $payload = json_decode($n['payload'], true);
        $this->assertSame(500, $payload['amount']);

        $readCount = $this->notifRepo->markRead(self::TEST_DEVICE_UUID, [$id]);
        $this->assertSame(1, $readCount);

        $row = $this->notifRepo->findScoped($id);
        $this->assertSame(1, (int) $row['is_read']);
        $this->assertNotNull($row['read_at']);
    }

    public function testCursorBasedPolling(): void
    {
        $pdo = Database::getInstance()->pdo();
        $dbTimeRow = $pdo->query("SELECT NOW() as now")->fetch();
        $dbTime = strtotime($dbTimeRow['now']);

        $cursorBefore = date('Y-m-d H:i:s', $dbTime - 5);

        $id1 = (int) $this->notifRepo->queue(
            self::TEST_DEVICE_UUID, 'payment_received',
            'Cursor Test Payment', 'Tk 100.00'
        );

        $results = $this->notifRepo->pollSince(self::TEST_DEVICE_UUID, $cursorBefore);
        $this->assertNotEmpty($results, 'Should have notifications newer than past cursor');
        $resultIds = array_map('intval', array_column($results, 'id'));
        $this->assertContains($id1, $resultIds);

        $futureCursor = date('Y-m-d H:i:s', $dbTime + 3600);
        $futureResults = $this->notifRepo->pollSince(self::TEST_DEVICE_UUID, $futureCursor);
        $this->assertEmpty($futureResults, 'Should have no notifications newer than future cursor');
    }

    public function testUnreadCount(): void
    {
        $initial = $this->notifRepo->countUnread(1, self::TEST_DEVICE_UUID);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = (int) $this->notifRepo->queue(
                self::TEST_DEVICE_UUID, 'payment_received',
                "Payment {$i}", "Tk " . (($i + 1) * 100)
            );
        }

        $this->assertSame($initial + 3, $this->notifRepo->countUnread(1, self::TEST_DEVICE_UUID));

        $this->notifRepo->markRead(self::TEST_DEVICE_UUID, [$ids[0], $ids[1]]);

        $this->assertSame($initial + 1, $this->notifRepo->countUnread(1, self::TEST_DEVICE_UUID));
    }

    public function testServicePollResponseStructure(): void
    {
        $this->notifService->queuePaymentNotification(
            self::TEST_DEVICE_UUID, 'credit', 1500.0, '01712345678', 'SVC001', 'bKash'
        );

        $result = $this->notifService->poll(self::TEST_DEVICE_UUID);

        $this->assertArrayHasKey('notifications', $result);
        $this->assertArrayHasKey('unread_count', $result);
        $this->assertArrayHasKey('poll_interval_seconds', $result);
        $this->assertSame(10, $result['poll_interval_seconds']);
        $this->assertGreaterThanOrEqual(1, $result['unread_count']);

        $notif = end($result['notifications']);
        $this->assertIsArray($notif['payload']);
        $this->assertEquals(1500, $notif['payload']['amount']);
    }

    public function testDashboardSummaryQuery(): void
    {
        $pdo = Database::getInstance()->pdo();

        $stmt = $pdo->prepare("SELECT id FROM op_paired_devices WHERE device_id = :uuid");
        $stmt->execute([':uuid' => self::TEST_DEVICE_UUID]);
        if (!$stmt->fetch()) {
            $pdo->prepare(
                "INSERT INTO op_paired_devices (merchant_id, device_id, device_name, platform, status, paired_at)
                 VALUES (1, :uuid, 'Test Device', 'android', 'active', NOW())"
            )->execute([':uuid' => self::TEST_DEVICE_UUID]);
        }

        $pdo->prepare(
            "INSERT INTO op_sms_parsed (device_id, merchant_id, sender, received_at, encrypted_raw,
             amount, parsed_type, parser_type, parse_confidence, match_status)
             VALUES (:uuid, 1, 'bKash', NOW(), 'test', 500.00, 'credit', 'regex', 'high', 'accepted')"
        )->execute([':uuid' => self::TEST_DEVICE_UUID]);

        $pdo->prepare(
            "INSERT INTO op_sms_parsed (device_id, merchant_id, sender, received_at, encrypted_raw,
             amount, parsed_type, parser_type, parse_confidence, match_status)
             VALUES (:uuid, 1, 'Nagad', NOW(), 'test', 200.00, 'debit', 'heuristic', 'medium', 'accepted')"
        )->execute([':uuid' => self::TEST_DEVICE_UUID]);

        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN parsed_type = 'credit' THEN amount ELSE 0 END), 0) AS total_received,
                COALESCE(SUM(CASE WHEN parsed_type = 'debit'  THEN amount ELSE 0 END), 0) AS total_sent,
                COALESCE(SUM(CASE WHEN parsed_type = 'credit' THEN 1 ELSE 0 END), 0) AS credit_count,
                COALESCE(SUM(CASE WHEN parsed_type = 'debit'  THEN 1 ELSE 0 END), 0) AS debit_count
             FROM op_sms_parsed
             WHERE merchant_id = 1 AND match_status = 'accepted' AND DATE(received_at) = CURDATE()"
        );
        $stmt->execute();
        $summary = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertGreaterThanOrEqual(500.0, (float) $summary['total_received']);
        $this->assertGreaterThanOrEqual(200.0, (float) $summary['total_sent']);
        $this->assertGreaterThanOrEqual(1, (int) $summary['credit_count']);
        $this->assertGreaterThanOrEqual(1, (int) $summary['debit_count']);

        $pdo->exec("DELETE FROM op_sms_parsed WHERE device_id = '" . self::TEST_DEVICE_UUID . "'");
        $pdo->exec("DELETE FROM op_paired_devices WHERE device_id = '" . self::TEST_DEVICE_UUID . "'");
    }
}
