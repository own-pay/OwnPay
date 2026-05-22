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
 * 
 * Manages the checkout lifecycle, including customer presentation pages, currency 
 * conversion workflows for regional processors, integration handshake redirects, 
 * double-capture idempotency checks, and status confirmation templates.
 */
final class PaymentIntentCheckoutController
{
    /**
     * @var Container The service container instance.
     */
    private Container $c;

    /**
     * @var EventManager The system-wide event manager.
     */
    private EventManager $events;

    /**
     * @var TransactionRepository Repository mapping transactions.
     */
    private TransactionRepository $txnRepo;

    /**
     * @var ManualGatewayRepository Repository handling manual payment configurations.
     */
    private ManualGatewayRepository $manualGw;

    /**
     * @var GatewayConfigRepository Repository handling API payment configurations.
     */
    private GatewayConfigRepository $apiGw;

    /**
     * @var MerchantRepository Repository handling brand/merchant operations.
     */
    private MerchantRepository $merchants;

    /**
     * @var SettingsRepository Repository handling site configuration variables.
     */
    private SettingsRepository $settings;

    /**
     * @var PaymentIntentRepository Repository handling intent records.
     */
    private PaymentIntentRepository $intents;

    /**
     * @var PaymentService Service layer managing payment intents.
     */
    private PaymentService $paymentService;

    /**
     * @var CurrencyService Service handling currency rates and conversion.
     */
    private CurrencyService $currencyService;

    /**
     * @var TransactionService Service managing transaction states.
     */
    private TransactionService $transactionService;

    /**
     * @var \OwnPay\Core\Database Database driver wrapper.
     */
    private \OwnPay\Core\Database $db;

    /**
     * Constructor.
     *
     * @param Container $c The service container instance.
     * @param EventManager $events The system-wide event manager.
     * @param TransactionRepository $txnRepo Repository mapping transactions.
     * @param ManualGatewayRepository $manualGw Repository handling manual payment configurations.
     * @param GatewayConfigRepository $apiGw Repository handling API payment configurations.
     * @param MerchantRepository $merchants Repository handling brand/merchant operations.
     * @param SettingsRepository $settings Repository handling site configuration variables.
     * @param PaymentIntentRepository $intents Repository handling intent records.
     * @param PaymentService $paymentService Service layer managing payment intents.
     * @param CurrencyService $currencyService Service handling currency rates and conversion.
     * @param TransactionService $transactionService Service managing transaction states.
     * @param \OwnPay\Core\Database $db Database driver wrapper.
     */
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
     * Display the checkout page for a specific payment intent.
     *
     * GET /checkout/intent/{token}
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The rendered checkout interface or status redirect.
     * @throws \RuntimeException If the required secure signature keys are missing from environmental config.
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

        // Resolve active payment gateway configurations and manual gateway endpoints.
        $manualGateways = $this->manualGw->forTenant($mid)->listActive();
        $apiGateways = $this->apiGw->forTenant($mid)->listActiveForCheckout();

        // Retrieve the localized symbol representing the invoice's original currency.
        $intent['currency_symbol'] = $this->currencyService->getSymbol($intent['currency']);

        // Discover active plugins to extract metadata and theme assets for MFS/gateways.
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

        $gateways = ['mfs' => [], 'bank' => [], 'global' => [], 'express' => []];

        // In-line closure helper to evaluate gateway requirements and execute cross-currency conversion.
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
            
            // Perform cross-currency translation for manual Mobile Financial Services (MFS) processing BDT.
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
            
            // Evaluate and perform cross-currency translation for regional API gateways.
            $gw = $checkAndConvert($gw, $slug);

            $gateways[$cat][] = $gw;
        }

        $brand = $this->loadBrand($mid);
        $faqs = json_decode($this->settings->get('general', 'faqs', '[]'), true);

        // Generate HMAC signature securing amount, currency, and token details against checkout tampering.
        // Security check: ensure HMAC_KEY or APP_KEY is properly configured; fallback or throw if missing.
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $checkoutHash = hash_hmac('sha256', $intent['amount'] . '|' . $intent['currency'] . '|' . $token, $hmacKey);

        // Compile details mapping for manual gateway instructions shown in the checkout modal.
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

            // Calculate the equivalent BDT amount for manual payment prompt visualization.
            $gwSlugLower = strtolower($gw['slug'] ?? $gw['name'] ?? '');
            $convAmount = null;
            $convCurrency = null;
            if (str_contains($gwSlugLower, 'bkash') || str_contains($gwSlugLower, 'nagad') || str_contains($gwSlugLower, 'rocket') || str_contains($gwSlugLower, 'upay')) {
                if ($intent['currency'] !== 'BDT') {
                    try {
                        $convAmount = $this->currencyService->convert((string) $intent['amount'], $intent['currency'], 'BDT');
                        $convCurrency = 'BDT';
                    } catch (\Throwable $e) {}
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

        // Calculate remaining session time bounds for checkout expiration timer.
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

        // Extract and decode payment invoice line items from intent metadata.
        $items = [];
        $metaObj = json_decode($intent['metadata'] ?? '{}', true) ?: [];
        if (isset($metaObj['items'])) {
            $items = $metaObj['items'];
        }

        // Construct mock transaction entity schema structure for compatibility with legacy Twig templates.
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
     * Process checkout form submission for a payment intent.
     *
     * POST /checkout/intent/{token}/pay
     *
     * @param Request $req The incoming HTTP request.
     * @return Response Redirection, gateway form html, or JSON state payload.
     * @throws \RuntimeException If the required secure signature keys are missing from environmental config.
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

        // Verify checkout integrity via HMAC signature validation.
        $submittedHash = $req->input('checkout_hash', '');
        // Security check: ensure HMAC key is present and validate parameters against tampering.
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

        // Resolve and apply currency translation rules if the gateway dictates localized currency.
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

        // Initialize and write a database transaction record mapped to this intent.
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

        $txn = null;
        $existingTxn = $this->txnRepo->forTenant($mid)->findByIntentId((int)$intent['id']);
        if ($existingTxn !== null) {
            $statusObj = \OwnPay\Enum\TransactionStatus::tryFrom($existingTxn['status']);
            if ($statusObj !== null && !$statusObj->isTerminal()) {
                try {
                    $this->txnRepo->forTenant($mid)->updateScoped((int)$existingTxn['id'], [
                        'gateway_slug' => $gateway,
                        'amount'       => $amount,
                        'currency'     => $currency,
                        'method'       => $gatewayMode === 'api' ? 'api' : 'manual',
                        'reference'    => $intent['description'] ?? null,
                        'metadata'     => !empty($intent['metadata']) ? $intent['metadata'] : '{}',
                    ]);
                    $txn = $this->txnRepo->forTenant($mid)->findScoped((int)$existingTxn['id']);
                } catch (\Throwable $e) {
                    $this->c->get(\OwnPay\Service\System\Logger::class)->error('Transaction update failed: ' . $e->getMessage());
                    if ($req->isAjax()) {
                        return Response::json(['success' => false, 'error' => 'Payment processing failed. Please try again.'], 500);
                    }
                    return $this->renderStatus($token, 'failed');
                }
            }
        }

        if ($txn === null) {
            try {
                $txn = $this->transactionService->create($mid, $txnData);
            } catch (\Throwable $e) {
                // Log failure context for administration auditing; response contains sanitized user message.
                $this->c->get(\OwnPay\Service\System\Logger::class)->error('Transaction creation failed: ' . $e->getMessage());
                if ($req->isAjax()) {
                    return Response::json(['success' => false, 'error' => 'Payment processing failed. Please try again.'], 500);
                }
                return $this->renderStatus($token, 'failed');
            }
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

            // Update intent to processing.
            $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'processing']);

            if ($req->isAjax()) {
                return Response::json([
                    'success'      => true,
                    'redirect_url' => "/checkout/intent/{$token}/status",
                ]);
            }
            return Response::redirect("/checkout/intent/{$token}/status");
        }

        // Initialize gateway integration handshake and resolve external redirection parameters.
        if ($this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
            try {
                $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                
                // Resolve the white-labeled payment callback URL bound to the merchant domain context.
                $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
                $callbackUrl = $urlService->buildCallbackUrl($mid, $token, $req);

                // Enforce dynamic cross-currency conversion if gateway requirements differ from request currency.
                $payAmount = $txn['amount'];
                $payCurrency = $txn['currency'];
                if ($this->c->has(\OwnPay\Gateway\GatewayBridge::class)) {
                    $bridge = $this->c->get(\OwnPay\Gateway\GatewayBridge::class);
                    $supported = $bridge->getSupportedCurrencies($gateway);
                    if (!empty($supported) && !in_array($payCurrency, $supported, true)) {
                        // Apply currency conversion using exchange rate service and update metadata audits.
                        $targetCurrency = $supported[0];
                        if ($this->c->has(\OwnPay\Service\Payment\CurrencyService::class)) {
                            $currSvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
                            $converted = $currSvc->convert($payAmount, $payCurrency, $targetCurrency);
                            if ($converted !== '0') {
                                // Record pre-conversion amount and exchange rate variables for financial audit trails.
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
                    // Track external gateway initiation stage by updating transaction state to processing.
                    $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gateway, 'processing', $mid);
                    $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'processing']);

                    if ($req->isAjax()) {
                        return Response::json([
                            'success'      => true,
                            'redirect_url' => $result['redirect_url'],
                        ]);
                    }
                    return Response::redirect($result['redirect_url']);
                } elseif ($result['success'] && !empty($result['form_html'])) {
                    // Track external gateway initiation stage by updating transaction state to processing.
                    $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gateway, 'processing', $mid);
                    $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'processing']);

                    if ($req->isAjax()) {
                        return Response::json([
                            'success'   => true,
                            'form_html' => $result['form_html'],
                        ]);
                    }
                    return Response::html($result['form_html']);
                }

                $errorMsg = $result['error'] ?? 'Gateway returned no redirect URL';
                if ($req->isAjax()) {
                    return Response::json(['success' => false, 'error' => $errorMsg], 422);
                }
            } catch (\Throwable $e) {
                $this->c->get(\OwnPay\Service\System\Logger::class)->error(
                    "Gateway {$gateway} initiation failed: " . $e->getMessage()
                );
                // Return standardized exception feedback for presentation to client.
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
     * Display status page for the payment intent, and handle regional callback captures.
     *
     * GET /checkout/intent/{token}/status
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The rendered transaction status view.
     */
    public function status(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return $this->renderStatus($token, 'expired');
        }

        $mid = (int) $intent['merchant_id'];

        // Handle callback parameters and execute transaction settlement checks.
        // Map query inputs from regional integration interfaces.
        $callbackPaymentId = $req->query('paymentID') ?? $req->query('payment_id') ?? '';
        $callbackStatus = $req->query('status') ?? '';

        if ($callbackPaymentId !== '' && $intent['status'] === 'processing') {
            // Retrieve corresponding active transaction context record.
            $txn = $this->db->fetchOne(
                "SELECT * FROM op_transactions WHERE payment_intent_id = :pi AND merchant_id = :mid AND status = 'processing' ORDER BY id DESC LIMIT 1",
                ['pi' => $intent['id'], 'mid' => $mid]
            );

            if ($txn && $this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
                // Acquire database-level transaction status lock to prevent concurrent double-capture callback processing.
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
                            // Handle explicit rejection status by updating database records to failed.
                            if (in_array($callbackStatus, ['cancel', 'failure', 'failed'], true)) {
                                $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gateway, 'failed', $mid);
                                $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'failed']);
                                $intent['status'] = 'failed';
                            } else {
                                // Revert status to processing state to support retrying checkout verification.
                                $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gateway, 'processing', $mid);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Revert status on capture failure to permit subsequent verification attempts.
                    $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $txn['gateway_slug'] ?? '', 'processing', $mid);
                    // Log settlement capture exceptions without disrupting presentation page rendering.
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
     * Terminate / cancel a payment intent session manually.
     *
     * POST /checkout/intent/{token}/cancel
     *
     * @param Request $req The incoming HTTP request.
     * @return Response Redirect to external merchant landing URL or local status screen.
     * @throws \RuntimeException If the secure signature keys are missing from environmental config.
     */
    public function cancel(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return $this->renderStatus($token, 'cancelled');
        }

        $submittedHash = $req->input('checkout_hash', '');
        if (empty($submittedHash)) {
            return $this->renderStatus($token, 'expired');
        }
        // Validate signature integrity protecting status state against manipulation.
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $expectedHash = hash_hmac('sha256', $intent['amount'] . '|' . $intent['currency'] . '|' . $token, $hmacKey);
        if (!hash_equals($expectedHash, $submittedHash)) {
            return $this->renderStatus($token, 'expired');
        }

        $mid = (int) $intent['merchant_id'];
        $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'cancelled']);

        // Terminate active child transactions mapped to the cancelled checkout intent.
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
     * Submit verification details for manual payment gateway processing.
     *
     * POST /checkout/intent/{token}/manual-verify
     *
     * @param Request $req The incoming HTTP request.
     * @return Response Redirect status response object.
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

        // Elevate checkout intent status to reflect review processing.
        $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'processing']);

        $this->events->doAction('checkout.manual_verify.submitted', $txn, $verifyData);

        return Response::redirect("/checkout/intent/{$token}/status");
    }

    /**
     * Render the payment confirmation status page.
     *
     * @param string $ref Unique transaction reference token.
     * @param string $status Target transaction status tag.
     * @param array|null $intent The active intent details, if found.
     * @return Response HTML template response.
     */
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
            // Query transaction scoped strictly to merchant ID to guarantee tenant isolation.
            $txn = $this->db->fetchOne(
                "SELECT * FROM op_transactions WHERE payment_intent_id = :pi AND merchant_id = :mid ORDER BY id DESC LIMIT 1",
                ['pi' => $intent['id'], 'mid' => $mid]
            );

            // Reconcile and synchronize parent intent status with final transaction outcomes.
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

    /**
     * Resolve branding parameters for a given merchant ID.
     *
     * @param int $mid Brand/Merchant ID.
     * @return array Brand details styling array.
     */
    private function loadBrand(int $mid): array
    {
        // Load customized white-label styling assets via brand context layout services.
        if ($this->c->has(\OwnPay\Service\Brand\BrandThemeService::class)) {
            return $this->c->get(\OwnPay\Service\Brand\BrandThemeService::class)->getBrandTheme($mid);
        }

        // Fallback: resolve basic branding elements using core configuration parameters.
        $merchant = $this->merchants->find($mid);
        $s = $this->settings->getGroup('general');
        return [
            'name'          => $merchant['name'] ?? $s['app_name'] ?? 'Own Pay',
            'logo'          => $merchant['logo'] ?? '',
            'color'         => $s['theme_primary'] ?? '#0D9488',
            'support_email' => $s['support_email'] ?? '',
        ];
    }

    /**
     * Handles express checkout (Apple Pay / Google Pay) for payment intents via AJAX post.
     *
     * @param \OwnPay\Http\Request $req The incoming HTTP request.
     * @return \OwnPay\Http\Response The JSON redirect outcome response.
     */
    public function expressPay(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return Response::json(['success' => false, 'error' => 'Transaction expired or not found.'], 404);
        }

        if ($intent['status'] !== 'pending') {
            return Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
        }

        // Verify checkout integrity via HMAC signature validation.
        $submittedHash = $req->input('checkout_hash', '');
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $expectedHash = hash_hmac('sha256', $intent['amount'] . '|' . $intent['currency'] . '|' . $token, $hmacKey);
        if (!hash_equals($expectedHash, $submittedHash)) {
            return Response::json(['success' => false, 'error' => 'Session expired. Please refresh the page.'], 403);
        }

        $provider = (string) $req->input('provider', '');
        $gatewaySlug = '';
        if (in_array($provider, ['apple-pay', 'Apple Pay'], true)) {
            $gatewaySlug = 'apple-pay';
        } elseif (in_array($provider, ['google-pay', 'Google Pay'], true)) {
            $gatewaySlug = 'google-pay';
        } else {
            return Response::json(['success' => false, 'error' => 'Invalid express provider.'], 400);
        }

        $mid = (int) $intent['merchant_id'];

        // Assert configured and active
        $activeGateways = $this->apiGw->forTenant($mid)->listActiveForCheckout();
        $isActive = false;
        foreach ($activeGateways as $gw) {
            if (($gw['slug'] ?? '') === $gatewaySlug) {
                $isActive = true;
                break;
            }
        }
        if (!$isActive) {
            return Response::json(['success' => false, 'error' => 'Selected gateway is not active.'], 422);
        }

        // Initialize and write a database transaction record mapped to this intent.
        $txnData = [
            'merchant_id'       => $mid,
            'payment_intent_id' => $intent['id'],
            'customer_id'       => $intent['customer_id'],
            'gateway_slug'      => $gatewaySlug,
            'amount'            => $intent['amount'],
            'currency'          => $intent['currency'],
            'method'            => 'api',
            'status'            => 'pending',
            'reference'         => $intent['description'] ?? null,
            'metadata'          => !empty($intent['metadata']) ? $intent['metadata'] : '{}',
        ];

        try {
            $txn = $this->transactionService->create($mid, $txnData);
        } catch (\Throwable $e) {
            $this->c->get(\OwnPay\Service\System\Logger::class)->error('Express Transaction creation failed: ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => 'Payment processing failed. Please try again.'], 500);
        }

        if ($this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
            try {
                $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                
                $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
                $callbackUrl = $urlService->buildCallbackUrl($mid, $token, $req);

                $result = $svc->initiatePayment($mid, $gatewaySlug, [
                    'amount'       => $txn['amount'],
                    'currency'     => $txn['currency'],
                    'trx_id'       => $txn['trx_id'],
                    'redirect_url' => $callbackUrl,
                    'cancel_url'   => $callbackUrl,
                    'existing_txn' => true,
                ]);

                if ($result['success'] && !empty($result['redirect_url'])) {
                    $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gatewaySlug, 'processing', $mid);
                    $this->intents->forTenant($mid)->updateScoped((int) $intent['id'], ['status' => 'processing']);

                    return Response::json([
                        'success'      => true,
                        'redirect_url' => $result['redirect_url'],
                    ]);
                }

                $errorMsg = $result['error'] ?? 'Gateway returned no redirect URL';
                $this->c->get(\OwnPay\Service\System\Logger::class)->warning(
                    "Express Gateway {$gatewaySlug} failed for intent {$intent['id']}: {$errorMsg}"
                );

                return Response::json(['success' => false, 'error' => $errorMsg], 422);

            } catch (\Throwable $e) {
                $this->c->get(\OwnPay\Service\System\Logger::class)->error(
                    "Express Gateway {$gatewaySlug} initiation failed: " . $e->getMessage()
                );
                return Response::json(['success' => false, 'error' => 'Gateway connection error. Please try again.'], 422);
            }
        }

        return Response::json(['success' => false, 'error' => 'Payment service is not configured.'], 500);
    }
}
