<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Service\System\PdfService;
use PHPUnit\Framework\TestCase;

final class PdfServicePathTraversalTest extends TestCase
{
    private string $outputDir;
    private PdfService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputDir = sys_get_temp_dir() . '/ownpay-pdf-test-' . uniqid();
        $this->service = new PdfService($this->outputDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDir)) {
            foreach (glob($this->outputDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->outputDir);
        }
        // Clean up any traversal-target file a pre-fix run would have escaped into.
        @unlink(dirname($this->outputDir) . '/pwned.html');
        parent::tearDown();
    }

    public function testGenerateInvoiceFilenameIsDerivedFromIdNotInvoiceNumber(): void
    {
        $path = $this->service->generateInvoice(
            ['id' => 42, 'invoice_number' => '../../../pwned', 'items' => []],
            '<p>{{invoice_number}}</p>'
        );

        $this->assertSame($this->outputDir . '/invoice_42.html', $path);
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist(dirname($this->outputDir) . '/pwned.html');
    }

    public function testGenerateInvoiceHandlesMissingIdSafely(): void
    {
        $path = $this->service->generateInvoice(
            ['invoice_number' => '../../../etc/passwd', 'items' => []],
            '<p>no id</p>'
        );

        $this->assertStringStartsWith($this->outputDir . '/invoice_', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertFileExists($path);
    }

    public function testGenerateFromHtmlSanitizesTraversalFilenameDirectly(): void
    {
        $path = $this->service->generateFromHtml('<p>hi</p>', '../../../pwned2');

        $this->assertFileExists($path);
        $this->assertStringStartsWith($this->outputDir, $path);
        $this->assertFileDoesNotExist(dirname($this->outputDir, 3) . '/pwned2.html');
    }
}
