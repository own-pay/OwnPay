<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * PDF service — invoice/receipt generation using HTML templates.
 *
 * Generates HTML output for invoices. For production PDF output,
 * use a headless browser (wkhtmltopdf, Puppeteer) or integrate
 * a composer PDF library in a future sprint.
 */
final class PdfService
{
    private string $outputDir;

    public function __construct(?string $outputDir = null)
    {
        $this->outputDir = $outputDir ?? dirname(__DIR__, 3) . '/storage/pdf';
        if (!is_dir($this->outputDir)) {
            @mkdir($this->outputDir, 0755, true);
        }
    }

    /**
     * Generate PDF-ready HTML from template.
     *
     * @return string File path to generated HTML
     */
    public function generateFromHtml(string $html, string $filename, array $options = []): string
    {
        $path = $this->outputDir . '/' . $filename . '.html';
        file_put_contents($path, $this->wrapPrintableHtml($html));
        return $path;
    }

    /**
     * Generate invoice HTML.
     */
    public function generateInvoice(array $invoiceData, string $templateHtml): string
    {
        $html = $templateHtml;
        foreach ($invoiceData as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $html = str_replace('{{' . $key . '}}', htmlspecialchars((string) $value, ENT_QUOTES), $html);
            }
        }

        if (isset($invoiceData['items']) && is_array($invoiceData['items'])) {
            $itemsHtml = '';
            foreach ($invoiceData['items'] as $item) {
                $itemsHtml .= '<tr>';
                $itemsHtml .= '<td>' . htmlspecialchars($item['description'] ?? '') . '</td>';
                $itemsHtml .= '<td>' . htmlspecialchars((string) ($item['quantity'] ?? '1')) . '</td>';
                $itemsHtml .= '<td>' . htmlspecialchars((string) ($item['amount'] ?? '0.00')) . '</td>';
                $itemsHtml .= '</tr>';
            }
            $html = str_replace('{{items_rows}}', $itemsHtml, $html);
        }

        $filename = 'invoice_' . ($invoiceData['invoice_number'] ?? (new \DateTimeImmutable())->format('YmdHis'));
        return $this->generateFromHtml($html, $filename);
    }

    /**
     * Wrap HTML with print-friendly styles for browser PDF print.
     */
    private function wrapPrintableHtml(string $body): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                @media print { body { margin: 0; } @page { size: A4; margin: 20mm; } }
                body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 14px; color: #1a1a2e; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 8px 12px; border: 1px solid #dee2e6; text-align: left; }
                th { background: #f8f9fa; font-weight: 600; }
            </style>
        </head>
        <body>{$body}</body>
        </html>
        HTML;
    }
}
