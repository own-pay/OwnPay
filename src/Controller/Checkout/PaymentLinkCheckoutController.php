<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;
use OwnPay\Repository\PaymentLinkRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Repository\SettingsRepository;
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

    /**
     * GET /pay/{slug} — show payment link checkout (fixed amount) or amount entry form.
     *
     * C-01 FIX: Session-bind txn creation → reuse existing pending txn on refresh.
     * Prevents DB flooding on repeated GET visits. use_count NOT incremented here
     * (moved to payment completion).
     */
    public function show(Request $req): Response
    {
        $slug = (string) $req->param('slug');
        $link = $this->linkRepo->findActiveBySlug($slug);
        $twig = $this->c->get(\Twig\Environment::class);

        if (!$link) {
            return $this->renderExpired($twig);
        }

        // Check max_uses (only count COMPLETED transactions, not pending ones)
        if ($link['max_uses'] > 0 && ($link['use_count'] ?? 0) >= $link['max_uses']) {
            return $this->renderExpired($twig);
        }
        if (!empty($link['expires_at']) && DateHelper::isPast($link['expires_at'])) {
            return $this->renderExpired($twig);
        }

        $amount = $link['amount'] ?? $req->query('amount', '0');
        $amt = (float) $amount;

        // CHK-004 FIX: Validate GET ?amount param against min/max bounds
        if ($amt > 0) {
            $minAmount = (float) ($link['min_amount'] ?? 0);
            $maxAmount = (float) ($link['max_amount'] ?? 0);
            if (($minAmount > 0 && $amt < $minAmount) || ($maxAmount > 0 && $amt > $maxAmount)) {
                $amt = 0; // Force amount entry form with error
                $amount = '0';
            }
        }

        if ($amt <= 0) {
            // M-02 FIX: Inject CSRF token into template data
            $csrf = $_SESSION['csrf_token'] ?? '';
            $tpl = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
            $error = ($req->query('amount', '') !== '' && (float) $req->query('amount', '0') > 0)
                ? 'Amount is out of valid bounds.'
                : null;
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'csrf_token' => $csrf,
                'error'      => $error,
            ]));
        }

        // C-01 FIX: Check session for existing pending txn for this link
        $sessionKey = 'pay_link_txn_' . $link['id'];
        if (!empty($_SESSION[$sessionKey])) {
            $existingTxn = $this->txnRepo->findActiveForCheckout($_SESSION[$sessionKey]);
            if ($existingTxn) {
                return Response::redirect("/checkout/{$existingTxn['trx_id']}");
            }
            // Stale session entry — remove it
            unset($_SESSION[$sessionKey]);
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

        // C-01 FIX: Store txn ref in session for dedup on refresh
        $_SESSION[$sessionKey] = $trxId;

        // NOTE: use_count NOT incremented here — moved to payment completion hook

        return Response::redirect("/checkout/{$trxId}");
    }

    /**
     * POST /pay/{slug}/submit — customer-entered amount form submission.
     *
     * H-02 FIX: Validate min/max amount bounds.
     * H-04 FIX: Session dedup prevents double-click duplicate transactions.
     */
    public function submit(Request $req): Response
    {
        $slug = (string) $req->param('slug');
        $link = $this->linkRepo->findActiveBySlug($slug);
        $twig = $this->c->get(\Twig\Environment::class);

        if (!$link) {
            return $this->renderExpired($twig);
        }

        $amount = (float) $req->post('amount', '0');
        $csrf = $_SESSION['csrf_token'] ?? '';

        // Basic validation
        if ($amount <= 0) {
            $tpl = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'error'      => 'Please enter a valid amount',
                'csrf_token' => $csrf,
            ]));
        }

        // H-02 FIX: Enforce min/max amount bounds
        $minAmount = (float) ($link['min_amount'] ?? 0);
        $maxAmount = (float) ($link['max_amount'] ?? 0);

        if ($minAmount > 0 && $amount < $minAmount) {
            $tpl = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
            $currency = $link['currency'] ?? 'BDT';
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'error'      => "Minimum amount is {$minAmount} {$currency}",
                'csrf_token' => $csrf,
            ]));
        }
        if ($maxAmount > 0 && $amount > $maxAmount) {
            $tpl = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
            $currency = $link['currency'] ?? 'BDT';
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'error'      => "Maximum amount is {$maxAmount} {$currency}",
                'csrf_token' => $csrf,
            ]));
        }

        // H-04 FIX: Session dedup — prevent double-click creating duplicate txns
        $sessionKey = 'pay_link_txn_' . $link['id'];
        if (!empty($_SESSION[$sessionKey])) {
            $existingTxn = $this->txnRepo->findActiveForCheckout($_SESSION[$sessionKey]);
            if ($existingTxn) {
                return Response::redirect("/checkout/{$existingTxn['trx_id']}");
            }
            unset($_SESSION[$sessionKey]);
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

        $_SESSION[$sessionKey] = $trxId;

        return Response::redirect("/checkout/{$trxId}");
    }

    /**
     * Render expired/invalid status page with proper brand data (M-01 FIX).
     */
    private function renderExpired(\Twig\Environment $twig): Response
    {
        $tpl = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
        return Response::html($twig->render($tpl, [
            'status'       => 'expired',
            'status_label' => 'Payment Link Expired',
            'txn'          => [],
            'brand'        => ['name' => 'Own Pay', 'logo' => '', 'color' => '#0D9488', 'support_email' => ''],
            'lang'         => [],
        ]));
    }
}
