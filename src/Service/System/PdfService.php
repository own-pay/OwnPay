<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * PDF service — invoice/receipt generation using TCPDF or DomPDF.
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
     * Generate PDF from HTML template.
     *
     * @return string File path to generated PDF
     */
    public function generateFromHtml(string $html, string $filename, array $options = []): string
    {
        $orientation = $options['orientation'] ?? 'portrait';
        $paperSize = $options['paper'] ?? 'A4';

        // Use DomPDF if available
        if (class_exists('\Dompdf\Dompdf')) {
            return $this->generateWithDompdf($html, $filename, $orientation, $paperSize);
        }

        // Fallback: save HTML as-is with .html extension
        $path = $this->outputDir . '/' . $filename . '.html';
        file_put_contents($path, $html);
        return $path;
    }

    /**
     * Generate invoice PDF.
     */
    public function generateInvoice(array $invoiceData, string $templateHtml): string
    {
        // Replace template variables
        $html = $templateHtml;
        foreach ($invoiceData as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $html = str_replace('{{' . $key . '}}', htmlspecialchars((string) $value, ENT_QUOTES), $html);
            }
        }

        // Build items table
        if (isset($invoiceData['items']) && is_array($invoiceData['items'])) {
            $itemsHtml = '';
            foreach ($invoiceData['items'] as $item) {
                $itemsHtml .= '<tr>';
                $itemsHtml .= '<td>' . htmlspecialchars($item['description'] ?? '') . '</td>';
                $itemsHtml .= '<td>' . htmlspecialchars($item['quantity'] ?? '1') . '</td>';
                $itemsHtml .= '<td>' . htmlspecialchars($item['amount'] ?? '0.00') . '</td>';
                $itemsHtml .= '</tr>';
            }
            $html = str_replace('{{items_rows}}', $itemsHtml, $html);
        }

        $filename = 'invoice_' . ($invoiceData['invoice_number'] ?? date('YmdHis'));
        return $this->generateFromHtml($html, $filename);
    }

    private function generateWithDompdf(string $html, string $filename, string $orientation, string $paperSize): string
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paperSize, $orientation);
        $dompdf->render();

        $path = $this->outputDir . '/' . $filename . '.pdf';
        file_put_contents($path, $dompdf->output());

        return $path;
    }
}
