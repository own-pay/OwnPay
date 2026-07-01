<?php
declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\View\TwigExtensions;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the plugin-hook output sanitizer against the bypasses
 * the previous single-pass, quoted-only implementation allowed:
 * split-tag reassembly, unquoted event handlers, unquoted javascript: URIs,
 * and self-closing <link> elements.
 */
final class HookOutputSanitizerTest extends TestCase
{
    private function sanitize(string $html): string
    {
        $ext = new TwigExtensions(new Container());
        $method = new \ReflectionMethod(TwigExtensions::class, 'sanitizeHookOutput');
        /** @var string $result */
        $result = $method->invoke($ext, $html);
        return $result;
    }

    public function testPreservesSafeMarkup(): void
    {
        $html = '<div class="widget"><a href="/admin/reports">Reports</a><ul><li>Item</li></ul></div>';
        $this->assertSame($html, $this->sanitize($html));
    }

    public function testStripsScriptBlocks(): void
    {
        $this->assertStringNotContainsString('<script', strtolower($this->sanitize('<p>x</p><script>alert(1)</script>')));
    }

    public function testStripsSplitTagReassembly(): void
    {
        // One strip pass turns <scr<script>ipt> into <script> - the loop must
        // keep going until nothing dangerous remains.
        $result = strtolower($this->sanitize('<scr<script>ipt>alert(1)</scr</script>ipt>'));
        $this->assertStringNotContainsString('<script', $result);
    }

    public function testStripsUnquotedEventHandlers(): void
    {
        $result = $this->sanitize('<img src=x onerror=alert(1)>');
        $this->assertStringNotContainsString('onerror', $result);

        $result = $this->sanitize('<svg onload=alert(document.cookie)></svg>');
        $this->assertStringNotContainsString('onload', $result);
    }

    public function testStripsQuotedEventHandlers(): void
    {
        $result = $this->sanitize('<div onclick="doEvil()">x</div>');
        $this->assertStringNotContainsString('onclick', $result);
    }

    public function testStripsJavascriptUrisQuotedAndUnquoted(): void
    {
        $this->assertStringNotContainsString('javascript:', $this->sanitize('<a href="javascript:alert(1)">x</a>'));
        $this->assertStringNotContainsString('javascript:', $this->sanitize('<a href=javascript:alert(1)>x</a>'));
    }

    public function testStripsSelfClosingLinkAndMeta(): void
    {
        $result = strtolower($this->sanitize('<link rel="stylesheet" href="https://evil.example/x.css"><meta http-equiv="refresh" content="0;url=https://evil.example">'));
        $this->assertStringNotContainsString('<link', $result);
        $this->assertStringNotContainsString('<meta', $result);
    }

    public function testStripsIframesAndForms(): void
    {
        $result = strtolower($this->sanitize('<iframe src="https://evil.example"></iframe><form action="/admin/staff/store" method="post"><input name="x"></form>'));
        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringNotContainsString('<form', $result);
    }
}
