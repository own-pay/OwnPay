<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

final class InvoiceCheckoutController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function show(Request $req, string $invoiceNumber): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $invoice = $db->fetchOne("SELECT * FROM op_invoices WHERE invoice_number = :num AND status != 'paid'", ['num' => $invoiceNumber]);
        if (!$invoice) {
            $twig = $this->c->get(\Twig\Environment::class);
            return Response::html($twig->render('checkout/checkout-status.twig', ['status' => 'expired', 'txn' => []]));
        }

        // Create transaction from invoice if needed, redirect to checkout
        $txn = $db->fetchOne("SELECT trx_id FROM op_transactions WHERE invoice_id = :iid AND status = 'pending'", ['iid' => $invoice['id']]);
        if ($txn) return Response::redirect("/checkout/{$txn['trx_id']}");

        // Create new transaction
        $trxId = 'TXN-' . strtoupper(bin2hex(random_bytes(8)));
        $db->insert("INSERT INTO op_transactions (trx_id, merchant_id, invoice_id, amount, currency, status, created_at) VALUES (:trx, :mid, :iid, :amt, :cur, 'pending', NOW())", [
            'trx' => $trxId, 'mid' => $invoice['merchant_id'], 'iid' => $invoice['id'],
            'amt' => $invoice['total'], 'cur' => $invoice['currency'],
        ]);
        return Response::redirect("/checkout/{$trxId}");
    }
}
