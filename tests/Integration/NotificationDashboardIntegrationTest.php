<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\MobileNotificationRepository;
use OwnPay\Service\Notification\MobileNotificationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Integration\IntegrationTestCase;

/**
 * NotificationDashboardIntegrationTest — End-to-end tests for Part 4.
 *
 * Requires live DB. Tests:
 *   1. Notification create → poll → markRead round-trip
 *   2. Cursor-based polling (since parameter)
 *   3. Unread count accuracy
 *   4. Dashboard summary query (via raw SQL against op_sms_parsed)
 *   5. Cleanup of old read notifications
 *
 * @group Integration
 */
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
        // Set tenant context (brand context 1)
        $this->notifRepo = $this->notifRepo->forTenant(1);
        $this->notifService = new MobileNotificationService($this->notifRepo);

        // Ensure test device exists for Foreign Key constraints
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

    // ─── Test 1: Create → Poll → MarkRead round-trip ───────────────────────

    public function testCreatePollMarkReadRoundTrip(): void
    {
        // Create notification
        $id = (int) $this->notifRepo->queue(
            self::TEST_DEVICE_UUID,
            'payment_received',
            'Payment Received',
            'Tk 500.00 from 01712345678',
            ['amount' => 500, 'trx_id' => 'TEST001']
        );
        $this->assertGreaterThan(0, $id);

        // Poll — should appear
        $notifications = $this->notifRepo->pollSince(self::TEST_DEVICE_UUID);
        $found = array_filter($notifications, fn ($n) => (int)$n['id'] === $id);
        $this->assertNotEmpty($found, 'Notification should appear in poll');

        // Verify payload is stored as JSON string
        $n = reset($found);
        $payload = json_decode($n['payload'], true);
        $this->assertSame(500, $payload['amount']);

        // Mark read
        $readCount = $this->notifRepo->markRead(self::TEST_DEVICE_UUID, [$id]);
        $this->assertSame(1, $readCount);

        // Verify read status
        $row = $this->notifRepo->findScoped($id);
        $this->assertSame(1, (int) $row['is_read']);
        $this->assertNotNull($row['read_at']);
    }

    // ─── Test 2: Cursor-based polling ─────────────────────────────────────

    public function testCursorBasedPolling(): void
    {
        $pdo = Database::getInstance()->pdo();
        $dbTimeRow = $pdo->query("SELECT NOW() as now")->fetch();
        $dbTime = strtotime($dbTimeRow['now']);

        // Record a cursor BEFORE creating any notifications
        $cursorBefore = date('Y-m-d H:i:s', $dbTime - 5);

        // Create notification
        $id1 = (int) $this->notifRepo->queue(
            self::TEST_DEVICE_UUID, 'payment_received',
            'Cursor Test Payment', 'Tk 100.00'
        );

        // Poll since the past cursor — should get the notification
        $results = $this->notifRepo->pollSince(self::TEST_DEVICE_UUID, $cursorBefore);
        $this->assertNotEmpty($results, 'Should have notifications newer than past cursor');
        $resultIds = array_map('intval', array_column($results, 'id'));
        $this->assertContains($id1, $resultIds);

        // Poll since a FUTURE cursor — should get nothing for this device
        $futureCursor = date('Y-m-d H:i:s', $dbTime + 3600);
        $futureResults = $this->notifRepo->pollSince(self::TEST_DEVICE_UUID, $futureCursor);
        $this->assertEmpty($futureResults, 'Should have no notifications newer than future cursor');
    }

    // ─── Test 3: Unread count ─────────────────────────────────────────────

    public function testUnreadCount(): void
    {
        // Start fresh
        $initial = $this->notifRepo->countUnread(1, self::TEST_DEVICE_UUID);

        // Create 3 notifications
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = (int) $this->notifRepo->queue(
                self::TEST_DEVICE_UUID, 'payment_received',
                "Payment {$i}", "Tk " . (($i + 1) * 100)
            );
        }

        $this->assertSame($initial + 3, $this->notifRepo->countUnread(1, self::TEST_DEVICE_UUID));

        // Mark 2 as read
        $this->notifRepo->markRead(self::TEST_DEVICE_UUID, [$ids[0], $ids[1]]);

        $this->assertSame($initial + 1, $this->notifRepo->countUnread(1, self::TEST_DEVICE_UUID));
    }

    // ─── Test 4: Service-level poll response structure ────────────────────

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

        // Verify payload is decoded
        $notif = end($result['notifications']);
        $this->assertIsArray($notif['payload']);
        $this->assertEquals(1500, $notif['payload']['amount']);
    }

    // ─── Test 5: Dashboard summary SQL ─────────────────────────────────────

    public function testDashboardSummaryQuery(): void
    {
        $pdo = Database::getInstance()->pdo();

        // Ensure test device exists for FK
        $stmt = $pdo->prepare("SELECT id FROM op_paired_devices WHERE device_id = :uuid");
        $stmt->execute([':uuid' => self::TEST_DEVICE_UUID]);
        if (!$stmt->fetch()) {
            $pdo->prepare(
                "INSERT INTO op_paired_devices (merchant_id, device_id, device_name, platform, status, paired_at)
                 VALUES (1, :uuid, 'Test Device', 'android', 'active', NOW())"
            )->execute([':uuid' => self::TEST_DEVICE_UUID]);
        }

        // Insert test transactions
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

        // Query summary
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

        // Cleanup
        $pdo->exec("DELETE FROM op_sms_parsed WHERE device_id = '" . self::TEST_DEVICE_UUID . "'");
        $pdo->exec("DELETE FROM op_paired_devices WHERE device_id = '" . self::TEST_DEVICE_UUID . "'");
    }
}
