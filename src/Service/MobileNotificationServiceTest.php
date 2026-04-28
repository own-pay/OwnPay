<?php

declare(strict_types=1);

namespace OwnPay\Service;

use OwnPay\Service\MobileNotificationService;
use PHPUnit\Framework\TestCase;

/**
 * MobileNotificationServiceTest — Unit tests for the notification service.
 *
 * Tests cover:
 *   - Payment notification queueing (credit/debit/unknown)
 *   - Notification body formatting
 *   - Poll response structure
 *   - Mark-as-read passthrough
 *   - Cleanup passthrough
 */
final class MobileNotificationServiceTest extends TestCase
{
    // ─── Queue Tests ─────────────────────────────────────────────────

    public function testQueueCreditNotification(): void
    {
        $repo = $this->stubRepo();
        $service = new MobileNotificationService($repo);

        $id = $service->queuePaymentNotification(
            'device-001', 'credit', 500.0, '01712345678', 'ABC123', 'bKash'
        );

        $this->assertSame(1, $id);
        $this->assertSame('payment_received', $repo->lastType);
        $this->assertSame('Payment Received', $repo->lastTitle);
        $this->assertStringContainsString('Tk 500.00', $repo->lastBody);
        $this->assertStringContainsString('from 01712345678', $repo->lastBody);
        $this->assertStringContainsString('ABC123', $repo->lastBody);
    }

    public function testQueueDebitNotification(): void
    {
        $repo = $this->stubRepo();
        $service = new MobileNotificationService($repo);

        $id = $service->queuePaymentNotification(
            'device-001', 'debit', 300.0, '01612345678', 'DEF456'
        );

        $this->assertSame(1, $id);
        $this->assertSame('payment_sent', $repo->lastType);
        $this->assertSame('Payment Sent', $repo->lastTitle);
        $this->assertStringContainsString('to 01612345678', $repo->lastBody);
    }

    public function testQueueUnknownTypeNotification(): void
    {
        $repo = $this->stubRepo();
        $service = new MobileNotificationService($repo);

        $id = $service->queuePaymentNotification(
            'device-001', 'unknown', 1000.0
        );

        $this->assertSame('payment_detected', $repo->lastType);
        $this->assertSame('Transaction Detected', $repo->lastTitle);
        $this->assertStringContainsString('Tk 1,000.00', $repo->lastBody);
    }

    public function testQueueNotificationWithoutAmount(): void
    {
        $repo = $this->stubRepo();
        $service = new MobileNotificationService($repo);

        $id = $service->queuePaymentNotification('device-001', 'credit');

        $this->assertSame('New transaction detected.', $repo->lastBody);
    }

    // ─── Poll Tests ──────────────────────────────────────────────────

    public function testPollReturnsCorrectStructure(): void
    {
        $repo = $this->stubRepo(notifications: [
            ['id' => 1, 'type' => 'payment_received', 'title' => 'Payment', 'body' => 'Tk 500', 'payload' => '{"amount":500}', 'is_read' => 0, 'created_at' => '2026-04-27 10:00:00'],
        ], unreadCount: 3);

        $service = new MobileNotificationService($repo);
        $result = $service->poll('device-001', '2026-04-27T09:00:00Z');

        $this->assertArrayHasKey('notifications', $result);
        $this->assertArrayHasKey('unread_count', $result);
        $this->assertArrayHasKey('poll_interval_seconds', $result);
        $this->assertSame(3, $result['unread_count']);
        $this->assertSame(10, $result['poll_interval_seconds']);
        $this->assertCount(1, $result['notifications']);
        // Verify payload was decoded from JSON string to array
        $this->assertIsArray($result['notifications'][0]['payload']);
        $this->assertSame(500, $result['notifications'][0]['payload']['amount']);
    }

    public function testPollWithNoNotifications(): void
    {
        $repo = $this->stubRepo(notifications: [], unreadCount: 0);
        $service = new MobileNotificationService($repo);

        $result = $service->poll('device-001');

        $this->assertEmpty($result['notifications']);
        $this->assertSame(0, $result['unread_count']);
    }

    // ─── MarkRead Tests ──────────────────────────────────────────────

    public function testMarkReadPassthrough(): void
    {
        $repo = $this->stubRepo(markReadCount: 3);
        $service = new MobileNotificationService($repo);

        $count = $service->markRead('device-001', [1, 2, 3]);
        $this->assertSame(3, $count);
    }

    // ─── Cleanup Tests ───────────────────────────────────────────────

    public function testCleanupPassthrough(): void
    {
        $repo = $this->stubRepo(purgeCount: 5);
        $service = new MobileNotificationService($repo);

        $count = $service->cleanup(7);
        $this->assertSame(5, $count);
    }

    // ─── Stub ────────────────────────────────────────────────────────

    private function stubRepo(
        array $notifications = [],
        int $unreadCount = 0,
        int $markReadCount = 0,
        int $purgeCount = 0,
    ): object {
        return new class($notifications, $unreadCount, $markReadCount, $purgeCount) {
            public ?string $lastType = null;
            public ?string $lastTitle = null;
            public ?string $lastBody = null;
            public ?array $lastPayload = null;

            public function __construct(
                private array $notifications,
                private int $unreadCount,
                private int $markReadCount,
                private int $purgeCount,
            ) {}

            public function create(string $deviceUuid, string $type, string $title, string $body = '', array $payload = []): int {
                $this->lastType = $type;
                $this->lastTitle = $title;
                $this->lastBody = $body;
                $this->lastPayload = $payload;
                return 1;
            }

            public function pollSince(string $deviceUuid, ?string $since = null, int $limit = 50): array {
                return $this->notifications;
            }

            public function countUnread(string $deviceUuid): int {
                return $this->unreadCount;
            }

            public function markRead(string $deviceUuid, array $ids): int {
                return $this->markReadCount;
            }

            public function purgeOldRead(int $olderThanDays = 7): int {
                return $this->purgeCount;
            }
        };
    }
}
