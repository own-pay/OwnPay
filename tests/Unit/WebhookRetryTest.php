<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use OwnPay\Repository\WebhookEventRepository;
use OwnPay\Repository\WebhookRepository;
use OwnPay\Repository\CommLogRepository;
use OwnPay\Event\EventManager;
use OwnPay\Service\Payment\WebhookService;
use OwnPay\Cron\WebhookRetryCron;
use OwnPay\Core\Database;

/**
 * Class WebhookRetryTest
 *
 * Verifies webhook queueing, exponential backoffs, DLQ quarantining, and background retries.
 */
#[AllowMockObjectsWithoutExpectations]
class WebhookRetryTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&Database
     */
    private $dbMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&\PDOStatement
     */
    private $stmtMock;

    /**
     * @var WebhookRepository
     */
    private WebhookRepository $webhooks;

    /**
     * @var CommLogRepository
     */
    private CommLogRepository $commLog;

    /**
     * @var EventManager
     */
    private EventManager $events;

    /**
     * @var WebhookEventRepository
     */
    private WebhookEventRepository $webhookEvents;

    /**
     * @var WebhookService
     */
    private WebhookService $service;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(Database::class);
        $this->stmtMock = $this->createMock(\PDOStatement::class);
        $this->webhooks = new WebhookRepository($this->dbMock);
        $this->commLog = new CommLogRepository($this->dbMock);
        $this->events = new EventManager();
        $this->webhookEvents = new WebhookEventRepository($this->dbMock);

        $this->service = new WebhookService(
            $this->webhooks,
            $this->commLog,
            $this->events,
            $this->webhookEvents
        );
    }

    public function testDispatchCreatesPendingWebhookEvent(): void
    {
        $merchantId = 1;
        $eventType = 'payment.completed';
        $payload = ['id' => 'OP-12345'];

        $webhookMock = [
            'id' => 10,
            'url' => 'https://merchant.example.com/webhook',
            'secret' => 'wh_secret_key',
            'merchant_id' => $merchantId,
        ];

        // 1. Mock listing active webhooks (fetchAll op_webhooks)
        // 2. Mock finding event (fetchOne op_webhook_events)
        $this->dbMock->method('fetchAll')
            ->willReturnCallback(function (string $query, array $params = []) use ($webhookMock) {
                if (str_contains($query, 'op_webhooks')) {
                    return [$webhookMock];
                }
                return [];
            });

        $this->dbMock->method('fetchOne')
            ->willReturnCallback(function (string $query, array $params = []) {
                if (str_contains($query, 'op_webhook_events')) {
                    return ['attempts' => 0];
                }
                return null;
            });

        // 3. Mock inserting the webhook event record & comm log
        $this->dbMock->method('insert')
            ->willReturnCallback(function (string $query, array $data = []) use ($webhookMock, $eventType, $payload) {
                if (str_contains($query, 'op_webhook_events')) {
                    $this->assertSame($webhookMock['id'], $data['webhook_id']);
                    $this->assertSame($eventType, $data['event_type']);
                    $this->assertSame('pending', $data['status']);
                    $this->assertSame(0, $data['attempts']);
                    $this->assertSame(json_encode($payload), $data['payload']);
                    return '15';
                }
                if (str_contains($query, 'op_comm_log')) {
                    return '101';
                }
                return '1';
            });

        // 4. Mock execution of update & execute
        $this->dbMock->method('update')->willReturn(1);
        $this->dbMock->method('execute')->willReturn($this->stmtMock);

        $this->service->dispatch($merchantId, $eventType, $payload);
    }

    public function testDeliverFailureSchedulesRetry(): void
    {
        $webhook = [
            'id' => 10,
            'url' => 'https://example.com:9999/webhook',
            'secret' => 'wh_secret_key',
            'merchant_id' => 1,
        ];

        $eventId = 20;

        $this->dbMock->method('fetchOne')
            ->willReturnCallback(function (string $query, array $params = []) {
                if (str_contains($query, 'op_webhook_events')) {
                    return ['attempts' => 0];
                }
                return null;
            });

        $this->dbMock->method('insert')->willReturn('102');
        $this->dbMock->method('update')->willReturn(1);

        $executeCount = 0;
        $this->dbMock->method('execute')
            ->willReturnCallback(function (string $query, array $params) use ($eventId, &$executeCount) {
                $executeCount++;
                if (str_contains($query, 'op_webhook_delivery_logs')) {
                    $this->assertSame($eventId, $params['eid']);
                } elseif (str_contains($query, 'UPDATE op_webhook_events')) {
                    $this->assertSame($eventId, $params['id']);
                    $this->assertSame(1, $params['att']);
                    $this->assertSame('failed', $params['st']);
                    $this->assertNotEmpty($params['next']);
                }
                return $this->stmtMock;
            });

        $this->service->deliver($webhook, 'payment.completed', ['id' => 'OP-123'], $eventId);
        $this->assertSame(2, $executeCount);
    }

    public function testDeliverFailureQuarantinesToDLQ(): void
    {
        $webhook = [
            'id' => 10,
            'url' => 'https://example.com/webhook',
            'secret' => 'wh_secret_key',
            'merchant_id' => 1,
        ];

        $eventId = 30;

        $this->dbMock->method('fetchOne')
            ->willReturnCallback(function (string $query, array $params = []) {
                if (str_contains($query, 'op_webhook_events')) {
                    return ['attempts' => 6];
                }
                return null;
            });

        $this->dbMock->method('insert')->willReturn('103');
        $this->dbMock->method('update')->willReturn(1);

        $executeCount = 0;
        $this->dbMock->method('execute')
            ->willReturnCallback(function (string $query, array $params) use ($eventId, &$executeCount) {
                $executeCount++;
                if (str_contains($query, 'op_webhook_delivery_logs')) {
                    $this->assertSame($eventId, $params['eid']);
                } elseif (str_contains($query, 'UPDATE op_webhook_events')) {
                    $this->assertSame($eventId, $params['id']);
                    $this->assertSame(7, $params['att']);
                    $this->assertSame('failed', $params['st']);
                    $this->assertNull($params['next']);
                }
                return $this->stmtMock;
            });

        $this->service->deliver($webhook, 'payment.completed', ['id' => 'OP-123'], $eventId);
        $this->assertSame(2, $executeCount);
    }

    public function testWebhookRetryCronRunsPendingRetries(): void
    {
        $eventMock = [
            'id' => 40,
            'webhook_id' => 10,
            'event_type' => 'payment.completed',
            'payload' => '{"id":"OP-123"}',
            'attempts' => 1,
        ];

        $webhookMock = [
            'id' => 10,
            'url' => 'https://example.com/webhook',
            'secret' => 'wh_secret_key',
            'merchant_id' => 1,
        ];

        $this->dbMock->method('fetchAll')
            ->willReturnCallback(function (string $query, array $params = []) use ($eventMock) {
                if (str_contains($query, 'next_retry_at <= NOW')) {
                    return [$eventMock];
                }
                return [];
            });

        $this->dbMock->method('fetchOne')
            ->willReturnCallback(function (string $query, array $params = []) use ($webhookMock) {
                if (str_contains($query, 'op_webhooks')) {
                    return $webhookMock;
                }
                if (str_contains($query, 'op_webhook_events')) {
                    return ['attempts' => 1];
                }
                return null;
            });

        $this->dbMock->method('insert')->willReturn('104');
        $this->dbMock->method('update')->willReturn(1);
        $this->dbMock->method('execute')->willReturn($this->stmtMock);

        $cron = new WebhookRetryCron($this->service, $this->webhookEvents, $this->dbMock);
        $results = $cron->run();

        $this->assertSame(1, $results['retried']);
        $this->assertSame(0, $results['succeeded']);
    }
}
