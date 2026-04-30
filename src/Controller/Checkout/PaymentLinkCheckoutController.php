<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

final class PaymentLinkCheckoutController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function show(Request $req, string $slug): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $link = $db->fetchOne("SELECT * FROM op_payment_links WHERE slug = :slug AND status = 'active'", ['slug' => $slug]);

        if (!$link) {
            $twig = $this->c->get(\Twig\Environment::class);
            return Response::html($twig->render('checkout/checkout-status.twig', ['status' => 'expired', 'txn' => []]));
        }

        // Check limits
        if ($link['max_uses'] > 0 && ($link['use_count'] ?? 0) >= $link['max_uses']) {
            $twig = $this->c->get(\Twig\Environment::class);
            return Response::html($twig->render('checkout/checkout-status.twig', ['status' => 'expired', 'txn' => []]));
        }
        if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) {
            $twig = $this->c->get(\Twig\Environment::class);
            return Response::html($twig->render('checkout/checkout-status.twig', ['status' => 'expired', 'txn' => []]));
        }

        // Create transaction from payment link
        $amount = $link['amount'] ?? $req->get('amount', '0');
        if ((float) $amount <= 0) {
            // Show amount input form
            $twig = $this->c->get(\Twig\Environment::class);
            return Response::html($twig->render('checkout/payment-link-amount.twig', ['link' => $link]));
        }

        $trxId = 'TXN-' . strtoupper(bin2hex(random_bytes(8)));
        $db->insert("INSERT INTO op_transactions (trx_id, merchant_id, amount, currency, status, metadata, created_at) VALUES (:trx, :mid, :amt, :cur, 'pending', :meta, NOW())", [
            'trx' => $trxId, 'mid' => $link['merchant_id'], 'amt' => $amount, 'cur' => $link['currency'] ?? 'BDT',
            'meta' => json_encode(['payment_link_id' => $link['id']]),
        ]);

        // Increment use count
        $db->update("UPDATE op_payment_links SET use_count = use_count + 1 WHERE id = :id", ['id' => $link['id']]);

        return Response::redirect("/checkout/{$trxId}");
    }
}
