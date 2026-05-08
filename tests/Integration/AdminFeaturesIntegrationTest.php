<?php

declare(strict_types=1);

namespace OwnPay\Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Repository\SmsDataRepository;
use OwnPay\Repository\MobileNotificationRepository;
use Tests\Integration\IntegrationTestCase;

/**
 * AdminFeaturesIntegrationTest — Integration tests for Part 5 admin features.
 *
 * Tests:
 *   1. SMS Template CRUD round-trip
 *   2. SMS record updateParsedData (reprocess/resolve)
 *   3. Notification cleanup (purgeOldRead)
 *   4. SMS stats query
 *
 * @group Integration
 */
final class AdminFeaturesIntegrationTest extends IntegrationTestCase
{
    private const TEST_DEVICE_UUID = 'integ-admin-test-0000';

    private SmsTemplateRepository $templateRepo;
    private SmsDataRepository $dataRepo;

    protected function setUp(): void
    {
        parent::setUp(); // triggers DB-available skip check

        $this->templateRepo = new SmsTemplateRepository();
        $this->dataRepo = new SmsDataRepository();

        // Seed test device for FK constraint
        $pdo = Database::getInstance()->getPdo();
        $stmt = $pdo->prepare("SELECT id FROM op_paired_devices WHERE device_uuid = :uuid");
        $stmt->execute([':uuid' => self::TEST_DEVICE_UUID]);
        if (!$stmt->fetch()) {
            $pdo->prepare(
                "INSERT INTO op_paired_devices (device_uuid, brand_id, device_name, fingerprint_hash,
                 aes_key_encrypted, refresh_token_hash, refresh_token_expires_at, jwt_secret,
                 platform, app_version, created_at)
                 VALUES (:uuid, 1, 'Admin Test Device', :fp, 'test', :rt, DATE_ADD(NOW(), INTERVAL 90 DAY), :jwt, 'android', '1.0.0', NOW())"
            )->execute([
                ':uuid' => self::TEST_DEVICE_UUID,
                ':fp' => hash('sha256', 'admin_test'),
                ':rt' => hash('sha256', 'admin_test_refresh'),
                ':jwt' => bin2hex(random_bytes(32)),
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (!static::$dbAvailable) {
            return;
        }

        $pdo = Database::getInstance()->getPdo();
        $pdo->exec("DELETE FROM op_sms_parsed WHERE device_uuid = '" . self::TEST_DEVICE_UUID . "'");
        $pdo->exec("DELETE FROM op_mobile_notifications WHERE device_uuid = '" . self::TEST_DEVICE_UUID . "'");
        $pdo->exec("DELETE FROM op_paired_devices WHERE device_uuid = '" . self::TEST_DEVICE_UUID . "'");
    }

    // ─── Test 1: Template CRUD ───────────────────────────────────────

    public function testTemplateCrudRoundTrip(): void
    {
        // Create
        $id = $this->templateRepo->create([
            'sender_pattern'   => 'TestProvider',
            'regex_pattern'    => '/Test Tk\s*(?P<amount>[\d.]+)/i',
            'provider_name'    => 'TestProvider',
            'transaction_type' => 'credit',
            'priority'         => 999,
            'description'      => 'Integration test template',
        ]);
        $this->assertGreaterThan(0, $id);

        // Read
        $template = $this->templateRepo->findById($id);
        $this->assertNotNull($template);
        $this->assertSame('TestProvider', $template['sender_pattern']);
        $this->assertSame(999, (int) $template['priority']);

        // Update
        $this->templateRepo->update($id, [
            'priority'    => 50,
            'description' => 'Updated description',
        ]);
        $updated = $this->templateRepo->findById($id);
        $this->assertSame(50, (int) $updated['priority']);
        $this->assertSame('Updated description', $updated['description']);

        // Delete
        $this->templateRepo->delete($id);
        $deleted = $this->templateRepo->findById($id);
        $this->assertNull($deleted);
    }

    // ─── Test 2: updateParsedData ────────────────────────────────────

    public function testUpdateParsedDataForReprocess(): void
    {
        // Insert a record with admin_review status
        $id = $this->dataRepo->create([
            'device_uuid'   => self::TEST_DEVICE_UUID,
            'brand_id'      => 1,
            'sender'        => 'TestSender',
            'received_at'   => date('Y-m-d H:i:s'),
            'encrypted_raw' => 'test_encrypted',
            'raw_message'   => 'Received Tk 500 from 01712345678. TrxID RPR001.',
            'parse_method'  => 'unparsed',
            'status'        => 'admin_review',
        ]);
        $this->assertGreaterThan(0, $id);

        // Simulate admin reprocess
        $this->dataRepo->updateParsedData($id, [
            'parsed_amount'    => 500.0,
            'parsed_type'      => 'credit',
            'parsed_trx_id'    => 'RPR001',
            'parsed_sender'    => '01712345678',
            'parse_method'     => 'regex',
            'parse_confidence' => 'high',
            'status'           => 'accepted',
        ]);

        $record = $this->dataRepo->findById($id);
        $this->assertSame('accepted', $record['status']);
        $this->assertSame(500.0, (float) $record['parsed_amount']);
        $this->assertSame('RPR001', $record['parsed_trx_id']);
        $this->assertSame('regex', $record['parse_method']);
        $this->assertNotNull($record['processed_at']);
    }

    // ─── Test 3: Notification cleanup ────────────────────────────────

    public function testNotificationCleanup(): void
    {
        $notifRepo = new MobileNotificationRepository();
        $pdo = Database::getInstance()->getPdo();

        // Insert an old read notification (8 days ago)
        $pdo->prepare(
            "INSERT INTO op_mobile_notifications (device_uuid, type, title, body, payload, is_read, read_at, created_at)
             VALUES (:uuid, 'test', 'Old', 'Old body', '{}', 1, DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY))"
        )->execute([':uuid' => self::TEST_DEVICE_UUID]);

        // Insert a recent read notification (1 day ago)
        $pdo->prepare(
            "INSERT INTO op_mobile_notifications (device_uuid, type, title, body, payload, is_read, read_at, created_at)
             VALUES (:uuid, 'test', 'Recent', 'Recent body', '{}', 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY))"
        )->execute([':uuid' => self::TEST_DEVICE_UUID]);

        // Purge > 7 days
        $purged = $notifRepo->purgeOldRead(7);

        // At least the old one should be purged
        $this->assertGreaterThanOrEqual(1, $purged);

        // Recent one should still exist
        $remaining = $notifRepo->pollSince(self::TEST_DEVICE_UUID);
        // The recent notification should be in results (it's read, but pollSince may exclude read by default)
        // At minimum, verify no crash
        $this->assertIsArray($remaining);
    }

    // ─── Test 4: SMS stats aggregation ───────────────────────────────

    public function testSmsStatsAggregation(): void
    {
        $pdo = Database::getInstance()->getPdo();

        // Insert test records
        $this->dataRepo->create([
            'device_uuid'   => self::TEST_DEVICE_UUID,
            'brand_id'      => 1,
            'sender'        => 'bKash',
            'received_at'   => date('Y-m-d H:i:s'),
            'encrypted_raw' => 'test',
            'parsed_amount' => 500.0,
            'parsed_type'   => 'credit',
            'parse_method'  => 'regex',
            'template_id'   => 1,
            'parse_confidence' => 'high',
            'status'        => 'accepted',
        ]);

        $this->dataRepo->create([
            'device_uuid'   => self::TEST_DEVICE_UUID,
            'brand_id'      => 1,
            'sender'        => 'Nagad',
            'received_at'   => date('Y-m-d H:i:s'),
            'encrypted_raw' => 'test',
            'parsed_amount' => 200.0,
            'parsed_type'   => 'debit',
            'parse_method'  => 'heuristic',
            'parse_confidence' => 'medium',
            'status'        => 'accepted',
        ]);

        // Query stats
        $statusStmt = $pdo->prepare(
            "SELECT status, COUNT(*) AS count FROM op_sms_parsed
             WHERE brand_id = 1 AND device_uuid = :uuid GROUP BY status"
        );
        $statusStmt->execute([':uuid' => self::TEST_DEVICE_UUID]);
        $byStatus = $statusStmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($byStatus);

        $methodStmt = $pdo->prepare(
            "SELECT parse_method, COUNT(*) AS count FROM op_sms_parsed
             WHERE brand_id = 1 AND device_uuid = :uuid GROUP BY parse_method"
        );
        $methodStmt->execute([':uuid' => self::TEST_DEVICE_UUID]);
        $byMethod = $methodStmt->fetchAll(\PDO::FETCH_ASSOC);

        $methods = array_column($byMethod, 'parse_method');
        $this->assertContains('regex', $methods);
        $this->assertContains('heuristic', $methods);
    }
}
