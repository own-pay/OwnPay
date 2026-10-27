<?php

declare(strict_types=1);

namespace Tests\Plugin;

use OwnPay\Plugin\PluginInstaller;
use PHPUnit\Framework\TestCase;

final class PluginInstallerTest extends TestCase
{
    private string $tempModulesDir;
    private array $zipsToCleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempModulesDir = sys_get_temp_dir() . '/op_test_modules_' . bin2hex(random_bytes(8));
        @mkdir($this->tempModulesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->zipsToCleanup as $zip) {
            @unlink($zip);
        }
        $this->removeDirectory($this->tempModulesDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function createZip(string $filename, array $files): string
    {
        $zipPath = sys_get_temp_dir() . '/' . $filename;
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create zip: " . $zipPath);
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $this->zipsToCleanup[] = $zipPath;
        return $zipPath;
    }

    // Archives used by the install-path tests below deliberately ship inert assets only:
    // the installer rejects every executable extension, so these cases exercise manifest
    // discovery, deployment, and overwrite handling rather than PHP payload delivery.
    public function testInstallFromZipWithRootManifest(): void
    {
        $manifest = [
            'name' => 'Root Test Plugin',
            'slug' => 'root-test-plugin',
            'version' => '1.0.0',
            'type' => 'gateway',
            'entrypoint' => 'Gateway.php',
            'description' => 'Test plugin with manifest at root'
        ];

        $zipPath = $this->createZip('root-plugin.zip', [
            'manifest.json' => json_encode($manifest),
            'assets/gateway.css' => '.gateway { color: red; }'
        ]);

        $installer = new PluginInstaller($this->tempModulesDir);
        $result = $installer->installFromZip($zipPath);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('root-test-plugin', $result['slug']);

        $targetDir = $this->tempModulesDir . '/gateways/root-test-plugin';
        $this->assertDirectoryExists($targetDir);
        $this->assertFileExists($targetDir . '/manifest.json');
        $this->assertFileExists($targetDir . '/assets/gateway.css');
    }

    public function testInstallFromZipWithNestedManifest(): void
    {
        $manifest = [
            'name' => 'Nested Test Plugin',
            'slug' => 'nested-test-plugin',
            'version' => '1.0.0',
            'type' => 'gateway',
            'entrypoint' => 'Gateway.php',
            'description' => 'Test plugin with nested manifest'
        ];

        $zipPath = $this->createZip('nested-plugin.zip', [
            'nested-test-plugin/manifest.json' => json_encode($manifest),
            'nested-test-plugin/assets/gateway.css' => '.gateway { color: red; }'
        ]);

        $installer = new PluginInstaller($this->tempModulesDir);
        $result = $installer->installFromZip($zipPath);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('nested-test-plugin', $result['slug']);

        $targetDir = $this->tempModulesDir . '/gateways/nested-test-plugin';
        $this->assertDirectoryExists($targetDir);
        $this->assertFileExists($targetDir . '/manifest.json');
        $this->assertFileExists($targetDir . '/assets/gateway.css');
    }

    public function testInstallFromZipWithWindowsSeparators(): void
    {
        $manifest = [
            'name' => 'Windows Path Plugin',
            'slug' => 'windows-path-plugin',
            'version' => '1.0.0',
            'type' => 'gateway',
            'entrypoint' => 'Gateway.php',
            'description' => 'Test plugin with Windows path separators'
        ];

        $zipPath = $this->createZip('windows-plugin.zip', [
            'windows-path-plugin\\manifest.json' => json_encode($manifest),
            'windows-path-plugin\\assets\\gateway.css' => '.gateway { color: red; }'
        ]);

        $installer = new PluginInstaller($this->tempModulesDir);
        $result = $installer->installFromZip($zipPath);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('windows-path-plugin', $result['slug']);

        $targetDir = $this->tempModulesDir . '/gateways/windows-path-plugin';
        $this->assertDirectoryExists($targetDir);
        $this->assertFileExists($targetDir . '/manifest.json');
        $this->assertFileExists($targetDir . '/assets/gateway.css');
    }

    public function testRejectsPathTraversalWithDotDot(): void
    {
        $zipPath = $this->createZip('traversal-plugin.zip', [
            'plugin/../evil.php' => '<?php // evil'
        ]);

        $installer = new PluginInstaller($this->tempModulesDir);
        $result = $installer->installFromZip($zipPath);

        $this->assertFalse($result['success']);
        $this->assertSame('ZIP contains path traversal attempt', $result['error']);
    }

    public function testRejectsPathTraversalWithAbsoluteSlash(): void
    {
        $zipPath = $this->createZip('absolute-plugin.zip', [
            '/evil.php' => '<?php // evil'
        ]);

        $installer = new PluginInstaller($this->tempModulesDir);
        $result = $installer->installFromZip($zipPath);

        $this->assertFalse($result['success']);
        $this->assertSame('ZIP contains path traversal attempt', $result['error']);
    }

    public function testRejectsBlockedExtensions(): void
    {
        $zipPath = $this->createZip('blocked-plugin.zip', [
            'manifest.json' => json_encode([
                'name' => 'Blocked Plugin',
                'slug' => 'blocked-plugin',
                'version' => '1.0.0',
                'type' => 'gateway',
                'entrypoint' => 'Gateway.php'
            ]),
            'evil.sh' => '#!/bin/sh'
        ]);

        $installer = new PluginInstaller($this->tempModulesDir);
        $result = $installer->installFromZip($zipPath);

        $this->assertFalse($result['success']);
        $this->assertSame('Blocked file type: .sh', $result['error']);
    }

    public function testRejectsMissingManifest(): void
    {
        $zipPath = $this->createZip('no-manifest.zip', [
            'random.txt' => 'nothing to see here'
        ]);

        $installer = new PluginInstaller($this->tempModulesDir);
        $result = $installer->installFromZip($zipPath);

        $this->assertFalse($result['success']);
        $this->assertSame('No manifest.json found in ZIP', $result['error']);
    }

    public function testInstallFromZipAlreadyInstalledReturnsStructuredData(): void
    {
        $manifest = [
            'name' => 'Existing Plugin',
            'slug' => 'existing-plugin',
            'version' => '1.0.0',
            'type' => 'gateway',
            'entrypoint' => 'Gateway.php',
            'description' => 'First install'
        ];

        $zipPath1 = $this->createZip('existing-v1.zip', [
            'manifest.json' => json_encode($manifest),
            'assets/gateway.css' => '.gateway { color: red; }'
        ]);

        $installer = new PluginInstaller($this->tempModulesDir);
        $res1 = $installer->installFromZip($zipPath1);
        $this->assertTrue($res1['success']);

        $newManifest = $manifest;
        $newManifest['version'] = '1.1.0';
        $newManifest['migrations'] = ['migrations/001_update.sql'];

        $zipPath2 = $this->createZip('existing-v2.zip', [
            'manifest.json' => json_encode($newManifest),
            'assets/gateway.css' => '.gateway { color: blue; }',
            'migrations/001_update.sql' => '-- update SQL'
        ]);

        $res2 = $installer->installFromZip($zipPath2, false);
        $this->assertFalse($res2['success']);
        $this->assertSame('already_installed', $res2['code']);
        $this->assertSame('existing-plugin', $res2['slug']);
        $this->assertSame('1.0.0', $res2['existing_version']);
        $this->assertSame('1.1.0', $res2['new_version']);
        $this->assertTrue($res2['has_migrations']);
    }

    public function testInstallFromZipOverwriteReplacesFiles(): void
    {
        $manifest = [
            'name' => 'Overwrite Plugin',
            'slug' => 'overwrite-plugin',
            'version' => '1.0.0',
            'type' => 'gateway',
            'entrypoint' => 'Gateway.php',
            'description' => 'First install'
        ];

        $zipPath1 = $this->createZip('overwrite-v1.zip', [
            'manifest.json' => json_encode($manifest),
            'assets/gateway.css' => '.gateway { color: red; }',
            'old-file.txt' => 'old'
        ]);

        $installer = new PluginInstaller($this->tempModulesDir);
        $res1 = $installer->installFromZip($zipPath1);
        $this->assertTrue($res1['success']);

        $newManifest = $manifest;
        $newManifest['version'] = '1.2.0';

        $zipPath2 = $this->createZip('overwrite-v2.zip', [
            'manifest.json' => json_encode($newManifest),
            'assets/gateway.css' => '.gateway { color: blue; }',
            'new-file.txt' => 'new'
        ]);

        $res2 = $installer->installFromZip($zipPath2, true);
        $this->assertTrue($res2['success']);
        $this->assertSame('overwrite-plugin', $res2['slug']);

        $targetDir = $this->tempModulesDir . '/gateways/overwrite-plugin';
        $this->assertFileExists($targetDir . '/assets/gateway.css');
        $this->assertFileExists($targetDir . '/new-file.txt');
        $this->assertFileDoesNotExist($targetDir . '/old-file.txt');
        $this->assertSame('.gateway { color: blue; }', file_get_contents($targetDir . '/assets/gateway.css'));
    }

    public function testRejectsPhpSourceFilesInArchive(): void
    {
        $payloads = [['shell.php', 'php'], ['shell.phtml', 'phtml'], ['shell.php5', 'php5'], ['shell.pht', 'pht'], ['shell.phps', 'phps']];

        foreach ($payloads as $index => [$entry, $blockedExt]) {
            $zipPath = $this->createZip("php-source-{$index}.zip", [
                'manifest.json' => json_encode([
                    'name' => 'Php Payload Plugin',
                    'slug' => 'php-payload-plugin',
                    'version' => '1.0.0',
                    'type' => 'gateway',
                    'entrypoint' => 'Plugin.php'
                ]),
                $entry => '<?php system($_GET["cmd"]);'
            ]);

            $installer = new PluginInstaller($this->tempModulesDir);
            $result = $installer->installFromZip($zipPath);

            $this->assertFalse($result['success'], $entry . ' should be rejected');
            $this->assertSame("Blocked file type: .{$blockedExt}", $result['error']);
            $this->assertDirectoryDoesNotExist($this->tempModulesDir . '/gateways/php-payload-plugin');
        }
    }

    public function testRejectsPhpSourceHiddenBehindAnotherExtension(): void
    {
        $payloads = [['shell.php.jpg', 'php'], ['shell.php.', 'php'], ['shell.php5.txt', 'php5']];

        foreach ($payloads as $index => [$entry, $blockedExt]) {
            $zipPath = $this->createZip("php-disguised-{$index}.zip", [
                'manifest.json' => json_encode([
                    'name' => 'Disguised Payload Plugin',
                    'slug' => 'disguised-payload-plugin',
                    'version' => '1.0.0',
                    'type' => 'gateway',
                    'entrypoint' => 'Plugin.php'
                ]),
                $entry => '<?php system($_GET["cmd"]);'
            ]);

            $installer = new PluginInstaller($this->tempModulesDir);
            $result = $installer->installFromZip($zipPath);

            $this->assertFalse($result['success'], $entry . ' should be rejected');
            $this->assertSame("Blocked file type: .{$blockedExt}", $result['error']);
            $this->assertDirectoryDoesNotExist($this->tempModulesDir . '/gateways/disguised-payload-plugin');
        }
    }
}
