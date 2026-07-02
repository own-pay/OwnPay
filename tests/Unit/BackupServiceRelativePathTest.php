<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Update\BackupService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression coverage for BackupService::backupCode()'s relative-path computation.
 *
 * IDEs (PhpStorm) flag `RecursiveIteratorIterator::getSubPathname()` as an undefined method
 * because the class doesn't declare it - the call only works via PHP's documented (but
 * reflection-invisible) __call() forwarding to the current inner RecursiveDirectoryIterator.
 * Behaviorally this was always correct at runtime, but calling it on the actual
 * RecursiveDirectoryIterator (via getInnerIterator()) is equally correct, IDE-clean, and doesn't
 * rely on undocumented-looking magic dispatch. This test locks in the resulting path is unchanged.
 */
final class BackupServiceRelativePathTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ownpay-backup-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/nested', 0755, true);
        file_put_contents($this->tmpDir . '/nested/file.txt', 'x');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir . '/nested/file.txt');
        @rmdir($this->tmpDir . '/nested');
        @rmdir($this->tmpDir);
    }

    public function testComputesRelativePathIncludingDirPrefix(): void
    {
        $service = (new ReflectionClass(BackupService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BackupService::class, 'relativePathForIteratedFile');
        $method->setAccessible(true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $found = null;
        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $found = $method->invoke($service, 'mydir', $iterator);
            }
        }

        // getSubPathname() returns OS-native separators (backslash on Windows); normalize before
        // comparing since that's a pre-existing characteristic of this call, not under test here.
        $this->assertSame('mydir/nested/file.txt', str_replace('\\', '/', (string) $found));
    }
}
