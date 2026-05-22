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
 * Controller managing the main customer payment checkout operations.
 *
 * This controller orchestrates the checkout UI lifecycle, including rendering the checkout page,
 * retrieving manual and API payment gateways, enforcing session expirations, executing HMAC integrity
 * handshakes, routing callback/redirect transactions, and processing verification submissions.
 */
final class CheckoutController
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
     * @var \OwnPay\Repository\TransactionRepository The transaction repository.
     */
    private TransactionRepository $txnRepo;

    /**
     * @var \OwnPay\Repository\ManualGatewayRepository The manual gateway repository.
     */
    private ManualGatewayRepository $manualGw;

    /**
     * @var \OwnPay\Repository\GatewayConfigRepository The API gateway configuration repository.
     */
    private GatewayConfigRepository $apiGw;

    /**
     * @var \OwnPay\Repository\MerchantRepository The merchant repository.
     */
    private MerchantRepository $merchants;

    /**
     * @var \OwnPay\Repository\SettingsRepository The settings repository.
     */
    private SettingsRepository $settings;

    /**
     * Initializes the checkout controller with required system dependencies.
     *
     * @param \OwnPay\Container $c The dependency injection container.
     * @param \OwnPay\Event\EventManager $events The event manager.
     * @param \OwnPay\Repository\TransactionRepository $txnRepo Repository for transactions.
     * @param \OwnPay\Repository\ManualGatewayRepository $manualGw Repository for manual payment gateways.
     * @param \OwnPay\Repository\GatewayConfigRepository $apiGw Repository for API payment gateway credentials.
     * @param \OwnPay\Repository\MerchantRepository $merchants Repository for merchant details.
     * @param \OwnPay\Repository\SettingsRepository $settings Repository for system configurations.
     */
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
     * Displays the main checkout screen for a specific active transaction.
     *
     * Validates transaction existence, expiry constraints, associated payment link statuses,
     * resolves brand information, categorizes available manual and API gateways, builds
     * JS timer configurations, and generates a security HMAC token for the transaction payload.
     *
     * @param \OwnPay\Http\Request $req The incoming HTTP request.
     * @return \OwnPay\Http\Response The checkout HTML response.
     * @throws \RuntimeException If the required HMAC_KEY or APP_KEY configuration is missing.
     */
    public function show(Request $req): Response
    {
        $ref = (string) $req->param('token');
        $txn = $this->txnRepo->findActiveForCheckout($ref);

        if (!$txn) {
            return $this->renderStatus($ref, 'expired');
        }

        // Enforce session timeout: cancel processing if the transaction timeline has expired.
        if (!empty($txn['expires_at']) && DateHelper::isPast($txn['expires_at'])) {
            return $this->renderStatus($ref, 'expired');
        }

        // Check payment link validity: cancel the transaction if the parent payment link is inactive or expired.
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

        // Dynamic currency resolution: fetch the localized currency symbol instead of a hardcoded value.
        if ($this->c->has(\OwnPay\Service\Payment\CurrencyService::class)) {
            $currSvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
            $txn['currency_symbol'] = $currSvc->getSymbol($txn['currency'] ?? 'BDT');
        }

        $this->events->doAction('checkout.before', $txn);

        // Query active manual and API-based gateways configured for this merchant.
        $manualGateways = $this->manualGw->forTenant($mid)->listActive();
        $apiGateways = $this->apiGw->forTenant($mid)->listActiveForCheckout();

        // Read active plugin metadata manifests to map colors, icons, and categories.
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

        // Distribute gateways into checkout categorizations.
        // Normalize properties mapping logo path keys to logo for template consistency.
        $gateways = ['mfs' => [], 'bank' => [], 'global' => [], 'express' => []];
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

        // Load white-label brand themes and general checkout settings.
        $brand = $this->loadBrand($mid);
        $faqs = json_decode($this->settings->get('general', 'faqs', '[]'), true);

        // Extract associated invoice identifier from transaction metadata payload to retrieve invoice line items.
        $items = [];
        $invoiceId = $meta['invoice_id'] ?? null;
        if ($invoiceId) {
            $invoiceRepo = $this->c->get(\OwnPay\Repository\InvoiceRepository::class);
            $items = $invoiceRepo->listItems((int) $invoiceId);
        }

        // Compute cryptographic HMAC checksum binding amount, currency, and reference token to prevent relay tampering.
        // Retrieve the system cryptographic key using fallback chains.
        // Ensure an operational HMAC key is configured in the host environment.
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $checkoutHash = hash_hmac('sha256', $txn['amount'] . '|' . $txn['currency'] . '|' . $ref, $hmacKey);

        // Build structured instructions and settings configuration maps for manual gateways.
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

            // Isolate the primary payment address fields for inline client reference.
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
            // Render manual gateway configuration with XSS entity escaping.
            'manual_gateways' => json_encode($manualDetails, JSON_HEX_TAG | JSON_HEX_AMP),
        ];

        $data = $this->events->applyFilter('checkout.render', $data);

        $tplName = $this->events->applyFilter('checkout.template', 'checkout/checkout.twig');
        $twig = $this->c->get(\Twig\Environment::class);
        return Response::html($twig->render($tplName, $data));
    }

    /**
     * Renders the transaction status view (e.g. success, processing, failed, expired).
     *
     * Resolves human-readable labels, currency symbols, and brand assets for the targeted state page.
     *
     * @param string $ref The transaction reference identifier.
     * @param string $status The current status code of the transaction.
     * @return \OwnPay\Http\Response The status HTML response.
     */
    private function renderStatus(string $ref, string $status): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $txn = $this->txnRepo->findAnyByTrxId($ref);
        $mid = (int) ($txn['merchant_id'] ?? 0);
        $brand = $mid > 0 ? $this->loadBrand($mid) : ['name' => 'Own Pay', 'logo' => '', 'color' => '#0D9488', 'support_email' => ''];

        // Map current transaction state to user-friendly messaging layouts.
        $statusLabels = [
            'success' => 'Payment Successful', 'completed' => 'Payment Successful',
            'failed' => 'Payment Failed', 'cancelled' => 'Payment Cancelled',
            'canceled' => 'Payment Cancelled', 'expired' => 'Payment Expired',
            'pending' => 'Payment Pending', 'pending_review' => 'Payment Under Review',
            'awaiting_verification' => 'Awaiting Verification',
            'processing' => 'Payment Processing',
        ];

        // Retrieve dynamic currency symbols for status confirmation page.
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

    /**
     * Resolves theme styling configurations and brand visual assets for the merchant.
     *
     * Utilizes BrandThemeService for white-labeled customization when active, falling back
     * to global default settings if unresolved.
     *
     * @param int $mid The merchant identifier.
     * @return array<string, mixed> The array containing visual style settings.
     */
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

    /**
     * Constructs the JS configurations injected into checkout template views.
     *
     * Computes remaining session session timer limits and collects gateway metadata.
     *
     * @param array<string, mixed> $txn The active transaction record.
     * @param array<string, mixed> $brand The active brand theme settings.
     * @param array<\OwnPay\Plugin\PluginManifest> $manifests Discovered gateway plugins metadata array.
     * @return array<string, mixed> The compiled JS configuration.
     */
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
     * Processes payment gateway selection or manual checkout submission.
     *
     * Validates the request with double-submit guards, checks payment link status, and verifies
     * the transaction HMAC integrity token. Delegates API transactions to the GatewayApiService,
     * resolving custom callbacks under the brand domain context, or registers manual details directly.
     *
     * @param \OwnPay\Http\Request $req The incoming HTTP request.
     * @return \OwnPay\Http\Response The JSON redirect, HTML payload, or HTTP redirect response.
     * @throws \RuntimeException If the required HMAC_KEY or APP_KEY configuration is missing.
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

        // Prevent double processing: allow only pending transactions to request capture.
        if ($txn['status'] !== 'pending') {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
            }
            return $this->renderStatus($token, $txn['status']);
        }

        // Re-verify payment link availability: enforce status limits during final capture step.
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

        // Enforce security handshake verification checking submitted HMAC against local signature.
        $submittedHash = $req->input('checkout_hash', '');
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
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

        // API Handshake: delegate connection requests targeting external gateways.
        if ($this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
            try {
                $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                
                // Multi-brand white-labeling: build callback target endpoints utilizing the active custom domain.
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
                    $this->txnRepo->setGatewayAndStatus(
                        (int) $txn['id'], $gateway, 'processing', (int) $txn['merchant_id']
                    );

                    // Dispatch ajax redirects for asynchronous capture handlers.
                    if ($req->isAjax()) {
                        return Response::json([
                            'success'      => true,
                            'redirect_url' => $result['redirect_url'],
                        ]);
                    }
                    return Response::redirect($result['redirect_url']);
                } elseif ($result['success'] && !empty($result['form_html'])) {
                    $this->txnRepo->setGatewayAndStatus(
                        (int) $txn['id'], $gateway, 'processing', (int) $txn['merchant_id']
                    );

                    if ($req->isAjax()) {
                        return Response::json([
                            'success'   => true,
                            'form_html' => $result['form_html'],
                        ]);
                    }
                    return Response::html($result['form_html']);
                }

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

        return Response::redirect("/checkout/{$token}/status");
    }

    /**
     * Cancels the active checkout transaction.
     *
     * Authenticates cancellation requests using transaction HMAC key checks.
     *
     * @param \OwnPay\Http\Request $req The incoming HTTP request.
     * @return \OwnPay\Http\Response The cancelled status response.
     * @throws \RuntimeException If the required HMAC_KEY or APP_KEY configuration is missing.
     */
    public function cancel(Request $req): Response
    {
        $token = (string) $req->param('token');
        $txn = $this->txnRepo->findActiveForCheckout($token);

        if (!$txn) {
            return $this->renderStatus($token, 'cancelled');
        }

        // Authenticate cancellation requests: verify HMAC token against registered keys.
        $submittedHash = $req->input('checkout_hash', '');
        if (empty($submittedHash)) {
            return $this->renderStatus($token, 'expired');
        }
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $expectedHash = hash_hmac('sha256', $txn['amount'] . '|' . $txn['currency'] . '|' . $token, $hmacKey);
        if (!hash_equals($expectedHash, $submittedHash)) {
            return $this->renderStatus($token, 'expired');
        }

        $this->txnRepo->cancelByTrxId($token);
        $this->events->doAction('checkout.cancelled', $token);
        return $this->renderStatus($token, 'cancelled');
    }

    /**
     * Handles payment status lookups and external callback captures.
     *
     * Monitors query params (e.g. paymentID/payment_id) from external redirect flows,
     * invoking the API callback capture loop on initial resolution visits.
     *
     * @param \OwnPay\Http\Request $req The incoming HTTP request.
     * @return \OwnPay\Http\Response The checkout status HTML response.
     */
    public function status(Request $req): Response
    {
        $token = (string) $req->param('token');
        $txn = $this->txnRepo->findAnyByTrxId($token);
        $status = $txn['status'] ?? 'expired';

        // Redirect callback loop: execute final capture steps when external providers redirect.
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
     * Registers manual verification transaction proof provided by customers.
     *
     * Transitions status to review pending and records tracking numbers in transaction metadata.
     *
     * @param \OwnPay\Http\Request $req The incoming HTTP request.
     * @return \OwnPay\Http\Response The pending review HTML response.
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

    /**
     * Handles express checkout (Apple Pay / Google Pay) via AJAX post.
     *
     * @param \OwnPay\Http\Request $req The incoming HTTP request.
     * @return \OwnPay\Http\Response The JSON redirect outcome response.
     */
    public function expressPay(Request $req): Response
    {
        $token = (string) $req->param('token');
        $txn = $this->txnRepo->findActiveForCheckout($token);

        if (!$txn) {
            return Response::json(['success' => false, 'error' => 'Transaction expired or not found.'], 404);
        }

        if ($txn['status'] !== 'pending') {
            return Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
        }

        // Re-verify payment link availability: enforce status limits during final capture step.
        $meta = json_decode($txn['metadata'] ?? '{}', true);
        $linkId = $meta['payment_link_id'] ?? null;
        if ($linkId !== null) {
            $linkRepo = $this->c->get(\OwnPay\Repository\PaymentLinkRepository::class);
            $link = $linkRepo->forTenant((int) $txn['merchant_id'])->findScoped((int) $linkId);
            if (!$link || $link['status'] !== 'active'
                || (!empty($link['expires_at']) && DateHelper::isPast($link['expires_at']))) {
                $this->txnRepo->cancelByTrxId($txn['trx_id']);
                return Response::json(['success' => false, 'error' => 'Payment link has expired.'], 410);
            }
        }

        // Enforce security handshake verification checking submitted HMAC against local signature.
        $submittedHash = $req->input('checkout_hash', '');
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $expectedHash = hash_hmac('sha256', $txn['amount'] . '|' . $txn['currency'] . '|' . $token, $hmacKey);
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

        $mid = (int) $txn['merchant_id'];

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

        if ($this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
            try {
                $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                
                $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
                $callbackUrl = $urlService->buildLegacyCallbackUrl($mid, $token, $req);

                $result = $svc->initiatePayment($mid, $gatewaySlug, [
                    'amount'       => $txn['amount'],
                    'currency'     => $txn['currency'],
                    'trx_id'       => $txn['trx_id'],
                    'redirect_url' => $callbackUrl,
                    'cancel_url'   => $callbackUrl,
                    'existing_txn' => true,
                ]);

                if ($result['success'] && !empty($result['redirect_url'])) {
                    $this->txnRepo->setGatewayAndStatus(
                        (int) $txn['id'], $gatewaySlug, 'processing', $mid
                    );

                    return Response::json([
                        'success'      => true,
                        'redirect_url' => $result['redirect_url'],
                    ]);
                }

                $errorMsg = $result['error'] ?? 'Gateway returned no redirect URL';
                $this->c->get(\OwnPay\Service\System\Logger::class)->warning(
                    "Express Gateway {$gatewaySlug} failed for trx {$txn['trx_id']}: {$errorMsg}"
                );

                return Response::json([
                    'success' => false,
                    'error'   => $errorMsg,
                ], 422);

            } catch (\Throwable $e) {
                $this->c->get(\OwnPay\Service\System\Logger::class)->error(
                    "Express Gateway {$gatewaySlug} initiation failed: " . $e->getMessage()
                );

                return Response::json([
                    'success' => false,
                    'error'   => 'Payment gateway error: ' . $e->getMessage(),
                ], 422);
            }
        }

        return Response::json([
            'success' => false,
            'error'   => 'Payment service is not configured.',
        ], 500);
    }
}
