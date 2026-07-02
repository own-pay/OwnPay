<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Repository\SmsDataRepository;
use OwnPay\Repository\MobileNotificationRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
final class AdminFeaturesIntegrationTest extends IntegrationTestCase
{
    private const TEST_DEVICE_UUID = 'integ-admin-test-0000';

    private SmsTemplateRepository $templateRepo;
    private SmsDataRepository $dataRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->templateRepo = new SmsTemplateRepository(Database::getInstance());
        $this->dataRepo = new SmsDataRepository(Database::getInstance());

        $this->templateRepo = $this->templateRepo->forTenant(1);
        $this->dataRepo = $this->dataRepo->forTenant(1);

        $pdo = Database::getInstance()->pdo();
        $pdo->exec("DELETE FROM op_sms_parsed WHERE device_id = '" . self::TEST_DEVICE_UUID . "'");
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
        $pdo->exec("DELETE FROM op_sms_parsed WHERE device_id = '" . self::TEST_DEVICE_UUID . "'");
        $pdo->exec("DELETE FROM op_mobile_notifications WHERE device_uuid = '" . self::TEST_DEVICE_UUID . "'");
        $pdo->exec("DELETE FROM op_paired_devices WHERE device_id = '" . self::TEST_DEVICE_UUID . "'");

        parent::tearDown();
    }

    public function testTemplateCrudRoundTrip(): void
    {
        $id = (int) $this->templateRepo->createScoped([
            'gateway_slug'   => 'bkash',
            'sender_pattern' => 'TestProvider',
            'amount_regex'   => '/Test Tk\\s*(?P<amount>[\\d.]+)/i',
            'trx_id_regex'   => '/Test TrxID (?P<trx_id>\\w+)/i',
            'priority'       => 999,
            'status'         => 'active',
        ]);
        $this->assertGreaterThan(0, $id);

        $template = $this->templateRepo->findScoped($id);
        $this->assertNotNull($template);
        $this->assertSame('TestProvider', $template['sender_pattern']);
        $this->assertSame(999, (int) $template['priority']);

        $this->templateRepo->updateScoped($id, [
            'priority' => 50,
            'status'   => 'inactive',
        ]);
        $updated = $this->templateRepo->findScoped($id);
        $this->assertSame(50, (int) $updated['priority']);
        $this->assertSame('inactive', $updated['status']);

        $this->templateRepo->deleteScoped($id);
        $deleted = $this->templateRepo->findScoped($id);
        $this->assertNull($deleted);
    }

    public function testUpdateParsedDataForReprocess(): void
    {
        $id = (int) $this->dataRepo->createScoped([
            'device_id'     => self::TEST_DEVICE_UUID,
            'sender'        => 'TestSender',
            'received_at'   => date('Y-m-d H:i:s'),
            'encrypted_raw' => 'test_encrypted',
            'body'          => 'Received Tk 500 from 01712345678. TrxID RPR001.',
            'parser_type'   => 'unparsed',
            'match_status'  => 'admin_review',
        ]);
        $this->assertGreaterThan(0, $id);

        $this->dataRepo->updateParsedData($id, [
            'amount'       => 500.0,
            'trx_id'       => 'RPR001',
            'gateway_slug' => 'bkash',
            'parser_type'  => 'regex',
            'match_status' => 'accepted',
        ]);

        $record = $this->dataRepo->findScoped($id);
        $this->assertSame('accepted', $record['match_status']);
        $this->assertSame(500.0, (float) $record['amount']);
        $this->assertSame('RPR001', $record['trx_id']);
        $this->assertSame('regex', $record['parser_type']);
        $this->assertNotNull($record['created_at']);
    }

    public function testNotificationCleanup(): void
    {
        $notifRepo = new MobileNotificationRepository(Database::getInstance());
        $pdo = Database::getInstance()->pdo();

        $pdo->prepare(
            "INSERT INTO op_mobile_notifications (merchant_id, device_uuid, type, title, body, payload, is_read, read_at, created_at)
             VALUES (1, :uuid, 'test', 'Old', 'Old body', '{}', 1, DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY))"
        )->execute([':uuid' => self::TEST_DEVICE_UUID]);

        $pdo->prepare(
            "INSERT INTO op_mobile_notifications (merchant_id, device_uuid, type, title, body, payload, is_read, read_at, created_at)
             VALUES (1, :uuid, 'test', 'Recent', 'Recent body', '{}', 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY))"
        )->execute([':uuid' => self::TEST_DEVICE_UUID]);

        $purged = $notifRepo->purgeOldRead(7);

        $this->assertGreaterThanOrEqual(1, $purged);

        $remaining = $notifRepo->forTenant(1)->pollSince(self::TEST_DEVICE_UUID);
        $this->assertIsArray($remaining);
    }

    public function testSmsStatsAggregation(): void
    {
        $pdo = Database::getInstance()->pdo();

        $this->dataRepo->create([
            'merchant_id'      => 1,
            'device_id'        => self::TEST_DEVICE_UUID,
            'sender'           => 'bKash',
            'received_at'      => date('Y-m-d H:i:s'),
            'encrypted_raw'    => 'test',
            'amount'           => 500.0,
            'parsed_type'      => 'credit',
            'parser_type'      => 'regex',
            'template_id'      => 1,
            'parse_confidence' => 'high',
            'match_status'     => 'accepted',
        ]);

        $this->dataRepo->create([
            'merchant_id'      => 1,
            'device_id'        => self::TEST_DEVICE_UUID,
            'sender'           => 'Nagad',
            'received_at'      => date('Y-m-d H:i:s'),
            'encrypted_raw'    => 'test',
            'amount'           => 200.0,
            'parsed_type'      => 'debit',
            'parser_type'      => 'heuristic',
            'parse_confidence' => 'medium',
            'match_status'     => 'accepted',
        ]);

        $statusStmt = $pdo->prepare(
            "SELECT match_status, COUNT(*) AS count FROM op_sms_parsed
             WHERE merchant_id = 1 AND device_id = :uuid GROUP BY match_status"
        );
        $statusStmt->execute([':uuid' => self::TEST_DEVICE_UUID]);
        $byStatus = $statusStmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($byStatus);

        $methodStmt = $pdo->prepare(
            "SELECT parser_type, COUNT(*) AS count FROM op_sms_parsed
             WHERE merchant_id = 1 AND device_id = :uuid GROUP BY parser_type"
        );
        $methodStmt->execute([':uuid' => self::TEST_DEVICE_UUID]);
        $byMethod = $methodStmt->fetchAll(\PDO::FETCH_ASSOC);

        $methods = array_column($byMethod, 'parser_type');
        $this->assertContains('regex', $methods);
        $this->assertContains('heuristic', $methods);
    }
}
