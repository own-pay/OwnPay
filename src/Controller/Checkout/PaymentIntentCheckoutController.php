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
use OwnPay\Repository\PaymentIntentRepository;
use OwnPay\Service\Payment\PaymentService;
use OwnPay\Service\Payment\CurrencyService;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Support\DateHelper;

/**
 * Payment Intent Checkout Controller
 * Manages the checkout flow, currency conversions, external gateway handshakes,
 * and 5-second countdown redirects.
 */
final class PaymentIntentCheckoutController
{
    private Container $c;
    private EventManager $events;
    private TransactionRepository $txnRepo;
    private ManualGatewayRepository $manualGw;
    private GatewayConfigRepository $apiGw;
    private MerchantRepository $merchants;
    private SettingsRepository $settings;
    private PaymentIntentRepository $intents;
    private PaymentService $paymentService;
    private CurrencyService $currencyService;
    private TransactionService $transactionService;
    private \OwnPay\Core\Database $db;

    public function __construct(
        Container $c,
        EventManager $events,
        TransactionRepository $txnRepo,
        ManualGatewayRepository $manualGw,
        GatewayConfigRepository $apiGw,
        MerchantRepository $merchants,
        SettingsRepository $settings,
        PaymentIntentRepository $intents,
        PaymentService $paymentService,
        CurrencyService $currencyService,
        TransactionService $transactionService,
        \OwnPay\Core\Database $db
    ) {
        $this->c = $c;
        $this->events = $events;
        $this->txnRepo = $txnRepo;
        $this->manualGw = $manualGw;
        $this->apiGw = $apiGw;
        $this->merchants = $merchants;
        $this->settings = $settings;
        $this->intents = $intents;
        $this->paymentService = $paymentService;
        $this->currencyService = $currencyService;
        $this->transactionService = $transactionService;
        $this->db = $db;
    }

    /**
     * GET /checkout/intent/{token}
     */
    public function show(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return $this->renderStatus($token, 'expired');
        }

        if ($intent['status'] !== 'pending') {
            return $this->renderStatus($token, $intent['status'], $intent);
        }

        $mid = (int) $intent['merchant_id'];

        // Get active gateways
        $manualGateways = $this->manualGw->forTenant($mid)->listActive();
        $apiGateways = $this->apiGw->forTenant($mid)->listActiveForCheckout();

        // Resolve symbol for original currency
        $intent['currency_symbol'] = $this->currencyService->getSymbol($intent['currency']);

        // Discover theme/manifest details
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

        $gateways = ['mfs' => [], 'bank' => [], 'global' => []];

        // Helper to check and convert currency
        $checkAndConvert = function(array $gw, string $slug) use ($intent): array {
            $supportedCurrencies = ['BDT'];
            if ($slug === 'bkash-api' || $slug === 'nagad' || $slug === 'rocket' || $slug === 'sslcommerz') {
                $supportedCurrencies = ['BDT'];
            } else {
                return $gw;
            }

            if ($intent['currency'] !== 'BDT') {
                try {
                    $converted = $this->currencyService->convert(
                        (string) $intent['amount'],
                        $intent['currency'],
                        'BDT'
                    );
                    $gw['converted_amount'] = $converted;
                    $gw['converted_currency'] = 'BDT';
                    $gw['converted_symbol'] = $this->currencyService->getSymbol('BDT');
                } catch (\Throwable $e) {
                    $gw['conversion_error'] = true;
                }
            }
            return $gw;
        };

        foreach ($manualGateways as $gw) {
            $cat = $gw['category'] ?? 'mfs';
            if (!isset($gateways[$cat])) $cat = 'mfs';
            $gw['logo'] = $gw['logo_path'] ?? '';
            $gw['color'] = json_decode($gw['colors'] ?? '{}', true)['primary'] ?? '#0D9488';
            $gw = array_merge($gw, ['mode' => 'manual']);
            
            // For manual MFS gateways, convert currency if they process BDT
            $gwSlugLower = strtolower($gw['slug'] ?? $gw['name'] ?? '');
            if (str_contains($gwSlugLower, 'bkash') || str_contains($gwSlugLower, 'nagad') || str_contains($gwSlugLower, 'rocket') || str_contains($gwSlugLower, 'upay')) {
                $gw = $checkAndConvert($gw, 'bkash-api');
            }

            $gateways[$cat][] = $gw;
        }

        foreach ($apiGateways as $gw) {
            $slug = $gw['slug'] ?? '';
            $cat = $categoryMap[$slug] ?? 'global';
            if (!isset($gateways[$cat])) $cat = 'global';
            $gw['logo'] = $gw['logo_path'] ?? '';
            $gw['color'] = $manifestMeta[$slug]['color'] ?? '#0D9488';
            $gw = array_merge($gw, ['mode' => 'api']);
            
            // Check cross-currency translation for regional API gateways
            $gw = $checkAndConvert($gw, $slug);

            $gateways[$cat][] = $gw;
        }

        $brand = $this->loadBrand($mid);
        $faqs = json_decode($this->settings->get('general', 'faqs', '[]'), true);

        // Generate HMAC hash binding amount+currency+token
        // BUG-010 FIX: No static fallback key — throw if unconfigured
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $checkoutHash = hash_hmac('sha256', $intent['amount'] . '|' . $intent['currency'] . '|' . $token, $hmacKey);

        // Build manual details map for JS popup
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

            $paymentNumber = '';
            foreach ($inputFields as $field) {
                if (($field['type'] ?? '') === 'payment_number' || ($field['name'] ?? '') === 'payment_number') {
                    $paymentNumber = $field['value'] ?? $field['default'] ?? '';
                    break;
                }
            }

            // Calculate converted BDT value for JS manual payment prompt popup
            $gwSlugLower = strtolower($gw['slug'] ?? $gw['name'] ?? '');
            $convAmount = null;
            $convCurrency = null;
            if (str_contains($gwSlugLower, 'bkash') || str_contains($gwSlugLower, 'nagad') || str_contains($gwSlugLower, 'rocket') || str_contains($gwSlugLower, 'upay')) {
                if ($intent['currency'] !== 'BDT') {
                    try {
                        $convAmount = $this->currencyService->convert((string) $intent['amount'], $intent['currency'], 'BDT');
                        $convCurrency = 'BDT';
                    } catch (\Throwable) {}
                }
            }

            $manualDetails[$slug] = [
                'name'               => $gw['name'] ?? '',
                'input_fields'       => $inputFields,
                'instructions'       => $instructions,
                'colors'             => json_decode($gw['colors'] ?? '{}', true) ?: [],
                'payment_number'     => $paymentNumber,
                'converted_amount'   => $convAmount,
                'converted_currency' => $convCurrency,
            ];
        }

        // JS Timer setup
        $timerEnabled = $this->settings->get('checkout', 'timer_enabled', '1');
        $timerSeconds = (int) $this->settings->get('checkout', 'timer_seconds', '600');
        $remaining = $timerSeconds;
        if (!empty($intent['created_at'])) {
            $createdAt = strtotime($intent['created_at']);
            if ($createdAt !== false) {
                $elapsed = time() - $createdAt;
                $remaining = max(0, $timerSeconds - $elapsed);
            }
        }

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

        $jsConfig = [
            'txnRef'                => $intent['token'],
            'checkoutBasePath'      => '/checkout/intent/' . $intent['token'],
            'originalAmount'        => $intent['amount'],
            'originalCurrency'      => $intent['currency'],
            'originalCurrencySymbol'=> $intent['currency_symbol'],
            'timeoutEnabled'        => $timerEnabled === '1',
            'timeoutSeconds'        => $timerSeconds,
            'timeoutRemaining'      => $remaining,
            'gatewayMeta'           => $gatewayMeta,
        ];

        // Format items from metadata
        $items = [];
        $metaObj = json_decode($intent['metadata'] ?? '{}', true) ?: [];
        if (isset($metaObj['items'])) {
            $items = $metaObj['items'];
        }

        // Mock a transaction record so templates render seamlessly
        $txnMock = [
            'trx_id'            => $intent['token'],
            'amount'            => $intent['amount'],
            'currency'          => $intent['currency'],
            'currency_symbol'   => $intent['currency_symbol'],
            'customer_name'     => $metaObj['customer_name'] ?? null,
            'customer_email'    => $metaObj['customer_email'] ?? null,
            'customer_phone'    => $metaObj['customer_phone'] ?? null,
            'reference'         => $intent['description'] ?? $metaObj['reference'] ?? null,
            'merchant_id'       => $mid,
        ];

        $data = [
            'txn'             => $txnMock,
            'intent'          => $intent,
            'gateways'        => $gateways,
            'brand'           => $brand,
            'items'           => $items,
            'faqs'            => $faqs,
            'show_faq'        => $this->settings->get('checkout', 'show_faq', '1') === '1',
            'config'          => $jsConfig,
            'checkout_hash'   => $checkoutHash,
            'manual_gateways' => json_encode($manualDetails, JSON_HEX_TAG | JSON_HEX_AMP),
            'is_intent'       => true,
        ];

        $data = $this->events->applyFilter('checkout.intent.render', $data);

        $twig = $this->c->get(\Twig\Environment::class);
        return Response::html($twig->render('checkout/checkout.twig', $data));
    }

    /**
     * POST /checkout/intent/{token}/pay
     */
    public function pay(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Transaction expired or not found.'], 404);
            }
            return $this->renderStatus($token, 'expired');
        }

        if ($intent['status'] !== 'pending') {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
            }
            return $this->renderStatus($token, $intent['status'], $intent);
        }

        // Verify HMAC integrity hash
        $submittedHash = $req->input('checkout_hash', '');
        // BUG-010 FIX: No static fallback key — throw if unconfigured
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $expectedHash = hash_hmac('sha256', $intent['amount'] . '|' . $intent['currency'] . '|' . $token, $hmacKey);
        if (!hash_equals($expectedHash, $submittedHash)) {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Session expired. Please refresh the page.'], 403);
            }
            return $this->renderStatus($token, 'expired');
        }

        $gateway = $req->input('gateway', '');
        $gatewayMode = $req->input('gateway_mode', 'manual');
        $mid = (int) $intent['merchant_id'];

        $amount = $intent['amount'];
        $currency = $intent['currency'];

        // Perform Cross-Currency Conversion if selected gateway is regional/BDT-only
        if ($gateway === 'bkash-api' || $gateway === 'nagad' || $gateway === 'rocket' || $gateway === 'sslcommerz' || str_contains(strtolower($gateway), 'bkash') || str_contains(strtolower($gateway), 'nagad')) {
            if ($currency !== 'BDT') {
                try {
                    $amount = $this->currencyService->convert((string) $intent['amount'], $currency, 'BDT');
                    $currency = 'BDT';
                } catch (\Throwable $e) {
                    if ($req->isAjax()) {
                        return Response::json(['success' => false, 'error' => 'Exchange rate translation error.'], 422);
                    }
                    return $this->renderStatus($token, 'failed');
                }
            }
        }

        // Create transaction linked to the Payment Intent
        $txnData = [
            'merchant_id'       => $mid,
            'payment_intent_id' => $intent['id'],
            'customer_id'       => $intent['customer_id'],
            'gateway_slug'      => $gateway,
            'amount'            => $amount,
            'currency'          => $currency,
            'method'            => $gatewayMode === 'api' ? 'api' : 'manual',
            'status'            => 'pending',
            'reference'         => $intent['description'] ?? null,
            'metadata'          => !empty($intent['metadata']) ? $intent['metadata'] : '{}',
        ];

        try {
            $txn = $this->transactionService->create($mid, $txnData);
        } catch (\Throwable $e) {
            // BUG-016 FIX: Log real error, return generic message to client
            $this->c->get(\OwnPay\Service\System\Logger::class)->error('Transaction creation failed: ' . $e->getMessage());
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Payment processing failed. Please try again.'], 500);
            }
            return $this->renderStatus($token, 'failed');
        }

        if ($gatewayMode === 'manual') {
            $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gateway, 'awaiting_verification', $mid);
            $details = $req->post('payment_details', []);
            if (!empty($details)) {
                $metaObj = json_decode($intent['metadata'] ?? '{}', true) ?: [];
                $metaObj['payment_details'] = $details;
                $metaObj['submitted_at'] = DateHelper::now();
                $this->txnRepo->updateMetadata((int) $txn['id'], $metaObj, $mid);
            }

            // Update intent to processing
            $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'processing']);

            if ($req->isAjax()) {
                return Response::json([
                    'success'      => true,
                    'redirect_url' => "/checkout/intent/{$token}/status",
                ]);
            }
            return Response::redirect("/checkout/intent/{$token}/status");
        }

        // API gateway — initiate handshake redirect
        if ($this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
            try {
                $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                
                // White-label: Resolve callback URL via brand's custom domain
                $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
                $callbackUrl = $urlService->buildCallbackUrl($mid, $token, $req);

                // Currency exchange: convert if gateway requires different currency
                $payAmount = $txn['amount'];
                $payCurrency = $txn['currency'];
                if ($this->c->has(\OwnPay\Gateway\GatewayBridge::class)) {
                    $bridge = $this->c->get(\OwnPay\Gateway\GatewayBridge::class);
                    $supported = $bridge->getSupportedCurrencies($gateway);
                    if (!empty($supported) && !in_array($payCurrency, $supported, true)) {
                        // Gateway doesn't accept this currency — convert to gateway's primary currency
                        $targetCurrency = $supported[0]; // First = preferred
                        if ($this->c->has(\OwnPay\Service\Payment\CurrencyService::class)) {
                            $currSvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
                            $converted = $currSvc->convert($payAmount, $payCurrency, $targetCurrency);
                            if ($converted !== '0') {
                                // Store original amount in metadata for audit
                                $existingMeta = json_decode($txn['metadata'] ?? '{}', true) ?: [];
                                $existingMeta['original_amount'] = $payAmount;
                                $existingMeta['original_currency'] = $payCurrency;
                                $existingMeta['exchange_rate'] = $currSvc->getRate($targetCurrency);
                                $existingMeta['converted_amount'] = $converted;
                                $existingMeta['converted_currency'] = $targetCurrency;
                                $this->db->execute(
                                    "UPDATE op_transactions SET metadata = :meta WHERE id = :id AND merchant_id = :mid",
                                    ['meta' => json_encode($existingMeta), 'id' => $txn['id'], 'mid' => $mid]
                                );
                                $payAmount = $converted;
                                $payCurrency = $targetCurrency;
                            }
                        }
                    }
                }

                $result = $svc->initiatePayment($mid, $gateway, [
                    'amount'       => $payAmount,
                    'currency'     => $payCurrency,
                    'trx_id'       => $txn['trx_id'],
                    'redirect_url' => $callbackUrl,
                    'cancel_url'   => $callbackUrl,
                    'existing_txn' => true,
                ]);

                if ($result['success'] && !empty($result['redirect_url'])) {
                    // Update Transaction Status to processing (Intent stays pending/initiated)
                    $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gateway, 'processing', $mid);
                    $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'processing']);

                    if ($req->isAjax()) {
                        return Response::json([
                            'success'      => true,
                            'redirect_url' => $result['redirect_url'],
                        ]);
                    }
                    return Response::redirect($result['redirect_url']);
                }

                $errorMsg = $result['error'] ?? 'Gateway returned no redirect URL';
                if ($req->isAjax()) {
                    return Response::json(['success' => false, 'error' => $errorMsg], 422);
                }
            } catch (\Throwable $e) {
                $this->c->get(\OwnPay\Service\System\Logger::class)->error(
                    "Gateway {$gateway} initiation failed: " . $e->getMessage()
                );
                // BUG-016 FIX: Generic error message, real error already logged above
                if ($req->isAjax()) {
                    return Response::json(['success' => false, 'error' => 'Gateway connection error. Please try again.'], 422);
                }
            }
        } else {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Payment service is not configured.'], 500);
            }
        }

        return Response::redirect("/checkout/intent/{$token}/status");
    }

    /**
     * GET /checkout/intent/{token}/status
     *
     * Also handles gateway callbacks — bKash redirects here with paymentID + status
     * query params after customer pays. We execute the payment capture on first visit.
     */
    public function status(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return $this->renderStatus($token, 'expired');
        }

        $mid = (int) $intent['merchant_id'];

        // Gateway callback handling — execute payment when bKash/SSLCommerz redirects back.
        // bKash sends: ?paymentID=xxx&status=success
        // SSLCommerz sends POST to success_url with tran_id, val_id, etc.
        $callbackPaymentId = $req->query('paymentID') ?? $req->query('payment_id') ?? '';
        $callbackStatus = $req->query('status') ?? '';

        if ($callbackPaymentId !== '' && $intent['status'] === 'processing') {
            // Find the linked transaction
            $txn = $this->db->fetchOne(
                "SELECT * FROM op_transactions WHERE payment_intent_id = :pi AND merchant_id = :mid AND status = 'processing' ORDER BY id DESC LIMIT 1",
                ['pi' => $intent['id'], 'mid' => $mid]
            );

            if ($txn && $this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
                // BUG-011 FIX: Atomic idempotency guard — claim the row before processing.
                // If another request already claimed it (0 rows affected), skip callback.
                $claimed = $this->db->update(
                    "UPDATE op_transactions SET status = 'callback_processing' WHERE id = :id AND merchant_id = :mid AND status = 'processing'",
                    ['id' => $txn['id'], 'mid' => $mid]
                );
                if ($claimed === 0) {
                    // Already being processed or completed — skip duplicate callback
                    return $this->renderStatus($token, $intent['status'], $intent);
                }

                try {
                    $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                    $callbackData = array_merge($req->query() ?? [], [
                        'paymentID' => $callbackPaymentId,
                        'trx_id'    => $txn['trx_id'],
                    ]);
                    $gateway = $txn['gateway_slug'] ?? '';

                    if ($gateway !== '') {
                        $result = $svc->handleCallback($mid, $gateway, $callbackData);

                        if ($result['success'] ?? false) {
                            $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'completed']);
                            $intent['status'] = 'completed';
                        } else {
                            // If bKash status=cancel or failure, mark as failed
                            if (in_array($callbackStatus, ['cancel', 'failure', 'failed'], true)) {
                                $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gateway, 'failed', $mid);
                                $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'failed']);
                                $intent['status'] = 'failed';
                            } else {
                                // Restore to processing if callback didn't resolve
                                $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gateway, 'processing', $mid);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Restore to processing on error so it can be retried
                    $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $txn['gateway_slug'] ?? '', 'processing', $mid);
                    // Log but don't crash the status page
                    if ($this->c->has(\OwnPay\Service\System\Logger::class)) {
                        $this->c->get(\OwnPay\Service\System\Logger::class)->error(
                            "Gateway callback execution failed for intent {$token}: " . $e->getMessage()
                        );
                    }
                }
            }
        }

        return $this->renderStatus($token, $intent['status'], $intent);
    }

    /**
     * POST /checkout/intent/{token}/cancel
     */
    public function cancel(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return $this->renderStatus($token, 'cancelled');
        }

        $submittedHash = $req->input('checkout_hash', '');
        if ($submittedHash) {
            // BUG-010 FIX: No static fallback key
            $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
            if ($hmacKey === '') {
                throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
            }
            $expectedHash = hash_hmac('sha256', $intent['amount'] . '|' . $intent['currency'] . '|' . $token, $hmacKey);
            if (!hash_equals($expectedHash, $submittedHash)) {
                return $this->renderStatus($token, 'expired');
            }
        }

        $mid = (int) $intent['merchant_id'];
        $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'cancelled']);

        // Cancel any pending transactions linked to this intent
        $this->db->execute(
            "UPDATE op_transactions SET status = 'cancelled' WHERE payment_intent_id = :pi AND merchant_id = :mid AND status = 'pending'",
            ['pi' => $intent['id'], 'mid' => $mid]
        );

        $cancelUrl = $intent['cancel_url'] ?: $intent['redirect_url'];
        if ($cancelUrl) {
            $separator = str_contains($cancelUrl, '?') ? '&' : '?';
            $redirectTarget = $cancelUrl . $separator . 'token=' . urlencode($token) . '&status=cancelled';
            return Response::redirect($redirectTarget);
        }

        return $this->renderStatus($token, 'cancelled', $intent);
    }

    /**
     * POST /checkout/intent/{token}/manual-verify
     */
    public function manualVerify(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return $this->renderStatus($token, 'expired');
        }

        $mid = (int) $intent['merchant_id'];
        $txn = $this->db->fetchOne(
            "SELECT * FROM op_transactions WHERE payment_intent_id = :pi AND merchant_id = :mid AND status = 'awaiting_verification' ORDER BY id DESC LIMIT 1",
            ['pi' => $intent['id'], 'mid' => $mid]
        );

        if (!$txn) {
            return $this->renderStatus($token, 'expired');
        }

        $verifyData = [
            'sender_number'  => $req->input('sender_number', ''),
            'transaction_id' => $req->input('transaction_id', ''),
            'submitted_at'   => DateHelper::now(),
        ];

        $existingMeta = json_decode($txn['metadata'] ?? '{}', true) ?: [];
        $existingMeta['verification'] = $verifyData;

        $this->txnRepo->setStatusWithMeta((int) $txn['id'], 'pending_review', $existingMeta, $mid);

        // Update payment intent to processing
        $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'processing']);

        $this->events->doAction('checkout.manual_verify.submitted', $txn, $verifyData);

        return Response::redirect("/checkout/intent/{$token}/status");
    }

    private function renderStatus(string $ref, string $status, ?array $intent = null): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $mid = 0;
        if ($intent) {
            $mid = (int) $intent['merchant_id'];
        } else {
            $row = $this->intents->findByToken($ref);
            if ($row) {
                $mid = (int) $row['merchant_id'];
                $intent = $row;
            }
        }
        $brand = $mid > 0 ? $this->loadBrand($mid) : ['name' => 'Own Pay', 'logo' => '', 'color' => '#0D9488', 'support_email' => ''];

        $statusLabels = [
            'success' => 'Payment Successful', 'completed' => 'Payment Successful',
            'failed' => 'Payment Failed', 'cancelled' => 'Payment Cancelled',
            'canceled' => 'Payment Cancelled', 'expired' => 'Payment Expired',
            'pending' => 'Payment Pending', 'pending_review' => 'Payment Under Review',
            'awaiting_verification' => 'Awaiting Verification',
            'processing' => 'Payment Processing',
        ];

        $txn = null;
        if ($intent) {
            // BUG-012 FIX: Added merchant_id scope to prevent cross-brand data leakage
            $txn = $this->db->fetchOne(
                "SELECT * FROM op_transactions WHERE payment_intent_id = :pi AND merchant_id = :mid ORDER BY id DESC LIMIT 1",
                ['pi' => $intent['id'], 'mid' => $mid]
            );

            // Sync payment intent status if transaction is completed/failed
            if ($txn) {
                if ($txn['status'] === 'completed' && $intent['status'] !== 'completed') {
                    $this->paymentService->markPaid((int) $intent['id'], $mid);
                    $status = 'completed';
                } elseif ($txn['status'] === 'failed' && $intent['status'] !== 'failed') {
                    $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'failed']);
                    $status = 'failed';
                }
            }
        }

        if ($txn) {
            $txn['currency_symbol'] = $this->currencyService->getSymbol($txn['currency']);
        } elseif ($intent) {
            $txn = [
                'trx_id' => $intent['token'],
                'amount' => $intent['amount'],
                'currency' => $intent['currency'],
                'currency_symbol' => $this->currencyService->getSymbol($intent['currency']),
            ];
        } else {
            $txn = ['trx_id' => $ref];
        }

        $targetUrl = '';
        if ($intent) {
            $targetUrl = $intent['redirect_url'];
            if (in_array($status, ['failed', 'cancelled', 'expired'], true)) {
                $targetUrl = $intent['cancel_url'] ?? $intent['redirect_url'];
            }
        }

        $tplName = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
        return Response::html($twig->render($tplName, [
            'txn'                   => $txn,
            'status'                => $status,
            'status_label'          => $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)),
            'brand'                 => $brand,
            'lang'                  => [
                'success_msg' => $this->settings->get('general', 'checkout_success_msg', ''),
                'pending_msg' => $this->settings->get('general', 'checkout_pending_msg', ''),
                'failed_msg'  => $this->settings->get('general', 'checkout_failed_msg', ''),
            ],
            'merchant_redirect_url' => $targetUrl,
            'intent_token'          => $ref,
            'intent_status'         => $status,
            'is_intent'             => true,
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
}
