<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\ManualGatewayRepository;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Support\DateHelper;

/**
 * Checkout controller — renders checkout page, handles gateway selection.
 * Fires: checkout.before, checkout.render, checkout.gateway.selected
 */
final class CheckoutController
{
    private Container $c;
    private EventManager $events;
    private TransactionRepository $txnRepo;
    private ManualGatewayRepository $manualGw;
    private GatewayConfigRepository $apiGw;
    private MerchantRepository $merchants;
    private SettingsRepository $settings;

    public function __construct(
        Container $c,
        EventManager $events,
        TransactionRepository $txnRepo,
        ManualGatewayRepository $manualGw,
        GatewayConfigRepository $apiGw,
        MerchantRepository $merchants,
        SettingsRepository $settings
    ) {
        $this->c = $c;
        $this->events = $events;
        $this->txnRepo = $txnRepo;
        $this->manualGw = $manualGw;
        $this->apiGw = $apiGw;
        $this->merchants = $merchants;
        $this->settings = $settings;
    }

    /**
     * GET /checkout/{ref}
     */
    public function show(Request $req): Response
    {
        $ref = (string) $req->param('token');
        $txn = $this->txnRepo->findActiveForCheckout($ref);

        if (!$txn) {
            return $this->renderStatus($ref, 'expired');
        }

        // H-06 FIX: Enforce payment intent expiry — reject expired sessions.
        if (!empty($txn['expires_at']) && DateHelper::isPast($txn['expires_at'])) {
            return $this->renderStatus($ref, 'expired');
        }

        // CHK-005 FIX: Check if associated payment link is still active
        $meta = json_decode($txn['metadata'] ?? '{}', true);
        $linkId = $meta['payment_link_id'] ?? null;
        if ($linkId !== null) {
            $linkRepo = $this->c->get(\OwnPay\Repository\PaymentLinkRepository::class);
            $link = $linkRepo->forTenant((int) $txn['merchant_id'])->findScoped((int) $linkId);
            if (!$link || $link['status'] !== 'active'
                || (!empty($link['expires_at']) && DateHelper::isPast($link['expires_at']))) {
                $this->txnRepo->cancelByTrxId($txn['trx_id']);
                return $this->renderStatus($ref, 'expired');
            }
        }

        $mid = (int) $txn['merchant_id'];

        // L-02 FIX: Resolve currency symbol from DB — not hardcoded ৳
        if ($this->c->has(\OwnPay\Service\Payment\CurrencyService::class)) {
            $currSvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
            $txn['currency_symbol'] = $currSvc->getSymbol($txn['currency'] ?? 'BDT');
        }

        $this->events->doAction('checkout.before', $txn);

        // Load gateways via repos
        $manualGateways = $this->manualGw->forTenant($mid)->listActive();
        $apiGateways = $this->apiGw->forTenant($mid)->listActiveForCheckout();

        // Build category + icon + color maps from plugin manifests
        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
        $manifests = $loader->discover();
        $categoryMap = [];
        $manifestMeta = [];
        foreach ($manifests as $m) {
            $categoryMap[$m->slug] = $m->category;
            $manifestMeta[$m->slug] = [
                'color' => $m->color,
                'icon'  => $m->icon,
            ];
        }

        // Categorize into checkout tabs: mfs | bank | global (cards)
        // CK-01/05 FIX: Remap logo_path→logo so template can find it
        $gateways = ['mfs' => [], 'bank' => [], 'global' => []];
        foreach ($manualGateways as $gw) {
            $cat = $gw['category'] ?? 'mfs';
            if (!isset($gateways[$cat])) $cat = 'mfs';
            $gw['logo'] = $gw['logo_path'] ?? '';
            $gw['color'] = json_decode($gw['colors'] ?? '{}', true)['primary'] ?? '#0D9488';
            $gateways[$cat][] = array_merge($gw, ['mode' => 'manual']);
        }
        foreach ($apiGateways as $gw) {
            $cat = $categoryMap[$gw['slug'] ?? ''] ?? 'global';
            if (!isset($gateways[$cat])) $cat = 'global';
            $gw['logo'] = $gw['logo_path'] ?? '';
            $gw['color'] = $manifestMeta[$gw['slug']]['color'] ?? '#0D9488';
            $gateways[$cat][] = array_merge($gw, ['mode' => 'api']);
        }

        // Brand + settings
        $brand = $this->loadBrand($mid);
        $faqs = json_decode($this->settings->get('general', 'faqs', '[]'), true);

        // Invoice items — H-01 FIX: invoice_id is in metadata JSON, not a column
        $items = [];
        $invoiceId = $meta['invoice_id'] ?? null;
        if ($invoiceId) {
            $invoiceRepo = $this->c->get(\OwnPay\Repository\InvoiceRepository::class);
            $items = $invoiceRepo->listItems((int) $invoiceId);
        }

        // M-05 FIX: HMAC integrity hash binds amount+currency+token to prevent relay attacks.
        // H-05 FIX: Use $_ENV fallback chain — getenv() may not read phpdotenv vars.
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: 'fallback-key');
        $checkoutHash = hash_hmac('sha256', $txn['amount'] . '|' . $txn['currency'] . '|' . $ref, $hmacKey);

        // Build manual gateway details map for JS popup (C-5: no separate API endpoint needed)
        $manualDetails = [];
        foreach ($manualGateways as $gw) {
            $slug = $gw['slug'] ?? $gw['name'] ?? '';
            $inputFields = json_decode($gw['input_fields'] ?? '[]', true) ?: [];
            $instructionsObj = json_decode($gw['instructions'] ?? '[]', true) ?: [];
            if (is_array($instructionsObj) && isset($instructionsObj['steps'])) {
                $instructions = $instructionsObj['steps'];
            } elseif (is_array($instructionsObj)) {
                $instructions = $instructionsObj;
            } else {
                $instructions = [$instructionsObj];
            }

            // CK-07: Extract payment number from input_fields for JS popup convenience
            $paymentNumber = '';
            foreach ($inputFields as $field) {
                if (($field['type'] ?? '') === 'payment_number' || ($field['name'] ?? '') === 'payment_number') {
                    $paymentNumber = $field['value'] ?? $field['default'] ?? '';
                    break;
                }
            }

            $manualDetails[$slug] = [
                'name'           => $gw['name'] ?? '',
                'input_fields'   => $inputFields,
                'instructions'   => $instructions,
                'colors'         => json_decode($gw['colors'] ?? '{}', true) ?: [],
                'payment_number' => $paymentNumber,
            ];
        }

        $data = [
            'txn'             => $txn,
            'gateways'        => $gateways,
            'brand'           => $brand,
            'items'           => $items,
            'faqs'            => $faqs,
            'show_faq'        => $this->settings->get('checkout', 'show_faq', '1') === '1',
            'config'          => $this->buildJsConfig($txn, $brand, $manifests),
            'checkout_hash'   => $checkoutHash,
            // M-03 FIX: JSON_HEX_TAG prevents XSS via </script> in gateway names
            'manual_gateways' => json_encode($manualDetails, JSON_HEX_TAG | JSON_HEX_AMP),
        ];

        $data = $this->events->applyFilter('checkout.render', $data);

        $tplName = $this->events->applyFilter('checkout.template', 'checkout/checkout.twig');
        $twig = $this->c->get(\Twig\Environment::class);
        return Response::html($twig->render($tplName, $data));
    }

    private function renderStatus(string $ref, string $status): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $txn = $this->txnRepo->findAnyByTrxId($ref);
        $mid = (int) ($txn['merchant_id'] ?? 0);
        $brand = $mid > 0 ? $this->loadBrand($mid) : ['name' => 'Own Pay', 'logo' => '', 'color' => '#0D9488', 'support_email' => ''];

        // CK-08/11 FIX: Pass brand + human-readable status labels + custom messages
        $statusLabels = [
            'success' => 'Payment Successful', 'completed' => 'Payment Successful',
            'failed' => 'Payment Failed', 'cancelled' => 'Payment Cancelled',
            'canceled' => 'Payment Cancelled', 'expired' => 'Payment Expired',
            'pending' => 'Payment Pending', 'pending_review' => 'Payment Under Review',
            'awaiting_verification' => 'Awaiting Verification',
            'processing' => 'Payment Processing',
        ];

        // L-02 FIX: Resolve currency symbol for status pages
        if ($txn && $this->c->has(\OwnPay\Service\Payment\CurrencyService::class)) {
            $currSvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
            $txn['currency_symbol'] = $currSvc->getSymbol($txn['currency'] ?? 'BDT');
        }

        $tplName = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
        return Response::html($twig->render($tplName, [
            'txn'          => $txn ?? ['trx_id' => $ref],
            'status'       => $status ?: ($txn['status'] ?? 'expired'),
            'status_label' => $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)),
            'brand'        => $brand,
            'lang'         => [
                'success_msg' => $this->settings->get('general', 'checkout_success_msg', ''),
                'pending_msg' => $this->settings->get('general', 'checkout_pending_msg', ''),
                'failed_msg'  => $this->settings->get('general', 'checkout_failed_msg', ''),
            ],
        ]));
    }

    private function loadBrand(int $mid): array
    {
        // White-label: Use BrandThemeService for full per-brand theming
        if ($this->c->has(\OwnPay\Service\Brand\BrandThemeService::class)) {
            return $this->c->get(\OwnPay\Service\Brand\BrandThemeService::class)->getBrandTheme($mid);
        }

        // Fallback: basic brand data
        $merchant = $this->merchants->find($mid);
        $s = $this->settings->getGroup('general');
        return [
            'name'          => $merchant['name'] ?? $s['app_name'] ?? 'Own Pay',
            'logo'          => $merchant['logo'] ?? '',
            'color'         => $s['theme_primary'] ?? '#0D9488',
            'support_email' => $s['support_email'] ?? '',
        ];
    }

    private function buildJsConfig(array $txn, array $brand, array $manifests = []): array
    {
        // CK-03 FIX: Read timer from checkout settings instead of hardcoding
        $timerEnabled = $this->settings->get('checkout', 'timer_enabled', '1');
        $timerSeconds = (int) $this->settings->get('checkout', 'timer_seconds', '600');

        // CK-10 FIX: Build gatewayMeta dynamically from manifest data
        $gatewayMeta = [];
        foreach ($manifests as $m) {
            if ($m->type === 'gateway') {
                $gatewayMeta[$m->slug] = [
                    'color'    => $m->color,
                    'type'     => $m->category === 'mfs' ? 'Send Money' : 'Pay Online',
                    'logoText' => mb_strtoupper(mb_substr($m->name, 0, 2)),
                ];
            }
        }

        // Calculate remaining time so timer survives page refresh
        $remaining = $timerSeconds;
        if (!empty($txn['created_at'])) {
            $createdAt = strtotime($txn['created_at']);
            if ($createdAt !== false) {
                $elapsed = time() - $createdAt;
                $remaining = max(0, $timerSeconds - $elapsed);
            }
        }

        return [
            'txnRef'           => $txn['trx_id'],
            'timeoutEnabled'   => $timerEnabled === '1',
            'timeoutSeconds'   => $timerSeconds,
            'timeoutRemaining' => $remaining,
            'gatewayMeta'      => $gatewayMeta,
        ];
    }

    /**
     * POST /checkout/{token}/pay — gateway selection + manual payment submission.
     */
    public function pay(Request $req): Response
    {
        $token = (string) $req->param('token');
        $txn = $this->txnRepo->findActiveForCheckout($token);

        if (!$txn) {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Transaction expired or not found.'], 404);
            }
            return $this->renderStatus($token, 'expired');
        }

        // CHK-011 FIX: State-based double-submit guard — only pending txns can proceed
        if ($txn['status'] !== 'pending') {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
            }
            return $this->renderStatus($token, $txn['status']);
        }

        // CHK-005 FIX: Re-check payment link status at pay time
        $meta = json_decode($txn['metadata'] ?? '{}', true);
        $linkId = $meta['payment_link_id'] ?? null;
        if ($linkId !== null) {
            $linkRepo = $this->c->get(\OwnPay\Repository\PaymentLinkRepository::class);
            $link = $linkRepo->forTenant((int) $txn['merchant_id'])->findScoped((int) $linkId);
            if (!$link || $link['status'] !== 'active'
                || (!empty($link['expires_at']) && DateHelper::isPast($link['expires_at']))) {
                $this->txnRepo->cancelByTrxId($txn['trx_id']);
                if ($req->isAjax()) {
                    return Response::json(['success' => false, 'error' => 'Payment link has expired.'], 410);
                }
                return $this->renderStatus($token, 'expired');
            }
        }

        // M-05 FIX: Verify checkout integrity hash — reject tampered sessions.
        // Support both form POST and JSON body (AJAX via opPost)
        $submittedHash = $req->input('checkout_hash', '');
        // H-05 FIX: Use $_ENV fallback chain
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: 'fallback-key');
        $expectedHash = hash_hmac('sha256', $txn['amount'] . '|' . $txn['currency'] . '|' . $token, $hmacKey);
        if (!hash_equals($expectedHash, $submittedHash)) {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Session expired. Please refresh the page.'], 403);
            }
            return $this->renderStatus($token, 'expired');
        }

        $gateway = $req->input('gateway', '');
        $gatewayMode = $req->input('gateway_mode', 'manual');

        $this->events->doAction('checkout.gateway.selected', $txn, $gateway);

        if ($gatewayMode === 'manual') {
            $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gateway, 'awaiting_verification', (int) $txn['merchant_id']);

            $details = $req->post('payment_details', []);
            if (!empty($details)) {
                $this->txnRepo->updateMetadata((int) $txn['id'], [
                    'payment_details' => $details,
                    'submitted_at'    => DateHelper::now(),
                ], (int) $txn['merchant_id']);
            }
            return Response::redirect("/checkout/{$token}/status");
        }

        // API gateway — call external gateway via GatewayBridge pipeline.
        // CRITICAL: Do NOT update transaction status before the external API responds.
        // Status stays 'pending' until we have a confirmed redirect URL from the gateway.
        //
        // ARCHITECTURE (matching PipraPay reference):
        //   1. Frontend POSTs via fetch/AJAX (not form submit)
        //   2. Controller returns JSON with redirect_url
        //   3. Frontend does window.location.href = redirect_url (hard redirect OUT)
        //   4. User pays on external gateway (Stripe/bKash)
        //   5. Gateway redirects back to /checkout/{token}/status
        //   6. Webhook/IPN updates DB state asynchronously
        if ($this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
            try {
                $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                
                // White-label: Resolve callback URL via brand's custom domain
                $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
                $callbackUrl = $urlService->buildLegacyCallbackUrl((int) $txn['merchant_id'], $token, $req);

                $result = $svc->initiatePayment((int) $txn['merchant_id'], $gateway, [
                    'amount'       => $txn['amount'],
                    'currency'     => $txn['currency'],
                    'trx_id'       => $txn['trx_id'],
                    'redirect_url' => $callbackUrl,
                    'cancel_url'   => $callbackUrl,
                    'existing_txn' => true,
                ]);

                if ($result['success'] && !empty($result['redirect_url'])) {
                    // External gateway returned a valid redirect URL — NOW we transition state.
                    // This is the only safe point to update: the external handshake succeeded.
                    $this->txnRepo->setGatewayAndStatus(
                        (int) $txn['id'], $gateway, 'processing', (int) $txn['merchant_id']
                    );

                    // AJAX request: return JSON so frontend does window.location.href
                    if ($req->isAjax()) {
                        return Response::json([
                            'success'      => true,
                            'redirect_url' => $result['redirect_url'],
                        ]);
                    }
                    // Non-AJAX fallback: 302 redirect
                    return Response::redirect($result['redirect_url']);
                }

                // Gateway returned success=false or no redirect URL
                $errorMsg = $result['error'] ?? 'Gateway returned no redirect URL';
                $this->c->get(\OwnPay\Service\System\Logger::class)->warning(
                    "Gateway {$gateway} failed for trx {$txn['trx_id']}: {$errorMsg}"
                );

                if ($req->isAjax()) {
                    return Response::json([
                        'success' => false,
                        'error'   => $errorMsg,
                    ], 422);
                }
            } catch (\Throwable $e) {
                // Gateway bridge threw (adapter not found, API error, etc.)
                // Transaction stays 'pending' — user can retry with a different gateway.
                $this->c->get(\OwnPay\Service\System\Logger::class)->error(
                    "Gateway {$gateway} initiation failed: " . $e->getMessage()
                );

                if ($req->isAjax()) {
                    return Response::json([
                        'success' => false,
                        'error'   => 'Payment gateway error: ' . $e->getMessage(),
                    ], 422);
                }
            }
        } else {
            if ($req->isAjax()) {
                return Response::json([
                    'success' => false,
                    'error'   => 'Payment service is not configured.',
                ], 500);
            }
        }

        // Non-AJAX fallback: redirect to status page. Transaction is still 'pending'.
        return Response::redirect("/checkout/{$token}/status");
    }

    /**
     * POST /checkout/{token}/cancel
     *
     * H-03 FIX: Require checkout_hash to prevent unauthenticated cancellation.
     */
    public function cancel(Request $req): Response
    {
        $token = (string) $req->param('token');
        $txn = $this->txnRepo->findActiveForCheckout($token);

        if (!$txn) {
            return $this->renderStatus($token, 'cancelled');
        }

        // H-03 FIX: Verify HMAC hash — prevent anyone with trx_id from cancelling
        $submittedHash = $req->input('checkout_hash', '');
        if ($submittedHash) {
            $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: 'fallback-key');
            $expectedHash = hash_hmac('sha256', $txn['amount'] . '|' . $txn['currency'] . '|' . $token, $hmacKey);
            if (!hash_equals($expectedHash, $submittedHash)) {
                return $this->renderStatus($token, 'expired');
            }
        }

        $this->txnRepo->cancelByTrxId($token);
        $this->events->doAction('checkout.cancelled', $token);
        return $this->renderStatus($token, 'cancelled');
    }

    /**
     * GET /checkout/{token}/status
     *
     * Also handles gateway callbacks — bKash redirects here with paymentID + status
     * query params after customer pays. We execute the payment capture on first visit.
     */
    public function status(Request $req): Response
    {
        $token = (string) $req->param('token');
        $txn = $this->txnRepo->findAnyByTrxId($token);
        $status = $txn['status'] ?? 'expired';

        // Gateway callback handling — execute payment when bKash/SSLCommerz redirects back.
        $callbackPaymentId = $req->query('paymentID') ?? $req->query('payment_id') ?? '';
        $callbackStatus = $req->query('status') ?? '';

        if ($callbackPaymentId !== '' && $txn && $txn['status'] === 'processing') {
            if ($this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
                try {
                    $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                    $mid = (int) $txn['merchant_id'];
                    $gateway = $txn['gateway_slug'] ?? '';
                    $callbackData = array_merge($req->query() ?? [], [
                        'paymentID' => $callbackPaymentId,
                        'trx_id'    => $txn['trx_id'],
                    ]);

                    if ($gateway !== '') {
                        $result = $svc->handleCallback($mid, $gateway, $callbackData);
                        if ($result['success'] ?? false) {
                            $status = 'completed';
                        } elseif (in_array($callbackStatus, ['cancel', 'failure', 'failed'], true)) {
                            $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gateway, 'failed', $mid);
                            $status = 'failed';
                        }
                    }
                } catch (\Throwable $e) {
                    if ($this->c->has(\OwnPay\Service\System\Logger::class)) {
                        $this->c->get(\OwnPay\Service\System\Logger::class)->error(
                            "Gateway callback execution failed for {$token}: " . $e->getMessage()
                        );
                    }
                }
            }
        }

        return $this->renderStatus($token, $status);
    }

    /**
     * POST /checkout/{token}/manual-verify — customer submits manual payment proof.
     */
    public function manualVerify(Request $req): Response
    {
        $token = (string) $req->param('token');
        $txn = $this->txnRepo->findAwaitingVerification($token);

        if (!$txn) {
            return $this->renderStatus($token, 'expired');
        }

        $verifyData = [
            'sender_number'  => $req->input('sender_number', ''),
            'transaction_id' => $req->input('transaction_id', ''),
            'submitted_at'   => DateHelper::now(),
        ];

        $existingMeta = json_decode($txn['metadata'] ?? '{}', true);
        $existingMeta['verification'] = $verifyData;

        $this->txnRepo->setStatusWithMeta((int) $txn['id'], 'pending_review', $existingMeta, (int) $txn['merchant_id']);

        $this->events->doAction('checkout.manual_verify.submitted', $txn, $verifyData);

        return $this->renderStatus($token, 'pending_review');
    }
}
