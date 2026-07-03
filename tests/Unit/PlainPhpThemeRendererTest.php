<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\View\Theme\PlainPhpThemeRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlainPhpThemeRendererTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = __DIR__ . '/../fixtures/plain-php/greeting.php';
    }

    public function testRendersTemplateWithContextVariables(): void
    {
        $html = (new PlainPhpThemeRenderer())->render($this->fixture, ['name' => 'World']);
        $this->assertStringContainsString('Hello World', $html);
    }

    public function testEscHelperEscapesHtml(): void
    {
        $html = (new PlainPhpThemeRenderer())->render($this->fixture, ['name' => '<script>x</script>']);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testThrowsWhenTemplateMissing(): void
    {
        $this->expectException(RuntimeException::class);
        (new PlainPhpThemeRenderer())->render('/no/such/template.php', []);
    }

    public function testTemplateExceptionPropagates(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'plainphp') . '.php';
        file_put_contents($tmp, "<?php throw new \\LogicException('boom');");
        try {
            $this->expectException(\LogicException::class);
            (new PlainPhpThemeRenderer())->render($tmp, []);
        } finally {
            @unlink($tmp);
        }
    }
}
