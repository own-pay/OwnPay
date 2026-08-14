<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use OwnPay\Update\UpdateService;
use OwnPay\Update\BackupService;
use OwnPay\Update\HealthChecker;
use OwnPay\Update\MaintenanceMode;
use OwnPay\Repository\UpdateHistoryRepository;
use OwnPay\Event\EventManager;

class TestableUpdateService extends UpdateService
{
    public ?array $mockManifest = null;
    public ?string $mockPackagePath = null;
    public bool $extractPackageCalled = false;
    public bool $runMigrationsCalled = false;

    /**
     * Substitutes the RSA public key used by UpdateService for signature
     * verification. Used by testSuccessfulSignatureAndUpdate() to inject
     * a test-generated key pair so the test does not depend on the
     * production update_private_key.pem (which is intentionally not
     * distributed with the repo).
     */
    public static function setUpdatePublicKey(string $pem): void
    {
        self::$updatePublicKey = $pem;
    }
    public bool $clearCacheCalled = false;
    public bool $optimizeCalled = false;

    protected function fetchManifest(): array
    {
        if ($this->mockManifest !== null) {
            return $this->mockManifest;
        }
        return parent::fetchManifest();
    }

    protected function downloadPackage(string $url): string
    {
        if ($this->mockPackagePath !== null) {
            return $this->mockPackagePath;
        }
        return parent::downloadPackage($url);
    }

    protected function extractPackage(string $zipPath): void
    {
        $this->extractPackageCalled = true;
    }

    protected function runMigrations(): int
    {
        $this->runMigrationsCalled = true;
        return 0;
    }

    protected function clearCache(): void
    {
        $this->clearCacheCalled = true;
    }

    protected function optimize(): void
    {
        $this->optimizeCalled = true;
    }
}

#[AllowMockObjectsWithoutExpectations]
class UpdateServiceTest extends TestCase
{
    private string $tempZipPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempZipPath = tempnam(sys_get_temp_dir(), 'op_test_update_') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($this->tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('version.txt', '0.2.1');
            $zip->close();
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempZipPath)) {
            @unlink($this->tempZipPath);
        }
        parent::tearDown();
    }

    private function createUpdateService(): TestableUpdateService
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('createFullBackup')->willReturn('/tmp/backup.zip');

        $health = $this->createMock(HealthChecker::class);
        $health->method('check')->willReturn(['healthy' => true]);

        $maintenance = $this->createMock(MaintenanceMode::class);

        $history = $this->createMock(UpdateHistoryRepository::class);
        $history->method('isUpdateInProgress')->willReturn(false);
        $history->method('startUpdate')->willReturn(123);

        $events = new EventManager();

        return new TestableUpdateService(
            $backup,
            $health,
            $maintenance,
            $history,
            $events
        );
    }

    public function testSecurityDomainVerification(): void
    {
        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $this->tempZipPath;
        $updater->mockManifest = [
            'releases' => [
                [
                    'version' => '0.2.1',
                    'download_url' => 'https://example.com/releases/ownpay-0.2.1.zip',
                    'checksum_sha256' => hash_file('sha256', $this->tempZipPath),
                    'signature' => 'some-signature-value'
                ]
            ]
        ];

        $result = $updater->execute('0.2.1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Security Exception', $result['error']);
        $this->assertFalse($updater->extractPackageCalled);
    }

    public function testAllowedDomainsVerification(): void
    {
        $zip1 = tempnam(sys_get_temp_dir(), 'op_test_allowed_domain_') . '.zip';
        copy($this->tempZipPath, $zip1);

        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $zip1;
        $updater->mockManifest = [
            'releases' => [
                [
                    'version' => '0.2.1',
                    'download_url' => 'https://github.com/own-pay/OwnPay/releases/download/v0.2.1/ownpay-0.2.1.zip',
                    'checksum_sha256' => hash_file('sha256', $zip1),
                    'signature' => base64_encode('invalid-signature-bytes')
                ]
            ]
        ];

        $result = $updater->execute('0.2.1');
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('Security Exception', $result['error']);
        $this->assertStringContainsString('signature verification failed', $result['error']);

        $zip2 = tempnam(sys_get_temp_dir(), 'op_test_allowed_domain_') . '.zip';
        copy($this->tempZipPath, $zip2);

        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $zip2;
        $updater->mockManifest = [
            'releases' => [
                [
                    'version' => '0.2.1',
                    'download_url' => 'https://objects.githubusercontent.com/releases/ownpay-0.2.1.zip',
                    'checksum_sha256' => hash_file('sha256', $zip2),
                    'signature' => base64_encode('invalid-signature-bytes')
                ]
            ]
        ];

        $result = $updater->execute('0.2.1');
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('Security Exception', $result['error']);
        $this->assertStringContainsString('signature verification failed', $result['error']);
    }

    public function testMissingSignature(): void
    {
        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $this->tempZipPath;
        $updater->mockManifest = [
            'releases' => [
                [
                    'version' => '0.2.1',
                    'download_url' => 'https://update.ownpay.org/releases/ownpay-0.2.1.zip',
                    'checksum_sha256' => hash_file('sha256', $this->tempZipPath),
                ]
            ]
        ];

        $result = $updater->execute('0.2.1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Cryptographic signature for v0.2.1 is missing', $result['error']);
        $this->assertFalse($updater->extractPackageCalled);
    }

    public function testInvalidChecksum(): void
    {
        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $this->tempZipPath;
        $updater->mockManifest = [
            'releases' => [
                [
                    'version' => '0.2.1',
                    'download_url' => 'https://update.ownpay.org/releases/ownpay-0.2.1.zip',
                    'checksum_sha256' => 'incorrect-checksum-hash',
                    'signature' => 'some-signature'
                ]
            ]
        ];

        $result = $updater->execute('0.2.1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Package integrity check failed', $result['error']);
        $this->assertFalse($updater->extractPackageCalled);
    }

    public function testSignatureVerificationFailure(): void
    {
        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $this->tempZipPath;
        $updater->mockManifest = [
            'releases' => [
                [
                    'version' => '0.2.1',
                    'download_url' => 'https://update.ownpay.org/releases/ownpay-0.2.1.zip',
                    'checksum_sha256' => hash_file('sha256', $this->tempZipPath),
                    'signature' => base64_encode('invalid-signature-bytes')
                ]
            ]
        ];

        $result = $updater->execute('0.2.1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('signature verification failed', $result['error']);
        $this->assertFalse($updater->extractPackageCalled);
    }

    public function testSuccessfulSignatureAndUpdate(): void
    {
        // Generate a temporary RSA key pair for this test run and inject
        // the public key into UpdateService via the protected static
        // $updatePublicKey property. This avoids the dependency on the
        // production update_private_key.pem (which is intentionally not
        // distributed with the repo) while still exercising the full
        // sign → verify → extract → migrate → cache → optimize path.
        $keyConfig = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $keyResource = openssl_pkey_new($keyConfig);
        $this->assertNotFalse($keyResource);

        $privateKeyContent = '';
        $this->assertTrue(openssl_pkey_export($keyResource, $privateKeyContent));
        $privKeyResource = openssl_pkey_get_private($privateKeyContent);
        $this->assertNotFalse($privKeyResource);

        $keyDetails = openssl_pkey_get_details($keyResource);
        $this->assertIsArray($keyDetails);
        $this->assertArrayHasKey('key', $keyDetails);
        TestableUpdateService::setUpdatePublicKey($keyDetails['key']);

        $zipData = file_get_contents($this->tempZipPath);
        $this->assertTrue(openssl_sign($zipData, $signature, $privKeyResource, OPENSSL_ALGO_SHA256));
        $signatureBase64 = base64_encode($signature);

        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $this->tempZipPath;
        $updater->mockManifest = [
            'releases' => [
                [
                    'version' => '0.2.1',
                    'download_url' => 'https://update.ownpay.org/releases/ownpay-0.2.1.zip',
                    'checksum_sha256' => hash_file('sha256', $this->tempZipPath),
                    'signature' => $signatureBase64
                ]
            ]
        ];

        $result = $updater->execute('0.2.1');

        $this->assertTrue($result['success'], isset($result['error']) ? $result['error'] : '');
        $this->assertTrue($updater->extractPackageCalled);
        $this->assertTrue($updater->runMigrationsCalled);
        $this->assertTrue($updater->clearCacheCalled);
        $this->assertTrue($updater->optimizeCalled);
    }

    public function testOptimizeRunsOnlyAfterSuccessfulUpdate(): void
    {
        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $this->tempZipPath;
        $updater->mockManifest = [
            'releases' => [
                [
                    'version' => '0.2.1',
                    'download_url' => 'https://update.ownpay.org/releases/ownpay-0.2.1.zip',
                    'checksum_sha256' => 'incorrect-checksum-hash',
                    'signature' => 'some-signature',
                ],
            ],
        ];

        $result = $updater->execute('0.2.1');

        $this->assertFalse($result['success']);
        $this->assertFalse($updater->optimizeCalled, 'A failed update must never run post-update optimization');
    }

    public function testPurgeOldLogsRemovesOnlyFilesOlderThanRetentionWindow(): void
    {
        $logsDir = sys_get_temp_dir() . '/op_test_logs_' . bin2hex(random_bytes(6));
        mkdir($logsDir, 0755, true);

        $oldFile = $logsDir . '/app-2020-01-01.log';
        $recentFile = $logsDir . '/app-today.log';
        file_put_contents($oldFile, 'stale entry');
        file_put_contents($recentFile, 'fresh entry');

        // Backdate the old file well past the retention window; leave the recent
        // file at its just-written mtime.
        touch($oldFile, time() - (40 * 86400));

        try {
            $updater = $this->createUpdateService();
            $method = new \ReflectionMethod(UpdateService::class, 'purgeOldLogs');
            $method->invoke($updater, 30, $logsDir);

            $this->assertFileDoesNotExist($oldFile, 'file older than the retention window must be removed');
            $this->assertFileExists($recentFile, 'file within the retention window must be kept');
        } finally {
            @unlink($oldFile);
            @unlink($recentFile);
            @rmdir($logsDir);
        }
    }

    /** @param array<mixed, mixed> $data */
    private function resolveManifestFields(array $data): array
    {
        $method = new \ReflectionMethod(UpdateService::class, 'resolveManifestFields');
        /** @var array<string, mixed> $result */
        $result = $method->invoke($this->createUpdateService(), $data);
        return $result;
    }

    public function testResolveManifestFieldsPrefersStableChannel(): void
    {
        $fields = $this->resolveManifestFields([
            'channels' => [
                'stable' => ['latest_version_name' => '1.0.0', 'download_url' => 'https://example.com/1.0.0.zip'],
                'beta'   => ['latest_version_name' => '1.1.0-beta', 'download_url' => 'https://example.com/1.1.0-beta.zip'],
            ],
        ]);

        $this->assertSame('1.0.0', $fields['version']);
        $this->assertSame('https://example.com/1.0.0.zip', $fields['url']);
    }

    public function testResolveManifestFieldsFallsBackToBetaWhenStableIsAbsent(): void
    {
        $fields = $this->resolveManifestFields([
            'channels' => [
                'beta' => [
                    'latest_version_name' => '0.2.0',
                    'download_url' => 'https://example.com/0.2.0.zip',
                    'changelog' => 'Some changes',
                    'checksum_sha256' => 'abc123',
                ],
            ],
        ]);

        $this->assertSame('0.2.0', $fields['version']);
        $this->assertSame('https://example.com/0.2.0.zip', $fields['url']);
        $this->assertSame('Some changes', $fields['changelog']);
        $this->assertSame('abc123', $fields['checksum']);
    }

    public function testResolveManifestFieldsFallsBackToTopLevelWhenNoChannelsPresent(): void
    {
        $fields = $this->resolveManifestFields([
            'version' => '2.0.0',
            'download_url' => 'https://example.com/2.0.0.zip',
        ]);

        $this->assertSame('2.0.0', $fields['version']);
        $this->assertSame('https://example.com/2.0.0.zip', $fields['url']);
    }

    public function testResolveManifestFieldsReturnsNullVersionWhenNothingMatches(): void
    {
        $fields = $this->resolveManifestFields(['unrelated' => 'data']);

        $this->assertNull($fields['version']);
        $this->assertNull($fields['url']);
    }

    public function testPurgeOldLogsIsANoOpWhenDirectoryIsMissing(): void
    {
        $updater = $this->createUpdateService();
        $method = new \ReflectionMethod(UpdateService::class, 'purgeOldLogs');

        // Must not throw when storage/logs (or an override) doesn't exist.
        $method->invoke($updater, 30, sys_get_temp_dir() . '/op_test_logs_missing_' . bin2hex(random_bytes(6)));
        $this->addToAssertionCount(1);
    }

    /** @return array<int, string> */
    private function splitSql(string $sql): array
    {
        $method = new \ReflectionMethod(UpdateService::class, 'splitSqlStatements');
        /** @var array<int, string> $result */
        $result = $method->invoke($this->createUpdateService(), $sql);
        return $result;
    }

    public function testSplitSqlKeepsStatementPrecededByComment(): void
    {
        $sql = "-- Add provider_trx_id column and index to op_transactions\n"
             . "ALTER TABLE `op_transactions`\n"
             . "  ADD COLUMN `provider_trx_id` VARCHAR(100) DEFAULT NULL,\n"
             . "  ADD KEY `idx_provider_trx` (`provider_trx_id`);\n";

        $statements = $this->splitSql($sql);

        $this->assertCount(1, $statements);
        $this->assertStringStartsWith('ALTER TABLE', $statements[0]);
    }

    public function testSplitSqlDropsCommentOnlyChunks(): void
    {
        $this->assertSame([], $this->splitSql("-- just a comment;\n-- another comment\n"));
        $this->assertSame([], $this->splitSql("   \n\n"));
    }

    public function testSplitSqlHandlesInterleavedCommentsAndStatements(): void
    {
        $sql = "-- step one\nDELETE FROM a WHERE x = 1;\n\n-- step two\nALTER TABLE b ADD COLUMN c INT;";

        $statements = $this->splitSql($sql);

        $this->assertCount(2, $statements);
        $this->assertStringStartsWith('DELETE FROM a', $statements[0]);
        $this->assertStringStartsWith('ALTER TABLE b', $statements[1]);
    }

    public function testSplitSqlPreservesSemicolonsAndCommentMarkersInsideStrings(): void
    {
        $sql = "INSERT INTO t (v) VALUES ('a;b -- not a comment');\nUPDATE t SET v = 'x';";

        $statements = $this->splitSql($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString("'a;b -- not a comment'", $statements[0]);
    }
}
