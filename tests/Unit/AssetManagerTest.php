<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Service\System\AssetManager;
use OwnPay\Service\System\Logger;
use PHPUnit\Framework\TestCase;

final class AssetManagerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/asset-manager-test-' . bin2hex(random_bytes(6));
        mkdir($this->logDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->logDir);
    }

    private function readLog(): string
    {
        $contents = '';
        foreach (glob($this->logDir . '/*.log') ?: [] as $file) {
            $contents .= (string) file_get_contents($file);
        }
        return $contents;
    }

    public function testEnqueueStyleAndRenderProducesLinkTag(): void
    {
        $manager = new AssetManager(new Logger('test', $this->logDir));
        $manager->enqueueStyle('my-style', '/assets/css/my-style.css');

        $html = $manager->renderStyles();

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/css/my-style.css">', $html);
    }

    public function testEnqueueScriptAndRenderProducesScriptTag(): void
    {
        $manager = new AssetManager(new Logger('test', $this->logDir));
        $manager->enqueueScript('my-script', '/assets/js/my-script.js');

        $html = $manager->renderScripts();

        $this->assertStringContainsString('<script src="/assets/js/my-script.js"></script>', $html);
    }

    public function testVersionAppendsCacheBustingQueryString(): void
    {
        $manager = new AssetManager(new Logger('test', $this->logDir));
        $manager->enqueueStyle('my-style', '/assets/css/my-style.css', [], '5');

        $html = $manager->renderStyles();

        $this->assertStringContainsString('href="/assets/css/my-style.css?v=5"', $html);
    }

    public function testDuplicateHandleKeepsFirstRegistration(): void
    {
        $manager = new AssetManager(new Logger('test', $this->logDir));
        $manager->enqueueStyle('shared', '/assets/css/first.css');
        $manager->enqueueStyle('shared', '/assets/css/second.css');

        $html = $manager->renderStyles();

        $this->assertStringContainsString('first.css', $html);
        $this->assertStringNotContainsString('second.css', $html);
        $this->assertSame(1, substr_count($html, '<link'));
    }

    public function testDependencyOrderingPrintsDependencyFirst(): void
    {
        $manager = new AssetManager(new Logger('test', $this->logDir));
        $manager->enqueueScript('a', '/assets/js/a.js', ['b']);
        $manager->enqueueScript('b', '/assets/js/b.js');

        $html = $manager->renderScripts();

        $this->assertLessThan(strpos($html, 'a.js'), strpos($html, 'b.js'));
    }

    public function testCircularDependencyDoesNotCrashAndLogsWarning(): void
    {
        $manager = new AssetManager(new Logger('test', $this->logDir));
        $manager->enqueueScript('a', '/assets/js/a.js', ['b']);
        $manager->enqueueScript('b', '/assets/js/b.js', ['a']);

        $html = $manager->renderScripts();

        $this->assertStringContainsString('a.js', $html);
        $this->assertStringContainsString('b.js', $html);
        $this->assertStringContainsString('circular dependency', strtolower($this->readLog()));
    }

    public function testMissingDependencyIsSkippedGracefully(): void
    {
        $manager = new AssetManager(new Logger('test', $this->logDir));
        $manager->enqueueScript('a', '/assets/js/a.js', ['never-enqueued']);

        $html = $manager->renderScripts();

        $this->assertStringContainsString('a.js', $html);
    }

    public function testInvalidUrlWithJavascriptSchemeIsRejected(): void
    {
        $manager = new AssetManager(new Logger('test', $this->logDir));
        $manager->enqueueScript('bad', 'javascript:alert(1)');

        $html = $manager->renderScripts();

        $this->assertSame('', trim($html));
        $this->assertStringContainsString('rejected invalid asset url', strtolower($this->readLog()));
    }

    public function testInvalidUrlWithAngleBracketsIsRejected(): void
    {
        $manager = new AssetManager(new Logger('test', $this->logDir));
        $manager->enqueueStyle('bad', '/assets/css/x.css"><script>alert(1)</script>');

        $html = $manager->renderStyles();

        $this->assertSame('', trim($html));
    }

    public function testUrlIsEscapedInOutput(): void
    {
        $manager = new AssetManager(new Logger('test', $this->logDir));
        $manager->enqueueStyle('amp', '/assets/css/style.css?foo=bar&baz=qux');

        $html = $manager->renderStyles();

        $this->assertStringContainsString('&amp;baz=qux', $html);
    }

    public function testNoEnqueuedAssetsRendersEmptyString(): void
    {
        $manager = new AssetManager(new Logger('test', $this->logDir));

        $this->assertSame('', $manager->renderStyles());
        $this->assertSame('', $manager->renderScripts());
    }
}
