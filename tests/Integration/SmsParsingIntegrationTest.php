<?php

declare(strict_types=1);

namespace OwnPay\Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\SmsDataRepository;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Service\Sms\SmsParserService;
use OwnPay\Service\Sms\SmsRegexParser;
use OwnPay\Service\Sms\SmsHeuristicParser;
use PHPUnit\Framework\TestCase;

/**
 * SmsParsingIntegrationTest — End-to-end integration test for the SMS parsing pipeline.
 *
 * Requires live DB connection. Tests:
 *   1. Template lookup from seeded op_sms_templates
 *   2. Full parse → store → verify round-trip
 *   3. Dedup detection
 *   4. Heuristic fallback round-trip
 *
 * @group Integration
 */
final class SmsParsingIntegrationTest extends TestCase
{
    private const AES_KEY_HEX = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const TEST_DEVICE_UUID = 'integ-sms-test-' . '0000-0000';

    private SmsTemplateRepository $templateRepo;
    private SmsDataRepository $dataRepo;
    private SmsRegexParser $regexParser;
    private SmsHeuristicParser $heuristicParser;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $name = getenv('DB_NAME') ?: 'anirbanpay';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        Database::init($host, $name, $user, $pass);

        $this->templateRepo = new SmsTemplateRepository();
        $this->dataRepo = new SmsDataRepository();
        $this->regexParser = new SmsRegexParser();
        $this->heuristicParser = new SmsHeuristicParser();

        // Ensure test device exists in op_paired_devices
        $this->ensureTestDevice();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $pdo = Database::getInstance()->getPdo();
        $pdo->exec("DELETE FROM op_sms_parsed WHERE device_uuid = '" . self::TEST_DEVICE_UUID . "'");
        $pdo->exec("DELETE FROM op_paired_devices WHERE device_uuid = '" . self::TEST_DEVICE_UUID . "'");
    }

    // ─── Test 1: Template Lookup ─────────────────────────────────────

    public function testTemplateLookupReturnsBkashTemplates(): void
    {
        $templates = $this->templateRepo->findBySender('bKash');

        $this->assertNotEmpty($templates, 'Expected seeded bKash templates');
        foreach ($templates as $t) {
            $this->assertSame('bKash', $t['provider_name']);
        }
    }

    // ─── Test 2: Full Regex Parse + Store Round-Trip ──────────────────

    public function testFullRegexPipelineWithDb(): void
    {
        $plaintext = 'You have received Tk 1,500.50 from 01712345678. TrxID INT001. Your new balance is Tk 5,000.00.';
        $templates = $this->templateRepo->findBySender('bKash');
        $this->assertNotEmpty($templates);

        // Parse with Tier 1
        $parsed = $this->regexParser->parse($plaintext, $templates);
        $this->assertNotNull($parsed, 'Regex should match bKash credit template');
        $this->assertSame(1500.5, $parsed['parsed_amount']);
        $this->assertSame('INT001', $parsed['parsed_trx_id']);
        $this->assertSame('regex', $parsed['parse_method']);
        $this->assertSame('high', $parsed['parse_confidence']);

        // Store to DB
        $id = $this->dataRepo->create([
            'device_uuid'      => self::TEST_DEVICE_UUID,
            'brand_id'         => 1,
            'local_id'         => 100,
            'sender'           => 'bKash',
            'received_at'      => '2026-04-27 10:30:00',
            'encrypted_raw'    => 'test_encrypted_payload',
            'raw_message'      => $plaintext,
            'parsed_amount'    => $parsed['parsed_amount'],
            'parsed_trx_id'    => $parsed['parsed_trx_id'],
            'parsed_sender'    => $parsed['parsed_sender'],
            'parsed_balance'   => $parsed['parsed_balance'],
            'parsed_type'      => $parsed['parsed_type'],
            'parse_method'     => $parsed['parse_method'],
            'template_id'      => $parsed['template_id'],
            'parse_confidence' => $parsed['parse_confidence'],
            'status'           => 'accepted',
        ]);

        $this->assertGreaterThan(0, $id);

        // Verify from DB
        $row = $this->dataRepo->findById($id);
        $this->assertNotNull($row);
        $this->assertSame('1500.50', $row['parsed_amount']);
        $this->assertSame('INT001', $row['parsed_trx_id']);
        $this->assertSame('01712345678', $row['parsed_sender']);
        $this->assertSame('5000.00', $row['parsed_balance']);
        $this->assertSame('credit', $row['parsed_type']);
        $this->assertSame('regex', $row['parse_method']);
        $this->assertSame('high', $row['parse_confidence']);
    }

    // ─── Test 3: Dedup Detection ─────────────────────────────────────

    public function testDeduplicateDetection(): void
    {
        // Insert first record
        $this->dataRepo->create([
            'device_uuid'   => self::TEST_DEVICE_UUID,
            'brand_id'      => 1,
            'sender'        => 'bKash',
            'received_at'   => '2026-04-27 11:00:00',
            'encrypted_raw' => 'test',
            'status'        => 'accepted',
        ]);

        // Check dedup
        $isDup = $this->dataRepo->isDuplicate(
            self::TEST_DEVICE_UUID,
            'bKash',
            '2026-04-27 11:00:00'
        );
        $this->assertTrue($isDup, 'Should detect duplicate');

        // Different time = not duplicate
        $isDup2 = $this->dataRepo->isDuplicate(
            self::TEST_DEVICE_UUID,
            'bKash',
            '2026-04-27 11:00:05'
        );
        $this->assertFalse($isDup2, 'Different time should not be duplicate');
    }

    // ─── Test 4: Heuristic Fallback Round-Trip ───────────────────────

    public function testHeuristicFallbackWithDb(): void
    {
        $plaintext = 'Credited BDT 750.00 to your wallet from 01812345678. Ref: HEU555. Balance Tk 3,000.00';

        // No matching regex template for this format
        $templates = $this->templateRepo->findBySender('CustomBank');
        $this->assertEmpty($templates);

        // Regex fails → heuristic
        $regexResult = $this->regexParser->parse($plaintext, $templates);
        $this->assertNull($regexResult);

        $heuristicResult = $this->heuristicParser->parse($plaintext);
        $this->assertNotNull($heuristicResult);
        $this->assertSame(750.0, $heuristicResult['parsed_amount']);
        $this->assertSame('heuristic', $heuristicResult['parse_method']);
        $this->assertSame('credit', $heuristicResult['parsed_type']);

        // Store
        $id = $this->dataRepo->create([
            'device_uuid'      => self::TEST_DEVICE_UUID,
            'brand_id'         => 1,
            'sender'           => 'CustomBank',
            'received_at'      => '2026-04-27 12:00:00',
            'encrypted_raw'    => 'test_encrypted',
            'raw_message'      => $plaintext,
            'parsed_amount'    => $heuristicResult['parsed_amount'],
            'parsed_trx_id'    => $heuristicResult['parsed_trx_id'],
            'parsed_sender'    => $heuristicResult['parsed_sender'],
            'parsed_balance'   => $heuristicResult['parsed_balance'],
            'parsed_type'      => $heuristicResult['parsed_type'],
            'parse_method'     => $heuristicResult['parse_method'],
            'parse_confidence' => $heuristicResult['parse_confidence'],
            'status'           => 'accepted',
        ]);

        $row = $this->dataRepo->findById($id);
        $this->assertNotNull($row);
        $this->assertSame('750.00', $row['parsed_amount']);
        $this->assertSame('heuristic', $row['parse_method']);
    }

    // ─── Test 5: listByBrand pagination ──────────────────────────────

    public function testListByBrandWithPagination(): void
    {
        // Insert 3 records
        for ($i = 0; $i < 3; $i++) {
            $this->dataRepo->create([
                'device_uuid'   => self::TEST_DEVICE_UUID,
                'brand_id'      => 1,
                'sender'        => 'bKash',
                'received_at'   => "2026-04-27 14:0{$i}:00",
                'encrypted_raw' => "test_{$i}",
                'status'        => 'accepted',
            ]);
        }

        $page1 = $this->dataRepo->listByBrand(1, 2, 0);
        $this->assertSame(2, count($page1['items']));
        $this->assertGreaterThanOrEqual(3, $page1['total']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function ensureTestDevice(): void
    {
        $pdo = Database::getInstance()->getPdo();

        // Check if test device already exists
        $stmt = $pdo->prepare("SELECT id FROM op_paired_devices WHERE device_uuid = :uuid");
        $stmt->execute([':uuid' => self::TEST_DEVICE_UUID]);
        if ($stmt->fetch()) {
            return;
        }

        // Insert test device
        $pdo->prepare(
            "INSERT INTO op_paired_devices (device_uuid, brand_id, device_name, fingerprint_hash,
             aes_key_encrypted, refresh_token_hash, refresh_token_expires_at, jwt_secret,
             platform, app_version, created_at)
             VALUES (:uuid, 1, 'Integration Test Device', :fp_hash,
             :aes_key, :rt_hash, DATE_ADD(NOW(), INTERVAL 90 DAY), :jwt_secret,
             'android', '1.0.0', NOW())"
        )->execute([
            ':uuid'       => self::TEST_DEVICE_UUID,
            ':fp_hash'    => hash('sha256', 'test_fingerprint'),
            ':aes_key'    => 'test_aes_key_for_integration',
            ':rt_hash'    => hash('sha256', 'test_refresh_token'),
            ':jwt_secret' => bin2hex(random_bytes(32)),
        ]);
    }
}
