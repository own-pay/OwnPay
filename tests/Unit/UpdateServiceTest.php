<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use OwnPay\Update\UpdatePackageVerifier;
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
     * Substitutes the RSA public key used by UpdateService for transport-level
     * signature verification. Test harness only.
     */
    public static function setUpdatePublicKey(string $pem): void
    {
        self::$updatePublicKey = $pem;
    }

    /**
     * Substitutes the package verifier's trust store. Test harness only.
     */
    public static function setTrustedKeys(?array $trustedKeys): void
    {
        self::$trustedKeys = $trustedKeys;
    }

    public bool $clearCacheCalled = false;
    public bool $optimizeCalled = false;

    /** @var array<string, string>|null */
    private static ?array $trustedKeys = null;

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

    protected function createPackageVerifier(): UpdatePackageVerifier
    {
        if (self::$trustedKeys !== null) {
            return new UpdatePackageVerifier(self::$trustedKeys);
        }
        return parent::createPackageVerifier();
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
    private string $privateKey;
    private string $publicKeyPem = '';
    private \OpenSSLAsymmetricKey|\OpenSSLResource|null $privateKeyResource = null;

    protected function setUp(): void
    {
        parent::setUp();

        $keyConfig = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        if (getenv('OPENSSL_CONF') !== false && is_file((string) getenv('OPENSSL_CONF'))) {
            $keyConfig['config'] = (string) getenv('OPENSSL_CONF');
        } elseif (is_file(dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf')) {
            $keyConfig['config'] = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        }
        $keyResource = openssl_pkey_new($keyConfig);
        $this->assertNotFalse($keyResource);

        $privateKeyContent = '';
        $this->assertTrue(openssl_pkey_export($keyResource, $privateKeyContent, null, $keyConfig));
        $this->assertTrue(openssl_pkey_get_private($privateKeyContent) !== false);

        $keyDetails = openssl_pkey_get_details($keyResource);
        $this->assertIsArray($keyDetails);
        $this->assertArrayHasKey('key', $keyDetails);
        $this->privateKey = $privateKeyContent;
        $this->publicKeyPem = $keyDetails['key'];
        $this->privateKeyResource = openssl_pkey_get_private($privateKeyContent);
        $this->assertNotFalse($this->privateKeyResource);

        TestableUpdateService::setUpdatePublicKey($this->publicKeyPem);
        TestableUpdateService::setTrustedKeys(['ownpay-update-v1' => $this->publicKeyPem]);
    }

    protected function tearDown(): void
    {
        TestableUpdateService::setTrustedKeys(null);
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

        return new TestableUpdateService(
            $backup,
            $health,
            $maintenance,
            $history,
            new EventManager()
        );
    }

    /**
     * Builds a v2 update package: manifest with a per-file SHA-256 map, signed
     * manifest, plus signature.sig over the manifest digest.
     *
     * @param array<string, string> $files Extra files to embed (path => content).
     */
    private function createV2Package(array $files, string $version): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'op_test_v2_') . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));

        $fileHashes = [];
        foreach ($files as $name => $content) {
            $this->assertTrue($zip->addFromString($name, $content));
            $fileHashes[$name] = hash('sha256', $content);
        }

        $manifest = [
            'scheme'    => 'ownpay-update-v2',
            'version'   => $version,
            'package'   => 'ownpay',
            'issued_at' => gmdate('c'),
            'key_id'    => 'ownpay-update-v1',
            'files'     => $fileHashes,
        ];
        $manifestBytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($manifestBytes);
        $this->assertTrue($zip->addFromString('version.json', $manifestBytes));

        $signature = '';
        $this->assertTrue(openssl_sign(hash('sha256', $manifestBytes), $signature, $this->privateKeyResource, OPENSSL_ALGO_SHA256));
        $this->assertTrue($zip->addFromString('signature.sig', base64_encode($signature)));
        $zip->close();
        return $zipPath;
    }

    /**
     * Builds a v1 (legacy, unsigned manifest chain) package.
     *
     * @param array<string, string> $files
     */
    private function createV1Package(array $files, string $version): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'op_test_v1_') . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $name => $content) {
            $this->assertTrue($zip->addFromString($name, $content));
        }
        $this->assertTrue($zip->addFromString('version.json', json_encode(['version' => $version])));
        $zip->close();
        return $zipPath;
    }

    /** @return array<string, string> */
    private function validManifest(string $zipPath, string $signatureBase64, string $version = '0.3.0', string $url = 'https://update.ownpay.org/releases/ownpay-0.3.0.zip'): array
    {
        return [
            'releases' => [
                [
                    'version'          => $version,
                    'download_url'     => $url,
                    'checksum_sha256'  => hash_file('sha256', $zipPath),
                    'signature'        => $signatureBase64,
                ],
            ],
        ];
    }

    private function signZipBytes(string $zipPath): string
    {
        $zipData = file_get_contents($zipPath);
        $this->assertIsString($zipData);
        $signature = '';
        $this->assertTrue(openssl_sign($zipData, $signature, $this->privateKeyResource, OPENSSL_ALGO_SHA256));
        return base64_encode($signature);
    }

    public function testSecurityDomainVerification(): void
    {
        $zip = $this->createV2Package(['src/Example.php' => '<?php echo 1;'], '0.3.0');

        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $zip;
        $updater->mockManifest = $this->validManifest($zip, 'some-signature-value', '0.3.0', 'https://example.com/releases/ownpay-0.3.0.zip');

        $result = $updater->execute('0.3.0');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Security Exception', $result['error']);
        $this->assertFalse($updater->extractPackageCalled);
        if (file_exists($zip)) { @unlink($zip); }
    }

    public function testAllowedDomainsVerification(): void
    {
        foreach (['https://github.com/own-pay/OwnPay/releases/download/v0.3.0/ownpay-0.3.0.zip', 'https://objects.githubusercontent.com/releases/ownpay-0.3.0.zip'] as $url) {
            $zip = $this->createV2Package(['version.txt' => '0.3.0'], '0.3.0');
            $updater = $this->createUpdateService();
            $updater->mockPackagePath = $zip;
            $updater->mockManifest = $this->validManifest($zip, base64_encode('invalid-signature-bytes'), '0.3.0', $url);

            $result = $updater->execute('0.3.0');

            $this->assertFalse($result['success']);
            $this->assertStringNotContainsString('Security Exception', $result['error']);
            $this->assertStringContainsString('signature verification failed', $result['error']);
        }
        if (file_exists($zip)) { @unlink($zip); }
    }

    public function testMissingSignature(): void
    {
        $zip = $this->createV2Package(['version.txt' => '0.3.0'], '0.3.0');

        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $zip;
        $updater->mockManifest = [
            'releases' => [
                [
                    'version'         => '0.3.0',
                    'download_url'    => 'https://update.ownpay.org/releases/ownpay-0.3.0.zip',
                    'checksum_sha256' => hash_file('sha256', $zip),
                ],
            ],
        ];

        $result = $updater->execute('0.3.0');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Cryptographic signature for v0.3.0 is missing', $result['error']);
        $this->assertFalse($updater->extractPackageCalled);
        if (file_exists($zip)) { @unlink($zip); }
    }

    public function testInvalidChecksum(): void
    {
        $zip = $this->createV2Package(['version.txt' => '0.3.0'], '0.3.0');

        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $zip;
        $updater->mockManifest = $this->validManifest($zip, 'some-signature');
        $updater->mockManifest['releases'][0]['checksum_sha256'] = 'incorrect-checksum-hash';

        $result = $updater->execute('0.3.0');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Package integrity check failed', $result['error']);
        $this->assertFalse($updater->extractPackageCalled);
        if (file_exists($zip)) { @unlink($zip); }
    }

    public function testSignatureVerificationFailure(): void
    {
        $zip = $this->createV2Package(['version.txt' => '0.3.0'], '0.3.0');

        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $zip;
        $updater->mockManifest = $this->validManifest($zip, base64_encode('invalid-signature-bytes'));

        $result = $updater->execute('0.3.0');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('signature verification failed', $result['error']);
        $this->assertFalse($updater->extractPackageCalled);
        if (file_exists($zip)) { @unlink($zip); }
    }

    public function testSuccessfulSignatureAndUpdate(): void
    {
        $zip = $this->createV2Package(['version.txt' => '0.3.0', 'src/Example.php' => '<?php echo 1;'], '0.3.0');
        $signatureBase64 = $this->signZipBytes($zip);

        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $zip;
        $updater->mockManifest = $this->validManifest($zip, $signatureBase64);

        $result = $updater->execute('0.3.0');

        $this->assertTrue($result['success'], isset($result['error']) ? $result['error'] : '');
        $this->assertTrue($updater->extractPackageCalled);
        $this->assertTrue($updater->runMigrationsCalled);
        $this->assertTrue($updater->clearCacheCalled);
        $this->assertTrue($updater->optimizeCalled);
        if (file_exists($zip)) { @unlink($zip); }
    }

    public function testOptimizeRunsOnlyAfterSuccessfulUpdate(): void
    {
        $zip = $this->createV2Package(['version.txt' => '0.3.0'], '0.3.0');

        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $zip;
        $updater->mockManifest = $this->validManifest($zip, 'some-signature');
        $updater->mockManifest['releases'][0]['checksum_sha256'] = 'incorrect-checksum-hash';

        $result = $updater->execute('0.3.0');

        $this->assertFalse($result['success']);
        $this->assertFalse($updater->optimizeCalled, 'A failed update must never run post-update optimization');
        if (file_exists($zip)) { @unlink($zip); }
    }

    public function testDowngradeIsRejected(): void
    {
        $zip = $this->createV2Package(['version.txt' => '0.2.0'], '0.2.0');
        $signatureBase64 = $this->signZipBytes($zip);

        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $zip;
        $updater->mockManifest = $this->validManifest($zip, $signatureBase64, '0.2.0', 'https://update.ownpay.org/releases/ownpay-0.2.0.zip');

        $result = $updater->execute('0.2.0');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('older than the installed version', $result['error']);
        $this->assertFalse($updater->extractPackageCalled);
        if (file_exists($zip)) { @unlink($zip); }
    }

    public function testV1CodePackageIsRejected(): void
    {
        $zip = $this->createV1Package(['src/Example.php' => '<?php echo 1;'], '0.3.0');
        $signatureBase64 = $this->signZipBytes($zip);

        $updater = $this->createUpdateService();
        $updater->mockPackagePath = $zip;
        $updater->mockManifest = $this->validManifest($zip, $signatureBase64);

        $result = $updater->execute('0.3.0');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('v2 scheme', $result['error']);
        $this->assertFalse($updater->extractPackageCalled);
        if (file_exists($zip)) { @unlink($zip); }
    }
}
