<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * PDF Service
 *
 * Handles PDF generation for transaction receipts.
 */
class PdfService
{
    public static function op_downloadReceiptPDF($data = [])
{

    if (!$data) {
        die('Invalid transaction');
    }

    $tx = $data['transaction'];
    $brand = $data['brand'];

    $amountPaid = money_add(money_sub($tx['amount'], $tx['discount_amount']), $tx['processing_fee']);

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 15);

    if (!empty($brand['logo'])) {
        $pdf->Image($brand['logo'], 10, 10, 35);
    }

    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetXY(50, 12);
    $pdf->Cell(0, 8, $brand['name'], 0, 1);

    $pdf->SetFont('Arial', '', 10);
    $pdf->SetX(50);
    $pdf->Cell(0, 6, $brand['address']['city'] . ', ' . $brand['address']['country'], 0, 1);

    $pdf->Ln(10);

    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Payment Receipt', 0, 1, 'C');

    $status = strtoupper($tx['status']);

    $statusColors = [
        'COMPLETED' => [46, 204, 113],
        'PENDING' => [241, 196, 15],
        'REFUNDED' => [52, 152, 219],
        'CANCELED' => [231, 76, 60],
    ];

    $color = $statusColors[$status] ?? [120, 120, 120];

    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor($color[0], $color[1], $color[2]);
    $pdf->Cell(0, 8, 'STATUS: ' . $status, 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);

    $pdf->Ln(6);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, 'Amount Paid', 0, 1, 'C');

    $pdf->SetFont('Arial', 'B', 22);
    $pdf->Cell(0, 12, money_round($amountPaid, 2), 0, 1, 'C');

    $pdf->Ln(2);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, 'Local Net Amount: ' . money_round($tx['local_net_amount'], 2) . ' ' . $tx['local_currency'], 0, 1, 'C');

    $pdf->Ln(6);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(6);

    sectionTitle($pdf, 'Transaction Details');
    infoRow($pdf, 'Transaction Ref', $tx['ref']);
    infoRow($pdf, 'Payment Method', $tx['payment_method']);
    infoRow($pdf, 'Created Date', convertUTCtoUserTZ($tx['created_date'], empty($brand['locale']['timezone']) ? 'Asia/Dhaka' : $brand['locale']['timezone'], "M d, Y h:i A"));

    $pdf->Ln(3);
    sectionTitle($pdf, 'Customer Details');
    infoRow($pdf, 'Name', $tx['customer']['name']);
    infoRow($pdf, 'Email', $tx['customer']['email']);
    infoRow($pdf, 'Mobile', $tx['customer']['mobile']);

    $pdf->Ln(3);
    sectionTitle($pdf, 'Payment Breakdown');
    infoRow($pdf, 'Amount', money_round($tx['amount'], 2) . ' ' . $tx['currency']);
    infoRow($pdf, 'Discount', money_round($tx['discount_amount'], 2) . ' ' . $tx['currency']);
    infoRow($pdf, 'Processing Fee', money_round($tx['processing_fee'], 2) . ' ' . $tx['currency']);


    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 6, 'This is a system generated receipt.', 0, 1, 'C');

    $pdf->Output('D', 'Receipt-' . $tx['ref'] . '.pdf');
}

    private static function sectionTitle($pdf, $title)
{
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(0, 8, $title, 0, 1);
}

    private static function infoRow($pdf, $label, $value)
{
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, $label, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, $value, 0, 1);
}
}
