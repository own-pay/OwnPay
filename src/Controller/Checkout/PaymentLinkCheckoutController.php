<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;
use OwnPay\Repository\PaymentLinkRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Support\DateHelper;

final class PaymentLinkCheckoutController
{
    private Container $c;
    private EventManager $events;
    private PaymentLinkRepository $linkRepo;
    private TransactionRepository $txnRepo;

    public function __construct(Container $c, EventManager $events, PaymentLinkRepository $linkRepo, TransactionRepository $txnRepo)
    {
        $this->c        = $c;
        $this->events   = $events;
        $this->linkRepo = $linkRepo;
        $this->txnRepo  = $txnRepo;
    }

    public function show(Request $req): Response
    {
        $slug = (string) $req->param('slug');
        $link = $this->linkRepo->findActiveBySlug($slug);
        $twig = $this->c->get(\Twig\Environment::class);

        if (!$link) {
            $tpl = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
            return Response::html($twig->render($tpl, ['status' => 'expired', 'txn' => []]));
        }

        if ($link['max_uses'] > 0 && ($link['use_count'] ?? 0) >= $link['max_uses']) {
            $tpl = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
            return Response::html($twig->render($tpl, ['status' => 'expired', 'txn' => []]));
        }
        if (!empty($link['expires_at']) && DateHelper::isPast($link['expires_at'])) {
            $tpl = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
            return Response::html($twig->render($tpl, ['status' => 'expired', 'txn' => []]));
        }

        $amount = $link['amount'] ?? $req->get('amount', '0');
        if ((float) $amount <= 0) {
            $tpl = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
            return Response::html($twig->render($tpl, ['link' => $link]));
        }

        $trxId = 'TXN-' . strtoupper(bin2hex(random_bytes(8)));
        $uuid  = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $amt   = (float) $amount;

        $this->txnRepo->create([
            'uuid'         => $uuid,
            'trx_id'       => $trxId,
            'merchant_id'  => $link['merchant_id'],
            'gateway_slug' => 'link',
            'amount'       => $amt,
            'net_amount'   => $amt,
            'currency'     => $link['currency'] ?? 'BDT',
            'method'       => 'link',
            'status'       => 'pending',
            'metadata'     => json_encode(['payment_link_id' => $link['id']]),
        ]);

        $this->linkRepo->incrementUseCount($link['id']);

        return Response::redirect("/checkout/{$trxId}");
    }

    public function submit(Request $req): Response
    {
        $slug = (string) $req->param('slug');
        $link = $this->linkRepo->findActiveBySlug($slug);
        $twig = $this->c->get(\Twig\Environment::class);

        if (!$link) {
            $tpl = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
            return Response::html($twig->render($tpl, ['status' => 'expired', 'txn' => []]));
        }

        $amount = (float) $req->post('amount', '0');
        if ($amount <= 0) {
            $tpl = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
            return Response::html($twig->render($tpl, ['link' => $link, 'error' => 'Please enter a valid amount']));
        }

        $trxId = 'TXN-' . strtoupper(bin2hex(random_bytes(8)));
        $uuid  = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $this->txnRepo->create([
            'uuid'         => $uuid,
            'trx_id'       => $trxId,
            'merchant_id'  => $link['merchant_id'],
            'gateway_slug' => 'link',
            'amount'       => $amount,
            'net_amount'   => $amount,
            'currency'     => $link['currency'] ?? 'BDT',
            'method'       => 'link',
            'status'       => 'pending',
            'metadata'     => json_encode(['payment_link_id' => $link['id']]),
        ]);

        $this->linkRepo->incrementUseCount($link['id']);

        return Response::redirect("/checkout/{$trxId}");
    }
}
