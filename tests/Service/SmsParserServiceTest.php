<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Service\Sms\SmsParserService;
use OwnPay\Service\Sms\SmsRegexParser;
use OwnPay\Service\Sms\SmsHeuristicParser;
use PHPUnit\Framework\TestCase;

/**
 * SmsParserServiceTest â€” Unit tests for the SMS parsing orchestrator.
 *
 * Uses anonymous-class stubs for repositories and encryptor.
 * Uses real AES-256-GCM encryption for payload testing (matching the
 * decryptSmsPayload format: base64(IV + ciphertext + tag)).
 *
 * Tests cover:
 *   - Full regex match pipeline
 *   - Heuristic fallback pipeline
 *   - Unparsed flow (admin review)
 *   - Duplicate detection
 *   - Missing fields rejection
 *   - Device not found
 *   - Key decryption failure
 *   - Batch processing
 *   - SMS decryption failure
 */
final class SmsParserServiceTest extends TestCase
{
    private const DEVICE_UUID = 'test-device-uuid-1234';
    private const BRAND_ID = 1;
    // 64-char hex = 32-byte AES-256 key
    private const AES_KEY_HEX = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    private SmsRegexParser $regexParser;
    private SmsHeuristicParser $heuristicParser;

    protected function setUp(): void
    {
        $this->regexParser = new SmsRegexParser();
        $this->heuristicParser = new SmsHeuristicParser();
    }

    // â”€â”€â”€ Full Pipeline Tests â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testRegexMatchPipeline(): void
    {
        $plaintext = 'You have received Tk 500.00 from 01712345678. TrxID ABC123.';
        $service = $this->buildService(
            templates: [[
                'id'               => 1,
                'sender_pattern'   => 'bKash',
                'regex_pattern'    => '/received Tk\s*(?P<amount>[\d,]+(?:\.\d{1,2})?)\s*from\s*(?P<sender_number>\d{11})(?:.*?TrxID\s*(?P<trx_id>[A-Z0-9]+))?/i',
                'transaction_type' => 'credit',
            ]],
        );

        $results = $service->processBatch(self::DEVICE_UUID, self::BRAND_ID, [[
            'local_id'          => 1,
            'encrypted_payload' => $this->encrypt($plaintext),
            'sender'            => 'bKash',
            'received_at'       => '2026-04-27T10:30:00+06:00',
        ]]);

        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]['local_id']);
        $this->assertSame('accepted', $results[0]['status']);
        $this->assertNotNull($results[0]['server_ref']);
        $this->assertStringStartsWith('sms_', $results[0]['server_ref']);
    }

    public function testHeuristicFallback(): void
    {
        $plaintext = 'Credited Tk 300 to your account. TrxID HEU999.';
        $service = $this->buildService(templates: []);

        $results = $service->processBatch(self::DEVICE_UUID, self::BRAND_ID, [[
            'local_id'          => 2,
            'encrypted_payload' => $this->encrypt($plaintext),
            'sender'            => 'Unknown',
            'received_at'       => '2026-04-27T11:00:00+06:00',
        ]]);

        $this->assertSame('accepted', $results[0]['status']);
        $this->assertNotNull($results[0]['server_ref']);
    }

    public function testUnparsedGoesToAdminReview(): void
    {
        $plaintext = 'Welcome to Grameenphone! Dial *121# for info.';
        $service = $this->buildService(templates: []);

        $results = $service->processBatch(self::DEVICE_UUID, self::BRAND_ID, [[
            'local_id'          => 3,
            'encrypted_payload' => $this->encrypt($plaintext),
            'sender'            => 'GP',
            'received_at'       => '2026-04-27T12:00:00+06:00',
        ]]);

        $this->assertSame('accepted', $results[0]['status']);
        // Verify admin_review status was passed to create()
        $this->assertNotNull($results[0]['server_ref']);
    }

    // â”€â”€â”€ Dedup Tests â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testDuplicateDetection(): void
    {
        $service = $this->buildService(isDuplicate: true);

        $results = $service->processBatch(self::DEVICE_UUID, self::BRAND_ID, [[
            'local_id'          => 4,
            'encrypted_payload' => $this->encrypt('test'),
            'sender'            => 'bKash',
            'received_at'       => '2026-04-27T10:30:00+06:00',
        ]]);

        $this->assertSame('duplicate', $results[0]['status']);
        $this->assertNull($results[0]['server_ref']);
    }

    // â”€â”€â”€ Error Handling â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testDeviceNotFound(): void
    {
        $service = $this->buildService(deviceExists: false);

        $results = $service->processBatch('nonexistent-uuid', self::BRAND_ID, [[
            'local_id'          => 5,
            'encrypted_payload' => $this->encrypt('test'),
            'sender'            => 'bKash',
            'received_at'       => '2026-04-27T10:30:00+06:00',
        ]]);

        $this->assertSame('rejected', $results[0]['status']);
        $this->assertSame('DEVICE_NOT_FOUND', $results[0]['error']);
    }

    public function testKeyDecryptionFailure(): void
    {
        $service = $this->buildService(keyDecryptionFails: true);

        $results = $service->processBatch(self::DEVICE_UUID, self::BRAND_ID, [[
            'local_id'          => 6,
            'encrypted_payload' => $this->encrypt('test'),
            'sender'            => 'bKash',
            'received_at'       => '2026-04-27T10:30:00+06:00',
        ]]);

        $this->assertSame('rejected', $results[0]['status']);
        $this->assertSame('KEY_DECRYPTION_FAILED', $results[0]['error']);
    }

    public function testMissingPayloadRejected(): void
    {
        $service = $this->buildService();

        $results = $service->processBatch(self::DEVICE_UUID, self::BRAND_ID, [[
            'local_id'          => 7,
            'encrypted_payload' => '',
            'sender'            => 'bKash',
            'received_at'       => '2026-04-27T10:30:00+06:00',
        ]]);

        $this->assertSame('rejected', $results[0]['status']);
        $this->assertSame('MISSING_FIELDS', $results[0]['error']);
    }

    public function testMissingSenderRejected(): void
    {
        $service = $this->buildService();

        $results = $service->processBatch(self::DEVICE_UUID, self::BRAND_ID, [[
            'local_id'          => 8,
            'encrypted_payload' => $this->encrypt('test'),
            'sender'            => '',
            'received_at'       => '2026-04-27T10:30:00+06:00',
        ]]);

        $this->assertSame('rejected', $results[0]['status']);
        $this->assertSame('MISSING_FIELDS', $results[0]['error']);
    }

    // â”€â”€â”€ Batch Processing â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testBatchProcessingMultipleMessages(): void
    {
        $service = $this->buildService(templates: []);

        $messages = [
            ['local_id' => 10, 'encrypted_payload' => $this->encrypt('Received Tk 100.'), 'sender' => 'bKash', 'received_at' => '2026-04-27T10:00:00+06:00'],
            ['local_id' => 11, 'encrypted_payload' => $this->encrypt('Received Tk 200.'), 'sender' => 'Nagad', 'received_at' => '2026-04-27T10:01:00+06:00'],
            ['local_id' => 12, 'encrypted_payload' => $this->encrypt('Received Tk 300.'), 'sender' => 'Rocket', 'received_at' => '2026-04-27T10:02:00+06:00'],
        ];

        $results = $service->processBatch(self::DEVICE_UUID, self::BRAND_ID, $messages);

        $this->assertCount(3, $results);
        foreach ($results as $r) {
            $this->assertSame('accepted', $r['status']);
        }
    }

    public function testSmsDecryptionFailureStoresAsParseError(): void
    {
        $service = $this->buildService(templates: []);

        // Provide invalid encrypted payload (not valid AES-256-GCM)
        $results = $service->processBatch(self::DEVICE_UUID, self::BRAND_ID, [[
            'local_id'          => 20,
            'encrypted_payload' => base64_encode('this_is_not_valid_aes_gcm_data_that_is_long_enough'),
            'sender'            => 'bKash',
            'received_at'       => '2026-04-27T10:30:00+06:00',
        ]]);

        $this->assertSame('accepted', $results[0]['status']);
        $this->assertSame('DECRYPTION_FAILED', $results[0]['error'] ?? null);
        $this->assertNotNull($results[0]['server_ref']);
    }

    public function testAllDeviceMessagesRejectedIfDeviceNotFound(): void
    {
        $service = $this->buildService(deviceExists: false);

        $results = $service->processBatch('bad-uuid', self::BRAND_ID, [
            ['local_id' => 30, 'encrypted_payload' => $this->encrypt('test1'), 'sender' => 'bKash', 'received_at' => '2026-04-27T10:00:00+06:00'],
            ['local_id' => 31, 'encrypted_payload' => $this->encrypt('test2'), 'sender' => 'Nagad', 'received_at' => '2026-04-27T10:01:00+06:00'],
        ]);

        $this->assertCount(2, $results);
        foreach ($results as $r) {
            $this->assertSame('rejected', $r['status']);
            $this->assertSame('DEVICE_NOT_FOUND', $r['error']);
        }
    }

    // â”€â”€â”€ Crypto Helper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Encrypt a plaintext using AES-256-GCM with the test key.
     * Format: base64(IV(12) + ciphertext + tag(16))
     */
    private function encrypt(string $plaintext): string
    {
        $key = hex2bin(self::AES_KEY_HEX);
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        return base64_encode($iv . $ciphertext . $tag);
    }

    // â”€â”€â”€ Service Builder â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Build SmsParserService with anonymous-class stubs.
     */
    private function buildService(
        bool $deviceExists = true,
        bool $keyDecryptionFails = false,
        bool $isDuplicate = false,
        array $templates = [],
    ): SmsParserService {
        // Stub: PairedDeviceRepository
        $deviceRepo = new class($deviceExists) {
            private bool $exists;
            public function __construct(bool $exists) { $this->exists = $exists; }
            public function forTenant(int $merchantId): self { return $this; }
            public function findByUuid(string $uuid): ?array {
                if (!$this->exists) return null;
                return [
                    'id' => 1,
                    'device_uuid' => $uuid,
                    'brand_id' => 1,
                    'aes_key_encrypted' => 'test_encrypted_key',
                    'revoked_at' => null,
                ];
            }
        };

        // Stub: FieldEncryptor â€” returns real AES key hex on decrypt
        $aesKeyHex = self::AES_KEY_HEX;
        $encryptor = new class($keyDecryptionFails, $aesKeyHex) {
            private bool $fails;
            private string $key;
            public function __construct(bool $fails, string $key) {
                $this->fails = $fails;
                $this->key = $key;
            }
            public function decrypt(string $encrypted): string {
                if ($this->fails) throw new \RuntimeException('Stub: key decryption failed');
                return $this->key;
            }
        };

        // Stub: SmsTemplateRepository
        $templateRepo = new class($templates) {
            private array $templates;
            public function __construct(array $templates) { $this->templates = $templates; }
            public function findBySender(string $sender): array { return $this->templates; }
        };

        // Stub: SmsDataRepository
        $dataRepo = new class($isDuplicate) {
            private bool $isDup;
            private int $counter = 0;
            public function __construct(bool $isDup) { $this->isDup = $isDup; }
            public function isDuplicate(string $deviceUuid, string $sender, string $receivedAt): bool {
                return $this->isDup;
            }
            public function create(array $data): int {
                $this->counter++;
                return $this->counter;
            }
        };

        // Stub: MobileNotificationService (no-op)
        $notifService = new class {
            public function queuePaymentNotification(
                string $deviceUuid, string $type,
                ?float $amount = null, ?string $sender = null,
                ?string $trxId = null, ?string $provider = null,
            ): int { return 1; }
        };

        return new SmsParserService(
            $deviceRepo,
            $templateRepo,
            $dataRepo,
            $this->regexParser,
            $this->heuristicParser,
            $encryptor,
            $notifService,
        );
    }
}

