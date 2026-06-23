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

/**
 * Test subclass to isolate network/filesystem operations.
 */
class TestableUpdateService extends UpdateService
{
    public ?array $mockManifest = null;
    public ?string $mockPackagePath = null;
    public bool $extractPackageCalled = false;
    public bool $runMigrationsCalled = false;
    public bool $clearCacheCalled = false;

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
        // Do not actually extract files in tests to avoid overwriting workspace
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
}

#[AllowMockObjectsWithoutExpectations]
class UpdateServiceTest extends TestCase
{
    private string $tempZipPath;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a dummy zip file
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

    /**
     * Helper to build mocks.
     */
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
                    'download_url' => 'https://malicious-domain.com/releases/ownpay-0.2.1.zip',
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
        // 1. github.com should be allowed (it passes domain check and fails at signature check)
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

        // 2. objects.githubusercontent.com should be allowed
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
                    // Missing signature
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
        $privateKeyPath = dirname(__DIR__, 2) . '/update_private_key.pem';
        if (!file_exists($privateKeyPath)) {
            $this->markTestSkipped('update_private_key.pem not found in project root. Skipping success path verification.');
        }

        $privateKeyContent = file_get_contents($privateKeyPath);
        $privKeyResource = openssl_pkey_get_private($privateKeyContent);
        $this->assertNotFalse($privKeyResource, 'Private key resource should be valid.');

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
    }

    /**
     * Invokes the private splitSqlStatements() for direct verification.
     *
     * @return array<int, string>
     */
    private function splitSql(string $sql): array
    {
        $method = new \ReflectionMethod(UpdateService::class, 'splitSqlStatements');
        /** @var array<int, string> $result */
        $result = $method->invoke($this->createUpdateService(), $sql);
        return $result;
    }

    public function testSplitSqlKeepsStatementPrecededByComment(): void
    {
        // Regression: a statement whose chunk starts with a '-- comment' line
        // used to be discarded entirely — the migration was then marked as
        // executed without its DDL ever running (silent schema drift). This is
        // the exact shape of migration 008_add_provider_trx_id.sql.
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
