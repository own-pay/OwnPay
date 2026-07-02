<?php

declare(strict_types=1);

// `move_uploaded_file()` only succeeds against files PHP's SAPI actually registered as an HTTP
// upload, which is impossible to reproduce in a CLI test process. Shadowing it inside
// OwnPay\Service\System (PHP resolves unqualified calls against the *calling* namespace first)
// lets FilesystemService's production code path run unmodified while tests swap in a plain
// copy+unlink. Production runtime never loads this file, so the real move_uploaded_file() is
// used outside of tests.
namespace OwnPay\Service\System {
    if (!function_exists(__NAMESPACE__ . '\\move_uploaded_file')) {
        function move_uploaded_file(string $from, string $to): bool
        {
            return @copy($from, $to) && @unlink($from);
        }
    }
}

namespace Tests\Service {

    use OwnPay\Service\System\FilesystemService;
    use PHPUnit\Framework\TestCase;

    final class FilesystemServiceTest extends TestCase
    {
        private string $storageDir;
        private string $publicUploadsDir;
        private FilesystemService $fs;

        /** Minimal valid 1x1 transparent PNG, used so finfo's MIME sniff passes. */
        private const PNG_BYTES_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        protected function setUp(): void
        {
            $root = sys_get_temp_dir() . '/ownpay-fs-test-' . bin2hex(random_bytes(6));
            $this->storageDir = $root . '/storage';
            $this->publicUploadsDir = $root . '/public/assets/uploads';
            mkdir($this->storageDir, 0755, true);
            mkdir($this->publicUploadsDir, 0755, true);
            $this->fs = new FilesystemService($this->storageDir, $this->publicUploadsDir);
        }

        protected function tearDown(): void
        {
            $this->removeDir(dirname($this->storageDir));
        }

        private function removeDir(string $dir): void
        {
            if (!is_dir($dir)) {
                return;
            }
            $items = scandir($dir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . '/' . $item;
                is_dir($path) ? $this->removeDir($path) : unlink($path);
            }
            rmdir($dir);
        }

        /** @return array{error: int, name: string, tmp_name: string} */
        private function fakeUploadedPng(string $originalName = 'logo.png'): array
        {
            $tmp = tempnam(sys_get_temp_dir(), 'op-upload-');
            file_put_contents($tmp, base64_decode(self::PNG_BYTES_BASE64));
            return ['error' => UPLOAD_ERR_OK, 'name' => $originalName, 'tmp_name' => $tmp];
        }

        public function testStorePublicUploadMovesFileUnderPublicAssetsAndReturnsPublicUrl(): void
        {
            $path = $this->fs->storePublicUpload($this->fakeUploadedPng(), 'gateways');

            // Must be a complete, web-root-relative URL (leading slash) starting with /assets/uploads/...
            $this->assertStringStartsWith('/assets/uploads/gateways/', $path);
            $this->assertStringEndsWith('.png', $path);

            $diskPath = $this->publicUploadsDir . substr($path, strlen('/assets/uploads'));
            $this->assertFileExists($diskPath, 'Uploaded file must physically exist under the public uploads directory');
        }

        public function testStorePublicUploadRejectsDisallowedExtension(): void
        {
            $tmp = tempnam(sys_get_temp_dir(), 'op-upload-');
            file_put_contents($tmp, '<?php echo "evil"; ?>');
            $file = ['error' => UPLOAD_ERR_OK, 'name' => 'shell.php', 'tmp_name' => $tmp];

            $this->expectException(\RuntimeException::class);
            $this->fs->storePublicUpload($file, 'gateways');
        }

        public function testStoreUploadStillWritesUnderPrivateStorageDir(): void
        {
            $path = $this->fs->storeUpload($this->fakeUploadedPng(), 'uploads/disputes');

            // Unchanged contract: relative path with no leading slash, resolvable under storage/.
            $this->assertStringStartsWith('uploads/disputes/', $path);
            $this->assertFileExists($this->storageDir . '/' . $path);
        }
    }
}
