<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Repository\SmsDataRepository;
use OwnPay\Service\Sms\SmsRegexParser;
use OwnPay\Service\Sms\SmsHeuristicParser;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration test: SMS parsing round-trip against a live DB.
 *
 * Flow: Match templates, match heuristics, check duplicates, paginate.
 */
class SmsParsingIntegrationTest extends IntegrationTestCase
{
    private const TEST_DEVICE_UUID = 'integration-test-device-uuid-999';

    private SmsTemplateRepository $templateRepo;
    private SmsDataRepository $dataRepo;
    private SmsRegexParser $regexParser;
    private SmsHeuristicParser $heuristicParser;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            return;
        }

        echo "\nDB ENV values: " . json_encode([
            'host' => $_ENV['DB_HOST'] ?? null,
            'name' => $_ENV['DB_NAME'] ?? null,
            'user' => $_ENV['DB_USER'] ?? null,
            'pass' => $_ENV['DB_PASS'] ?? null,
        ]) . "\n";

        $db = Database::getInstance();
        $this->templateRepo = new SmsTemplateRepository($db);
        $this->dataRepo = new SmsDataRepository($db);
        $this->regexParser = new SmsRegexParser();
        $this->heuristicParser = new SmsHeuristicParser();

        $this->ensureTestDevice();
    }

    protected function tearDown(): void
    {
        if (!static::$dbAvailable) {
            return;
        }

        // Clean up test data
        $pdo = Database::getInstance()->pdo();
        $pdo->prepare("DELETE FROM op_sms_parsed WHERE device_id = ?")->execute([self::TEST_DEVICE_UUID]);
        $pdo->prepare("DELETE FROM op_paired_devices WHERE device_id = ?")->execute([self::TEST_DEVICE_UUID]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Test 1: Template Lookup
    // ═══════════════════════════════════════════════════════════════

    public function testTemplateLookupReturnsBkashTemplates(): void
    {
        $templates = $this->templateRepo->findBySender('bKash', 1);

        $this->assertNotEmpty($templates, 'Expected seeded bKash templates');
        foreach ($templates as $t) {
            $this->assertSame('bkash', $t['gateway_slug']);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  Test 2: Full Regex Parse + Store Round-Trip
    // ═══════════════════════════════════════════════════════════════

    public function testFullRegexPipelineWithDb(): void
    {
        $plaintext = 'You have received Tk 1,500.50 from 01712345678. TrxID INT001. Your new balance is Tk 5,000.00.';
        $templates = $this->templateRepo->findBySender('bKash', 1);
        $this->assertNotEmpty($templates);

        // Parse with Tier 1
        $parsed = $this->regexParser->parse($plaintext, $templates);
        $this->assertNotNull($parsed, 'Regex should match bKash credit template');
        $this->assertSame(1500.5, $parsed['parsed_amount']);
        $this->assertSame('INT001', $parsed['parsed_trx_id']);
        $this->assertSame('regex', $parsed['parse_method']);
        $this->assertSame('high', $parsed['parse_confidence']);

        // Store to DB
        $id = (int) $this->dataRepo->create([
            'device_id'        => self::TEST_DEVICE_UUID,
            'merchant_id'      => 1,
            'local_id'         => 100,
            'sender'           => 'bKash',
            'received_at'      => '2026-04-27 10:30:00',
            'encrypted_raw'    => 'test_encrypted_payload',
            'body'             => $plaintext,
            'amount'           => $parsed['parsed_amount'],
            'trx_id'           => $parsed['parsed_trx_id'],
            'parsed_sender'    => $parsed['parsed_sender'],
            'parsed_balance'   => $parsed['parsed_balance'],
            'parsed_type'      => $parsed['parsed_type'],
            'parser_type'      => $parsed['parse_method'],
            'template_id'      => $parsed['template_id'],
            'parse_confidence' => $parsed['parse_confidence'],
            'match_status'     => 'accepted',
        ]);

        $this->assertGreaterThan(0, $id);

        // Verify from DB
        $row = $this->dataRepo->find($id);
        $this->assertNotNull($row);
        $this->assertSame(1500.50, (float)$row['amount']);
        $this->assertSame('INT001', $row['trx_id']);
        $this->assertSame('01712345678', $row['parsed_sender']);
        $this->assertSame('credit', $row['parsed_type']);
        $this->assertSame('regex', $row['parser_type']);
        $this->assertSame('high', $row['parse_confidence']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Test 3: Dedup Detection
    // ═══════════════════════════════════════════════════════════════

    public function testDeduplicateDetection(): void
    {
        // Insert first record
        $this->dataRepo->create([
            'device_id'     => self::TEST_DEVICE_UUID,
            'merchant_id'   => 1,
            'sender'        => 'bKash',
            'received_at'   => '2026-04-27 11:00:00',
            'encrypted_raw' => 'test',
            'match_status'  => 'accepted',
        ]);

        // Check dedup
        $isDup = $this->dataRepo->forTenant(1)->isDuplicate(
            self::TEST_DEVICE_UUID,
            'bKash',
            '2026-04-27 11:00:00'
        );
        $this->assertTrue($isDup, 'Should detect duplicate');

        // Different time = not duplicate
        $isDup2 = $this->dataRepo->forTenant(1)->isDuplicate(
            self::TEST_DEVICE_UUID,
            'bKash',
            '2026-04-27 11:00:05'
        );
        $this->assertFalse($isDup2, 'Different time should not be duplicate');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Test 4: Heuristic Fallback Round-Trip
    // ═══════════════════════════════════════════════════════════════

    public function testHeuristicFallbackWithDb(): void
    {
        $plaintext = 'Credited BDT 750.00 to your wallet from 01812345678. Ref: HEU555. Balance Tk 3,000.00';

        // No matching regex template for CustomBank
        $templates = $this->templateRepo->findBySender('CustomBank', 1);
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
        $id = (int) $this->dataRepo->create([
            'device_id'        => self::TEST_DEVICE_UUID,
            'merchant_id'      => 1,
            'sender'           => 'CustomBank',
            'received_at'      => '2026-04-27 12:00:00',
            'encrypted_raw'    => 'test_encrypted',
            'body'             => $plaintext,
            'amount'           => $heuristicResult['parsed_amount'],
            'trx_id'           => $heuristicResult['parsed_trx_id'],
            'parsed_sender'    => $heuristicResult['parsed_sender'],
            'parsed_balance'   => $heuristicResult['parsed_balance'],
            'parsed_type'      => $heuristicResult['parsed_type'],
            'parser_type'      => $heuristicResult['parse_method'],
            'parse_confidence' => $heuristicResult['parse_confidence'],
            'match_status'     => 'accepted',
        ]);

        $row = $this->dataRepo->find($id);
        $this->assertNotNull($row);
        $this->assertSame(750.00, (float)$row['amount']);
        $this->assertSame('heuristic', $row['parser_type']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Test 5: listPaginated pagination
    // ═══════════════════════════════════════════════════════════════

    public function testListByBrandWithPagination(): void
    {
        // Insert 3 records
        for ($i = 0; $i < 3; $i++) {
            $this->dataRepo->create([
                'device_id'     => self::TEST_DEVICE_UUID,
                'merchant_id'   => 1,
                'sender'        => 'bKash',
                'received_at'   => "2026-04-27 14:0{$i}:00",
                'encrypted_raw' => "test_{$i}",
                'match_status'  => 'accepted',
            ]);
        }

        $page1 = $this->dataRepo->forTenant(1)->listPaginated(2, 0);
        $this->assertSame(2, count($page1['items']));
        $this->assertGreaterThanOrEqual(3, $page1['total']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════════════════════

    private function ensureTestDevice(): void
    {
        $pdo = Database::getInstance()->pdo();

        // Check if test device already exists
        $stmt = $pdo->prepare("SELECT id FROM op_paired_devices WHERE device_id = :uuid");
        $stmt->execute([':uuid' => self::TEST_DEVICE_UUID]);
        if ($stmt->fetch()) {
            return;
        }

        // Insert test device
        $pdo->prepare(
            "INSERT INTO op_paired_devices (device_id, merchant_id, device_name, jwt_fingerprint,
             aes_key_encrypted, platform, status, paired_at)
             VALUES (:uuid, 1, 'Integration Test Device', :fp_hash,
             :aes_key, 'android', 'active', NOW())"
        )->execute([
            ':uuid'    => self::TEST_DEVICE_UUID,
            ':fp_hash' => hash('sha256', 'test_fingerprint'),
            ':aes_key' => 'test_aes_key_for_integration',
        ]);
    }
}
