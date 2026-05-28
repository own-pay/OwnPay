<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Core\Database;
use OwnPay\Service\System\AuditService;

/**
 * Class AuditIntegrityTest
 *
 * Verifies cryptographic signature chaining, integrity checks, and backport signing for Audit Trail compliance.
 */
class AuditIntegrityTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&Database
     */
    private $dbMock;

    /**
     * @var AuditLogRepository
     */
    private AuditLogRepository $repo;

    /**
     * @var AuditService
     */
    private AuditService $service;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(Database::class);
        $this->repo = new AuditLogRepository($this->dbMock);
        $this->service = new AuditService($this->repo);
    }

    public function testCalculateSignature(): void
    {
        $signature = $this->repo->calculateSignature(
            1,
            2,
            'test.action',
            'TestEntity',
            123,
            '{"old":"value"}',
            '{"new":"value"}',
            '127.0.0.1',
            'Mozilla/5.0'
        );

        $this->assertNotEmpty($signature);
        $this->assertSame(64, strlen($signature)); // SHA-256 HMAC signature length is 64 characters
    }

    public function testRecordLogWithSignature(): void
    {
        $this->dbMock->expects($this->once())
            ->method('insert')
            ->with(
                $this->stringContains('INSERT INTO op_audit_logs'),
                $this->callback(function (array $data) {
                    $this->assertSame(1, $data['merchant_id']);
                    $this->assertSame(2, $data['user_id']);
                    $this->assertSame('test.action', $data['action']);
                    $this->assertNotEmpty($data['signature']);
                    return true;
                })
            )
            ->willReturn('1');

        $id = $this->repo->record(
            1,
            2,
            'test.action',
            'TestEntity',
            123,
            ['old' => 'value'],
            ['new' => 'value'],
            '127.0.0.1',
            'Mozilla/5.0'
        );

        $this->assertSame('1', $id);
    }

    public function testVerifyIntegritySecure(): void
    {
        // Compute a valid signature
        $merchantId = 1;
        $userId = 2;
        $action = 'test.action';
        $entityType = 'TestEntity';
        $entityId = 123;
        $oldJson = '{"old":"value"}';
        $newJson = '{"new":"value"}';
        $ip = '127.0.0.1';
        $ua = 'Mozilla/5.0';

        $signature = $this->repo->calculateSignature(
            $merchantId, $userId, $action, $entityType, $entityId, $oldJson, $newJson, $ip, $ua
        );

        $mockRow = [
            'id' => 1,
            'merchant_id' => $merchantId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldJson,
            'new_values' => $newJson,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'signature' => $signature,
        ];

        $this->dbMock->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('SELECT * FROM op_audit_logs'))
            ->willReturn([$mockRow]);

        $compromised = $this->service->verifyIntegrity();
        $this->assertEmpty($compromised);
    }

    public function testVerifyIntegrityCompromised(): void
    {
        $mockRow = [
            'id' => 1,
            'merchant_id' => 1,
            'user_id' => 2,
            'action' => 'test.action',
            'entity_type' => 'TestEntity',
            'entity_id' => 123,
            'old_values' => '{"old":"value"}',
            'new_values' => '{"new":"value"}',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'signature' => 'invalid_signature_checksum_1234567890',
        ];

        $this->dbMock->expects($this->once())
            ->method('fetchAll')
            ->willReturn([$mockRow]);

        $compromised = $this->service->verifyIntegrity();
        $this->assertCount(1, $compromised);
        $this->assertSame(1, (int)$compromised[0]['id']);
    }

    public function testSignExistingLogs(): void
    {
        $mockRow = [
            'id' => 5,
            'merchant_id' => 1,
            'user_id' => 2,
            'action' => 'old.action',
            'entity_type' => 'TestEntity',
            'entity_id' => 123,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'signature' => null,
        ];

        $this->dbMock->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('signature IS NULL'))
            ->willReturn([$mockRow]);

        $this->dbMock->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE op_audit_logs SET signature = :sig WHERE id = :id'),
                $this->callback(function (array $params) {
                    $this->assertSame(5, $params['id']);
                    $this->assertNotEmpty($params['sig']);
                    return true;
                })
            );

        $count = $this->service->signExistingLogs();
        $this->assertSame(1, $count);
    }
}
