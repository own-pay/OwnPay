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

/**
 * Controller handling public payment link checkouts.
 *
 * This controller resolves payment links by slug, checks expiration, status, usage limits,
 * handles custom amount user forms (with min/max bounds validation via BCMath), and routes the
 * customer to the transaction room. It includes deduplication rules to prevent double-click transactions.
 */
final class PaymentLinkCheckoutController
{
    /**
     * @var \OwnPay\Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var \OwnPay\Event\EventManager The event manager instance.
     */
    private EventManager $events;

    /**
     * @var \OwnPay\Repository\PaymentLinkRepository The payment link repository.
     */
    private PaymentLinkRepository $linkRepo;

    /**
     * @var \OwnPay\Repository\TransactionRepository The transaction repository.
     */
    private TransactionRepository $txnRepo;

    /**
     * Initializes the controller with necessary system dependencies.
     *
     * @param \OwnPay\Container $c The dependency injection container.
     * @param \OwnPay\Event\EventManager $events The event manager.
     * @param \OwnPay\Repository\PaymentLinkRepository $linkRepo Repository for payment link database access.
     * @param \OwnPay\Repository\TransactionRepository $txnRepo Repository for transaction database access.
     */
    public function __construct(Container $c, EventManager $events, PaymentLinkRepository $linkRepo, TransactionRepository $txnRepo)
    {
        $this->c        = $c;
        $this->events   = $events;
        $this->linkRepo = $linkRepo;
        $this->txnRepo  = $txnRepo;
    }

    /**
     * Renders the payment link checkout screen or redirects to the transaction.
     *
     * If the payment link has a fixed amount, it initializes a pending transaction (if one does not
     * already exist in session) and redirects the user to the checkout room. If it is a variable-amount
     * link, it presents the amount entry form. Enforces validation of limits, expiration, and min/max amount.
     *
     * @param \OwnPay\Http\Request $req The incoming HTTP request.
     * @return \OwnPay\Http\Response The HTTP response.
     * @throws \Exception If transaction token creation fails.
     */
    public function show(Request $req): Response
    {
        $slug = (string) $req->param('slug');
        $link = $this->linkRepo->findActiveBySlug($slug);
        $twig = $this->c->get(\Twig\Environment::class);

        if (!$link) {
            return $this->renderExpired($twig);
        }

        // Verify usage limits: ensure the link has not exceeded its maximum allowed completions.
        if ($link['max_uses'] > 0 && ($link['use_count'] ?? 0) >= $link['max_uses']) {
            return $this->renderExpired($twig);
        }
        if (!empty($link['expires_at']) && DateHelper::isPast($link['expires_at'])) {
            return $this->renderExpired($twig);
        }

        $amount = (string) ($link['amount'] ?? $req->query('amount', '0'));
        if (!is_numeric($amount)) $amount = '0';

        // Validate query amount: verify that the amount parameter remains within the configured minimum and maximum limits (using high-precision BCMath comparisons).
        if (bccomp($amount, '0', 2) > 0) {
            $minAmount = (string) ($link['min_amount'] ?? '0');
            $maxAmount = (string) ($link['max_amount'] ?? '0');
            if ((bccomp($minAmount, '0', 2) > 0 && bccomp($amount, $minAmount, 2) < 0)
                || (bccomp($maxAmount, '0', 2) > 0 && bccomp($amount, $maxAmount, 2) > 0)) {
                $amount = '0';
            }
        }

        if (bccomp($amount, '0', 2) <= 0) {
            // Retrieve the dynamic CSRF token to secure the form submission.
            $csrf = \OwnPay\Security\SecurityHelpers::csrfToken();
            $tpl = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
            $queryAmt = $req->query('amount', '');
            $error = ($queryAmt !== '' && is_numeric($queryAmt) && bccomp($queryAmt, '0', 2) > 0)
                ? 'Amount is out of valid bounds.'
                : null;
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'csrf_token' => $csrf,
                'error'      => $error,
            ]));
        }

        // Check for an existing active transaction session key to avoid duplicate ledger allocations.
        $sessionKey = 'pay_link_txn_' . $link['id'];
        if (!empty($_SESSION[$sessionKey])) {
            $existingTxn = $this->txnRepo->findActiveForCheckout($_SESSION[$sessionKey]);
            if ($existingTxn) {
                return Response::redirect("/checkout/{$existingTxn['trx_id']}");
            }
            // Clear stale or completed session references to allow a fresh transaction session.
            unset($_SESSION[$sessionKey]);
        }

        $trxId = $this->txnRepo->generateTrxId();
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

        // Save transaction reference within the session store to enable deduplication upon page reload.
        $_SESSION[$sessionKey] = $trxId;

        // Note: Link usage counter is only incremented during final payment capture events.

        return Response::redirect("/checkout/{$trxId}");
    }

    /**
     * Handles variable amount form submission for a payment link.
     *
     * Validates the entered amount using high-precision BCMath against the configured minimum and
     * maximum bounds. If validation passes, a new pending transaction is created (using session-based
     * deduplication to prevent double-clicks) and redirects the user to checkout.
     *
     * @param \OwnPay\Http\Request $req The incoming HTTP request.
     * @return \OwnPay\Http\Response The HTTP response.
     * @throws \Exception If transaction token creation fails.
     */
    public function submit(Request $req): Response
    {
        $slug = (string) $req->param('slug');
        $link = $this->linkRepo->findActiveBySlug($slug);
        $twig = $this->c->get(\Twig\Environment::class);

        if (!$link) {
            return $this->renderExpired($twig);
        }

        $amountStr = (string) $req->post('amount', '0');
        if (!is_numeric($amountStr)) $amountStr = '0';
        $csrf = \OwnPay\Security\SecurityHelpers::csrfToken();

        // Perform basic sanity check using high-precision BCMath comparison (must be greater than zero).
        if (bccomp($amountStr, '0', 2) <= 0) {
            $tpl = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'error'      => 'Please enter a valid amount',
                'csrf_token' => $csrf,
            ]));
        }

        // Enforce configured minimum and maximum boundary constraints utilizing BCMath.
        $minAmount = (string) ($link['min_amount'] ?? '0');
        $maxAmount = (string) ($link['max_amount'] ?? '0');

        if (bccomp($minAmount, '0', 2) > 0 && bccomp($amountStr, $minAmount, 2) < 0) {
            $tpl = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
            $currency = $link['currency'] ?? 'BDT';
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'error'      => "Minimum amount is {$minAmount} {$currency}",
                'csrf_token' => $csrf,
            ]));
        }
        if (bccomp($maxAmount, '0', 2) > 0 && bccomp($amountStr, $maxAmount, 2) > 0) {
            $tpl = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
            $currency = $link['currency'] ?? 'BDT';
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'error'      => "Maximum amount is {$maxAmount} {$currency}",
                'csrf_token' => $csrf,
            ]));
        }

        // Prevent double-submission: reuse existing pending transaction reference registered in current session.
        $sessionKey = 'pay_link_txn_' . $link['id'];
        if (!empty($_SESSION[$sessionKey])) {
            $existingTxn = $this->txnRepo->findActiveForCheckout($_SESSION[$sessionKey]);
            if ($existingTxn) {
                return Response::redirect("/checkout/{$existingTxn['trx_id']}");
            }
            unset($_SESSION[$sessionKey]);
        }

        $trxId = $this->txnRepo->generateTrxId();
        $uuid  = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $this->txnRepo->create([
            'uuid'         => $uuid,
            'trx_id'       => $trxId,
            'merchant_id'  => $link['merchant_id'],
            'gateway_slug' => 'link',
            'amount'       => $amountStr,
            'net_amount'   => $amountStr,
            'currency'     => $link['currency'] ?? 'BDT',
            'method'       => 'link',
            'status'       => 'pending',
            'metadata'     => json_encode(['payment_link_id' => $link['id']]),
        ]);

        $_SESSION[$sessionKey] = $trxId;

        return Response::redirect("/checkout/{$trxId}");
    }

    /**
     * Renders the expired or invalid payment link status page.
     *
     * @param \Twig\Environment $twig The Twig template engine environment.
     * @return \OwnPay\Http\Response The HTML response.
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
