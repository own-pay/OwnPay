<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use OwnPay\Update\ZipUpdateService;
use OwnPay\Update\BackupService;
use OwnPay\Update\HealthChecker;
use OwnPay\Update\MaintenanceMode;
use OwnPay\Repository\UpdateHistoryRepository;
use OwnPay\Event\EventManager;

class TestableZipUpdateService extends ZipUpdateService
{
    public bool $extractPackageCalled = false;
    public bool $runMigrationsCalled = false;
    public bool $clearCacheCalled = false;

    protected function extractPackage(string $tmpPath): void
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
}

#[AllowMockObjectsWithoutExpectations]
class ZipUpdateServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/op_zip_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /**
     * Builds a ZIP archive composed of the given entries.
     *
     * @param array<string, string> $entries Map of archive path => file content.
     * @return string Absolute path to the created ZIP.
     */
    private function createZip(array $entries): string
    {
        $zipPath = $this->tempDir . '/package-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->fail('Could not create test ZIP archive.');
        }
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $size = filesize($zipPath);
        if ($size !== false && $size < 1024) {
            $paddingZip = new \ZipArchive();
            if ($paddingZip->open($zipPath) === true) {
                $paddingZip->addFromString('src/padding.txt', random_bytes(2048));
                $paddingZip->close();
            }
        }
        return $zipPath;
    }

    /** @return array<string, mixed> */
    private function uploadedFile(string $zipPath, string $name = 'ownpay-update.zip'): array
    {
        return [
            'tmp_name' => $zipPath,
            'name'     => $name,
            'size'     => filesize($zipPath),
            'error'    => UPLOAD_ERR_OK,
        ];
    }

    private function createZipUpdateService(): TestableZipUpdateService
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('createFullBackup')->willReturn('/tmp/zip-test-backup.zip');

        $health = $this->createMock(HealthChecker::class);
        $health->method('check')->willReturn(['healthy' => true]);

        $maintenance = $this->createMock(MaintenanceMode::class);

        $history = $this->createMock(UpdateHistoryRepository::class);
        $history->method('isUpdateInProgress')->willReturn(false);
        $history->method('startManualUpdate')->willReturn(401);

        return new TestableZipUpdateService(
            $backup,
            $health,
            $maintenance,
            $history,
            new EventManager()
        );
    }

    public function testValidationRejectsNonZipExtension(): void
    {
        $txt = $this->tempDir . '/notes.txt';
        file_put_contents($txt, str_repeat('x', 2048));

        $result = $this->createZipUpdateService()->validate($this->uploadedFile($txt, 'notes.txt'));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Only .zip files are accepted', $result['error']);
    }

    public function testValidationRequiresVersionManifest(): void
    {
        $zip = $this->createZip(['version.txt' => '1.0.0']);

        $result = $this->createZipUpdateService()->validate($this->uploadedFile($zip));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('version.json', $result['error']);
    }

    public function testValidationRejectsPathTraversal(): void
    {
        $zip = $this->createZipWithInvalidContent(['../evil.php' => '<?php echo 1;']);

        $result = $this->createZipUpdateService()->validate($this->uploadedFile($zip));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('unsafe paths', $result['error']);
    }

    /**
     * Creates a ZIP containing the given offending entries plus a valid
     * version.json so the manifest check does not short-circuit first.
     *
     * @param array<string, string> $entries
     */
    private function createZipWithInvalidContent(array $entries): string
    {
        $entries['version.json'] = json_encode(['version' => '9.9.9']);
        return $this->createZip($entries);
    }

    public function testValidationAcceptsValidPackageAndExtractsVersion(): void
    {
        $zip = $this->createZip([
            'version.json' => json_encode(['version' => '2.1.0']),
            'config/app.php' => '<?php return [];',
        ]);

        $result = $this->createZipUpdateService()->validate($this->uploadedFile($zip));

        $this->assertTrue($result['valid'], isset($result['error']) ? $result['error'] : '');
        $this->assertSame('2.1.0', $result['version']);
    }

    public function testExecuteSkipsWhenUpdateIsAlreadyRunning(): void
    {
        $zip = $this->createZip(['version.json' => json_encode(['version' => '2.1.0'])]);

        $backup = $this->createMock(BackupService::class);
        $health = $this->createMock(HealthChecker::class);
        $maintenance = $this->createMock(MaintenanceMode::class);
        $history = $this->createMock(UpdateHistoryRepository::class);
        $history->method('isUpdateInProgress')->willReturn(true);

        $service = new TestableZipUpdateService($backup, $health, $maintenance, $history, new EventManager());

        $result = $service->execute($this->uploadedFile($zip), 'admin@example.com', '2.1.0');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already in progress', $result['error']);
        $this->assertFalse($service->extractPackageCalled);
    }

    public function testExecuteRunsFullPipelineOnValidPackage(): void
    {
        $zip = $this->createZip([
            'version.json' => json_encode(['version' => '2.1.0']),
            'src/Update/X.php' => '<?php echo 1;',
        ]);

        $service = $this->createZipUpdateService();

        $result = $service->execute($this->uploadedFile($zip), 'admin@example.com', '2.1.0');

        $this->assertTrue($result['success'], isset($result['error']) ? $result['error'] : '');
        $this->assertTrue($service->extractPackageCalled);
        $this->assertTrue($service->runMigrationsCalled);
        $this->assertTrue($service->clearCacheCalled);
    }

    public function testExecuteRejectsUnsafeGuestFileOutsideAllowedDirectories(): void
    {
        $zip = $this->createZipWithInvalidContent(['uploads/evil.php' => '<?php echo 1;']);

        $service = $this->createZipUpdateService();

        $result = $service->execute($this->uploadedFile($zip), 'admin@example.com', '2.1.0');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('outside approved directories', $result['error']);
        $this->assertFalse($service->extractPackageCalled);
    }

    public function testExecuteRollsBackWhenHealthCheckFails(): void
    {
        $zip = $this->createZip(['version.json' => json_encode(['version' => '2.1.0'])]);

        $backup = $this->createMock(BackupService::class);
        $backup->method('createFullBackup')->willReturn('/tmp/zip-test-backup.zip');

        $health = $this->createMock(HealthChecker::class);
        $health->method('check')->willReturn(['healthy' => false, 'error' => 'database unreachable']);

        $history = $this->createMock(UpdateHistoryRepository::class);
        $history->method('isUpdateInProgress')->willReturn(false);
        $history->method('startManualUpdate')->willReturn(402);

        $backup->expects($this->once())->method('restore')->with('/tmp/zip-test-backup.zip');
        $history->expects($this->once())->method('markRolledBack');

        $service = new TestableZipUpdateService(
            $backup,
            $health,
            $this->createMock(MaintenanceMode::class),
            $history,
            new EventManager()
        );

        $result = $service->execute($this->uploadedFile($zip), 'admin@example.com', '2.1.0');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['rollback']);
        $this->assertStringContainsString('Health check failed', $result['error']);
    }

    public function testValidationReportsAbsentSignature(): void
    {
        $zip = $this->createZip(['version.json' => json_encode(['version' => '2.1.0'])]);

        $result = $this->createZipUpdateService()->validate($this->uploadedFile($zip));

        $this->assertTrue($result['valid']);
        $this->assertSame('absent', $result['signature']);
    }

    public function testValidationAcceptsValidSignature(): void
    {
        $privateKeyPath = dirname(__DIR__, 2) . '/update_private_key.pem';
        if (!file_exists($privateKeyPath) || !function_exists('openssl_verify')) {
            $this->markTestSkipped('update_private_key.pem not found in project root.');
        }

        $zip = $this->createSignedZip($privateKeyPath);

        $result = $this->createZipUpdateService()->validate($this->uploadedFile($zip));

        $this->assertTrue($result['valid']);
        $this->assertSame('verified', $result['signature']);
    }

    public function testExecuteAppliesSignedPackage(): void
    {
        $privateKeyPath = dirname(__DIR__, 2) . '/update_private_key.pem';
        if (!file_exists($privateKeyPath) || !function_exists('openssl_verify')) {
            $this->markTestSkipped('update_private_key.pem not found in project root.');
        }

        $zip = $this->createSignedZip($privateKeyPath);
        $service = $this->createZipUpdateService();

        $result = $service->execute($this->uploadedFile($zip), 'admin@example.com', '2.1.0');

        $this->assertTrue($result['success'], isset($result['error']) ? $result['error'] : '');
        $this->assertTrue($service->extractPackageCalled);
    }

    public function testExecuteRejectsVersionMismatchWithManifest(): void
    {
        $zip = $this->createZip(['version.json' => json_encode(['version' => '2.1.0'])]);

        $service = $this->createZipUpdateService();

        $result = $service->execute($this->uploadedFile($zip), 'admin@example.com', '9.9.9');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Version mismatch', $result['error']);
        $this->assertFalse($service->extractPackageCalled);
    }

    public function testExecuteRequiresVersionManifest(): void
    {
        $zip = $this->createZip(['src/Update/Foo.php' => '<?php echo 1;']);
        $history = $this->createMock(UpdateHistoryRepository::class);
        $history->method('isUpdateInProgress')->willReturn(false);

        $service = new TestableZipUpdateService(
            $this->createMock(BackupService::class),
            $this->createMock(HealthChecker::class),
            $this->createMock(MaintenanceMode::class),
            $history,
            new EventManager()
        );

        $result = $service->execute($this->uploadedFile($zip), 'admin@example.com', '1.0.0');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('version.json', $result['error']);
        $this->assertFalse($service->extractPackageCalled);
    }

    public function testValidationRejectsInvalidSignature(): void
    {
        $zip = $this->createZip([
            'version.json' => json_encode(['version' => '2.1.0']),
            'signature.sig' => base64_encode('not-a-real-signature'),
        ]);

        $result = $this->createZipUpdateService()->validate($this->uploadedFile($zip));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Signature', $result['error']);
    }

    /**
     * Creates a ZIP signed by the project private key.
     *
     * @param string $privateKeyPath Path to the PEM private key.
     * @return string Path to the signed ZIP.
     */
    private function createSignedZip(string $privateKeyPath): string
    {
        $manifest = ['version' => '2.1.0'];

        $signedPayload = hash('sha256', json_encode($manifest));
        $privKeyResource = openssl_pkey_get_private(file_get_contents($privateKeyPath));
        $this->assertNotFalse($privKeyResource);
        $this->assertTrue(openssl_sign($signedPayload, $signature, $privKeyResource, OPENSSL_ALGO_SHA256));

        return $this->createZip([
            'version.json'  => json_encode($manifest),
            'signature.sig' => base64_encode($signature),
        ]);
    }
}