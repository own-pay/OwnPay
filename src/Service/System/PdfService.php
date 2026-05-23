<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * Service orchestrating invoice/receipt rendering.
 *
 * Compiles dynamic print-friendly HTML representations of invoice objects
 * for generation, preview, or translation to physical PDFs via external providers.
 */
final class PdfService
{
    /**
     * Absolute directory path where rendered files will be saved.
     *
     * @var string
     */
    private string $outputDir;

    /**
     * Initialises the PDF service.
     *
     * Creates the output folder structure if missing.
     *
     * @param string|null $outputDir Override destination folder path. Defaults to system storage/pdf.
     */
    public function __construct(?string $outputDir = null)
    {
        $this->outputDir = $outputDir ?? dirname(__DIR__, 3) . '/storage/pdf';
        if (!is_dir($this->outputDir)) {
            @mkdir($this->outputDir, 0755, true);
        }
    }

    /**
     * Wraps raw markup with print-friendly styles and writes it to an HTML file.
     *
     * @param string $html Raw body content.
     * @param string $filename Target output filename (without extension).
     * @param array<string, mixed> $options Optional engine parameters.
     * @return string Absolute file path to the generated HTML document.
     */
    public function generateFromHtml(string $html, string $filename, array $options = []): string
    {
        $path = $this->outputDir . '/' . $filename . '.html';
        file_put_contents($path, $this->wrapPrintableHtml($html));
        return $path;
    }

    /**
     * Renders a printable invoice document by compiling transaction data into an HTML template.
     *
     * Iterates over invoice parameters, applies HTML encoding, parses line items,
     * and exports the output file to the designated directory.
     *
     * @param array<string, mixed> $invoiceData Parameter map containing the invoice details and line items.
     * @param string $templateHtml Base HTML layout layout string.
     * @return string Absolute file path to the compiled HTML document.
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
                if (is_array($item)) {
                    $descVal = $item['description'] ?? '';
                    $qtyVal = $item['quantity'] ?? '1';
                    $amtVal = $item['amount'] ?? '0.00';
                    $desc = is_scalar($descVal) ? (string) $descVal : '';
                    $qty = is_scalar($qtyVal) ? (string) $qtyVal : '1';
                    $amt = is_scalar($amtVal) ? (string) $amtVal : '0.00';

                    $itemsHtml .= '<tr>';
                    $itemsHtml .= '<td>' . htmlspecialchars($desc) . '</td>';
                    $itemsHtml .= '<td>' . htmlspecialchars($qty) . '</td>';
                    $itemsHtml .= '<td>' . htmlspecialchars($amt) . '</td>';
                    $itemsHtml .= '</tr>';
                }
            }
            $html = str_replace('{{items_rows}}', $itemsHtml, $html);
        }

        $invNumVal = $invoiceData['invoice_number'] ?? null;
        $invNum = is_scalar($invNumVal) ? (string)$invNumVal : (new \DateTimeImmutable())->format('YmdHis');
        $filename = 'invoice_' . $invNum;
        return $this->generateFromHtml($html, $filename);
    }

    /**
     * Encloses the HTML body in a print-ready document structure.
     *
     * Applies standard A4 page margin specifications and clean font hierarchies.
     *
     * @param string $body Document content markup.
     * @return string Compiled printable document HTML string.
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
