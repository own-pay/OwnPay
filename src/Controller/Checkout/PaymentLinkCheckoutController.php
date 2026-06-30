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
        if (!$twig instanceof \Twig\Environment) {
            throw new \RuntimeException("Twig Environment not found");
        }

        if (!$link) {
            return $this->renderExpired($twig);
        }

        $merchantIdVal = $link['merchant_id'] ?? 0;
        $merchantId = (is_int($merchantIdVal) || is_string($merchantIdVal)) ? (int) $merchantIdVal : 0;
        $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
            $brandCtx->setActiveBrandId($merchantId);
        }

        // Verify usage limits: ensure the link has not exceeded its maximum allowed completions.
        $maxUsesVal = $link['max_uses'] ?? 0;
        $maxUses = (is_int($maxUsesVal) || is_string($maxUsesVal)) ? (int) $maxUsesVal : 0;
        $useCountVal = $link['use_count'] ?? 0;
        $useCount = (is_int($useCountVal) || is_string($useCountVal)) ? (int) $useCountVal : 0;
        if ($maxUses > 0 && $useCount >= $maxUses) {
            return $this->renderExpired($twig);
        }

        $expiresAtVal = $link['expires_at'] ?? '';
        $expiresAt = is_string($expiresAtVal) ? $expiresAtVal : '';
        if ($expiresAt && DateHelper::isPast($expiresAt)) {
            return $this->renderExpired($twig);
        }

        $linkAmtVal = $link['amount'] ?? null;
        $queryAmtVal = $req->query('amount', '0');
        $queryAmtStr = is_string($queryAmtVal) ? $queryAmtVal : '0';
        $amount = (is_string($linkAmtVal) || is_int($linkAmtVal) || is_float($linkAmtVal)) ? (string) $linkAmtVal : $queryAmtStr;
        if (!is_numeric($amount)) {
            $amount = '0';
        }

        // Validate query amount: verify that the amount parameter remains within the configured minimum and maximum limits (using high-precision BCMath comparisons).
        if (bccomp($amount, '0', 2) > 0) {
            $minAmountVal = $link['min_amount'] ?? '0';
            $minAmount = (is_string($minAmountVal) || is_int($minAmountVal) || is_float($minAmountVal)) ? (string) $minAmountVal : '0';
            if (!is_numeric($minAmount)) {
                $minAmount = '0';
            }
            $maxAmountVal = $link['max_amount'] ?? '0';
            $maxAmount = (is_string($maxAmountVal) || is_int($maxAmountVal) || is_float($maxAmountVal)) ? (string) $maxAmountVal : '0';
            if (!is_numeric($maxAmount)) {
                $maxAmount = '0';
            }
            if ((bccomp($minAmount, '0', 2) > 0 && bccomp($amount, $minAmount, 2) < 0)
                || (bccomp($maxAmount, '0', 2) > 0 && bccomp($amount, $maxAmount, 2) > 0)) {
                $amount = '0';
            }
        }

        if (bccomp($amount, '0', 2) <= 0) {
            // Retrieve the dynamic CSRF token to secure the form submission.
            $csrf = \OwnPay\Security\SecurityHelpers::csrfToken();
            $tplFilter = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
            $tpl = is_string($tplFilter) ? $tplFilter : 'checkout/payment-link-amount.twig';
            $queryAmt = $req->query('amount', '');
            /** @var numeric-string $queryAmtStr */
            $queryAmtStr = is_numeric($queryAmt) ? (string) $queryAmt : '0';
            $error = ($queryAmt !== '' && is_numeric($queryAmt) && bccomp($queryAmtStr, '0', 2) > 0)
                ? 'Amount is out of valid bounds.'
                : null;
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'csrf_token' => $csrf,
                'error'      => $error,
            ]));
        }

        // Check for an existing active transaction session key to avoid duplicate ledger allocations.
        $linkIdVal = $link['id'] ?? 0;
        $linkId = (is_int($linkIdVal) || is_string($linkIdVal)) ? (int) $linkIdVal : 0;
        $sessionKey = 'pay_link_txn_' . $linkId;
        if (!empty($_SESSION[$sessionKey])) {
            $sessionTrxIdVal = $_SESSION[$sessionKey];
            if (is_string($sessionTrxIdVal)) {
                $existingTxn = $this->txnRepo->findActiveForCheckout($sessionTrxIdVal);
                if (is_array($existingTxn) && isset($existingTxn['trx_id']) && is_string($existingTxn['trx_id'])) {
                    return Response::redirect("/checkout/{$existingTxn['trx_id']}");
                }
            }
            // Clear stale or completed session references to allow a fresh transaction session.
            unset($_SESSION[$sessionKey]);
        }

        $trxId = $this->txnRepo->generateTrxId();
        $uuid  = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $merchantIdVal = $link['merchant_id'] ?? 0;
        $merchantId = (is_int($merchantIdVal) || is_string($merchantIdVal)) ? (int) $merchantIdVal : 0;

        $currencyVal = $link['currency'] ?? 'BDT';
        $currency = is_string($currencyVal) ? $currencyVal : 'BDT';

        $this->txnRepo->create([
            'uuid'         => $uuid,
            'trx_id'       => $trxId,
            'merchant_id'  => $merchantId,
            'gateway_slug' => 'link',
            'amount'       => $amount,
            'net_amount'   => $amount,
            'currency'     => $currency,
            'method'       => 'link',
            'status'       => 'pending',
            'metadata'     => json_encode(['payment_link_id' => $linkId]),
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
        if (!$twig instanceof \Twig\Environment) {
            throw new \RuntimeException("Twig Environment not found");
        }

        if (!$link) {
            return $this->renderExpired($twig);
        }

        $merchantIdVal = $link['merchant_id'] ?? 0;
        $merchantId = (is_int($merchantIdVal) || is_string($merchantIdVal)) ? (int) $merchantIdVal : 0;
        $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
            $brandCtx->setActiveBrandId($merchantId);
        }

        $amountRaw = $req->post('amount', '0');
        $amountStr = is_string($amountRaw) ? $amountRaw : '0';
        if (!is_numeric($amountStr)) {
            $amountStr = '0';
        }
        $csrf = \OwnPay\Security\SecurityHelpers::csrfToken();

        $tplFilter = $this->events->applyFilter('checkout.payment_link.template', 'checkout/payment-link-amount.twig');
        $tpl = is_string($tplFilter) ? $tplFilter : 'checkout/payment-link-amount.twig';

        // Perform basic sanity check using high-precision BCMath comparison (must be greater than zero).
        if (bccomp($amountStr, '0', 2) <= 0) {
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'error'      => 'Please enter a valid amount',
                'csrf_token' => $csrf,
            ]));
        }

        // Enforce configured minimum and maximum boundary constraints utilizing BCMath.
        $minAmountVal = $link['min_amount'] ?? '0';
        $minAmount = (is_string($minAmountVal) || is_int($minAmountVal) || is_float($minAmountVal)) ? (string) $minAmountVal : '0';
        if (!is_numeric($minAmount)) {
            $minAmount = '0';
        }
        $maxAmountVal = $link['max_amount'] ?? '0';
        $maxAmount = (is_string($maxAmountVal) || is_int($maxAmountVal) || is_float($maxAmountVal)) ? (string) $maxAmountVal : '0';
        if (!is_numeric($maxAmount)) {
            $maxAmount = '0';
        }

        $currencyVal = $link['currency'] ?? 'BDT';
        $currency = is_string($currencyVal) ? $currencyVal : 'BDT';

        if (bccomp($minAmount, '0', 2) > 0 && bccomp($amountStr, $minAmount, 2) < 0) {
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'error'      => "Minimum amount is {$minAmount} {$currency}",
                'csrf_token' => $csrf,
            ]));
        }
        if (bccomp($maxAmount, '0', 2) > 0 && bccomp($amountStr, $maxAmount, 2) > 0) {
            return Response::html($twig->render($tpl, [
                'link'       => $link,
                'error'      => "Maximum amount is {$maxAmount} {$currency}",
                'csrf_token' => $csrf,
            ]));
        }

        // Prevent double-submission: reuse existing pending transaction reference registered in current session.
        $linkIdVal = $link['id'] ?? 0;
        $linkId = (is_int($linkIdVal) || is_string($linkIdVal)) ? (int) $linkIdVal : 0;
        $sessionKey = 'pay_link_txn_' . $linkId;
        if (!empty($_SESSION[$sessionKey])) {
            $sessionTrxIdVal = $_SESSION[$sessionKey];
            if (is_string($sessionTrxIdVal)) {
                $existingTxn = $this->txnRepo->findActiveForCheckout($sessionTrxIdVal);
                if (is_array($existingTxn) && isset($existingTxn['trx_id']) && is_string($existingTxn['trx_id'])) {
                    return Response::redirect("/checkout/{$existingTxn['trx_id']}");
                }
            }
            unset($_SESSION[$sessionKey]);
        }

        $trxId = $this->txnRepo->generateTrxId();
        $uuid  = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $merchantIdVal = $link['merchant_id'] ?? 0;
        $merchantId = (is_int($merchantIdVal) || is_string($merchantIdVal)) ? (int) $merchantIdVal : 0;

        $this->txnRepo->create([
            'uuid'         => $uuid,
            'trx_id'       => $trxId,
            'merchant_id'  => $merchantId,
            'gateway_slug' => 'link',
            'amount'       => $amountStr,
            'net_amount'   => $amountStr,
            'currency'     => $currency,
            'method'       => 'link',
            'status'       => 'pending',
            'metadata'     => json_encode(['payment_link_id' => $linkId]),
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
        $tplFilter = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
        $tpl = is_string($tplFilter) ? $tplFilter : 'checkout/checkout-status.twig';
        return Response::html($twig->render($tpl, [
            'status'       => 'expired',
            'status_label' => 'Payment Link Expired',
            'txn'          => [],
            'brand'        => ['name' => 'OwnPay', 'logo' => '', 'color' => '#0D9488', 'support_email' => ''],
            'lang'         => [],
        ]));
    }
}
