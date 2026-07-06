<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Plugin\ThemeSecurityScanner;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ThemeSecurityScanner's AST-based detection. Each test writes a small
 * fixture PHP file to a temp directory (mimicking a theme's templates/ folder), scans
 * it, and asserts on the resulting blocked/warning lists.
 */
final class ThemeSecurityScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/theme-scan-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/templates/checkout', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeTemplate(string $relativePath, string $content): void
    {
        $fullPath = $this->tmpDir . '/' . $relativePath;
        mkdir(dirname($fullPath), 0777, true);
        file_put_contents($fullPath, $content);
    }

    public function testCleanTemplateProducesNoFindings(): void
    {
        $this->writeTemplate('templates/checkout/checkout.php', <<<'PHP'
        <?php
        /** @var callable $esc */
        /** @var mixed $txn */
        $amount = is_array($txn ?? null) ? ($txn['amount'] ?? '0.00') : '0.00';
        echo $esc($amount);
        PHP);

        $result = ThemeSecurityScanner::scan($this->tmpDir);

        $this->assertSame([], $result['blocked']);
        $this->assertSame([], $result['warnings']);
    }

    public function testBlocksProcessExecutionFunctionCall(): void
    {
        $this->writeTemplate('templates/checkout/checkout.php', <<<'PHP'
        <?php
        system('ls -la');
        PHP);

        $result = ThemeSecurityScanner::scan($this->tmpDir);

        $this->assertNotEmpty($result['blocked']);
        $this->assertStringContainsString('system', $result['blocked'][0]);
        $this->assertStringContainsString('checkout.php', $result['blocked'][0]);
    }

    public function testBlocksDirectDatabaseClassReference(): void
    {
        $this->writeTemplate('templates/checkout/checkout.php', <<<'PHP'
        <?php
        $db = \OwnPay\Core\Database::getInstance();
        PHP);

        $result = ThemeSecurityScanner::scan($this->tmpDir);

        $this->assertNotEmpty($result['blocked']);
        $this->assertStringContainsString('Database', $result['blocked'][0]);
    }

    public function testBlocksDirectPdoInstantiation(): void
    {
        $this->writeTemplate('templates/checkout/checkout.php', <<<'PHP'
        <?php
        $pdo = new \PDO('mysql:host=localhost', 'root', '');
        PHP);

        $result = ThemeSecurityScanner::scan($this->tmpDir);

        $this->assertNotEmpty($result['blocked']);
        $this->assertStringContainsString('PDO', $result['blocked'][0]);
    }

    public function testBlocksMysqliFunctionCall(): void
    {
        $this->writeTemplate('templates/checkout/checkout.php', <<<'PHP'
        <?php
        $conn = mysqli_connect('localhost', 'root', '');
        PHP);

        $result = ThemeSecurityScanner::scan($this->tmpDir);

        $this->assertNotEmpty($result['blocked']);
        $this->assertStringContainsString('mysqli_connect', $result['blocked'][0]);
    }

    public function testWarnsOnServerSuperglobalAccessBeyondSafeAllowlist(): void
    {
        $this->writeTemplate('templates/checkout/checkout.php', <<<'PHP'
        <?php
        echo $_SERVER['DOCUMENT_ROOT'];
        PHP);

        $result = ThemeSecurityScanner::scan($this->tmpDir);

        $this->assertSame([], $result['blocked']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testAllowsSafeAllowlistedServerKeysWithoutWarning(): void
    {
        $this->writeTemplate('templates/checkout/checkout.php', <<<'PHP'
        <?php
        echo $_SERVER['REQUEST_URI'];
        echo $_SERVER['HTTP_HOST'];
        PHP);

        $result = ThemeSecurityScanner::scan($this->tmpDir);

        $this->assertSame([], $result['blocked']);
        $this->assertSame([], $result['warnings']);
    }

    public function testWarnsOnGlobalsSuperglobalAccess(): void
    {
        $this->writeTemplate('templates/checkout/checkout.php', <<<'PHP'
        <?php
        echo $GLOBALS['foo'];
        PHP);

        $result = ThemeSecurityScanner::scan($this->tmpDir);

        $this->assertSame([], $result['blocked']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testThemeWithNoPhpTemplatesProducesNoFindings(): void
    {
        // No templates/ files written at all - only the empty directory from setUp().
        $result = ThemeSecurityScanner::scan($this->tmpDir);

        $this->assertSame([], $result['blocked']);
        $this->assertSame([], $result['warnings']);
    }

    public function testOnlyScansFilesUnderTemplatesDirectory(): void
    {
        // A dangerous call outside templates/ (e.g. the theme's own entry class) must
        // NOT be flagged by this scanner - that's PluginLoader's existing separate scan.
        mkdir($this->tmpDir . '/src', 0777, true);
        file_put_contents($this->tmpDir . '/src/Theme.php', "<?php\nsystem('ls');\n");

        $result = ThemeSecurityScanner::scan($this->tmpDir);

        $this->assertSame([], $result['blocked']);
    }
}
