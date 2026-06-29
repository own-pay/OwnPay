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
    use CheckoutPresentationTrait;

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

        if ($txn === null) {
            return $this->renderStatus($ref, 'expired');
        }

        $midVal = $txn['merchant_id'] ?? 0;
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;

        // Verify that the transaction merchant matches the resolved host domain merchant (prevent Cross-Brand Leakage)
        $domainMidVal = $req->getAttribute('merchant_id');
        if (is_int($domainMidVal) || is_string($domainMidVal)) {
            $domainMid = (int) $domainMidVal;
            if ($domainMid !== $mid) {
                return $this->renderStatus($ref, 'expired');
            }
        }

        $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
            $brandCtx->setActiveBrandId($mid);
        }

        $txnTrxIdVal = $txn['trx_id'] ?? null;
        $txnTrxId = is_string($txnTrxIdVal) ? $txnTrxIdVal : '';

        $txnAmountVal = $txn['amount'] ?? '0';
        $txnAmount = (is_string($txnAmountVal) || is_int($txnAmountVal) || is_float($txnAmountVal)) ? (string) $txnAmountVal : '0';

        $txnCurrencyVal = $txn['currency'] ?? 'BDT';
        $txnCurrency = is_string($txnCurrencyVal) ? $txnCurrencyVal : 'BDT';

        // Verify active brand status
        $merchant = $this->merchants->find($mid);
        if ($merchant === null || ($merchant['status'] ?? 'active') !== 'active') {
            return $this->renderStatus($ref, 'expired');
        }

        // Enforce session timeout: cancel processing if the transaction timeline has expired.
        $expiresAtVal = $txn['expires_at'] ?? '';
        $expiresAt = is_string($expiresAtVal) ? $expiresAtVal : '';
        if ($expiresAt !== '' && DateHelper::isPast($expiresAt)) {
            return $this->renderStatus($ref, 'expired');
        }

        // Check payment link validity: cancel the transaction if the parent payment link is inactive or expired.
        $metaRaw = $txn['metadata'] ?? '{}';
        $metaStr = is_string($metaRaw) ? $metaRaw : '{}';
        $meta = json_decode($metaStr, true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $linkIdVal = $meta['payment_link_id'] ?? null;
        if ($linkIdVal !== null && (is_int($linkIdVal) || is_string($linkIdVal))) {
            $linkId = (int) $linkIdVal;
            $linkRepo = $this->c->get(\OwnPay\Repository\PaymentLinkRepository::class);
            if ($linkRepo instanceof \OwnPay\Repository\PaymentLinkRepository) {
                $link = $linkRepo->forTenant($mid)->findScoped($linkId);
                if (is_array($link)) {
                    $linkStatusVal = $link['status'] ?? '';
                    $linkStatus = is_string($linkStatusVal) ? $linkStatusVal : '';
                    $linkExpiresAtVal = $link['expires_at'] ?? '';
                    $linkExpiresAt = is_string($linkExpiresAtVal) ? $linkExpiresAtVal : '';
                    if ($linkStatus !== 'active'
                        || ($linkExpiresAt !== '' && DateHelper::isPast($linkExpiresAt))) {
                        $this->txnRepo->cancelByTrxId($txnTrxId);
                        return $this->renderStatus($ref, 'expired');
                    }
                } else {
                    $this->txnRepo->cancelByTrxId($txnTrxId);
                    return $this->renderStatus($ref, 'expired');
                }
            }
        }

        // Dynamic currency resolution: fetch the localized currency symbol instead of a hardcoded value.
        if ($this->c->has(\OwnPay\Service\Payment\CurrencyService::class)) {
            $currSvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
            if ($currSvc instanceof \OwnPay\Service\Payment\CurrencyService) {
                $txn['currency_symbol'] = $currSvc->getSymbol($txnCurrency);
            }
        }

        $this->events->doAction('checkout.before', $txn);

        $platformId = ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) ? $brandCtx->getPlatformId() : 0;
        $manualGateways = $this->manualGw->listActiveForCheckout($mid, $platformId);
        $apiGateways = $this->apiGw->forTenant($mid)->listActiveForCheckout();

        // Read active plugin metadata manifests to map colors, icons, and categories.
        $manifests = [];
        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
        if ($loader instanceof \OwnPay\Plugin\PluginLoader) {
            $manifests = $loader->discover();
        }
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
            if (!is_string($cat) || !isset($gateways[$cat])) {
                $cat = 'mfs';
            }
            $gw['logo'] = $gw['logo_path'] ?? '';
            $gwColorsRaw = $gw['colors'] ?? '{}';
            $gwColorsStr = is_string($gwColorsRaw) ? $gwColorsRaw : '{}';
            $colorsDecoded = json_decode($gwColorsStr, true);
            $gw['color'] = (is_array($colorsDecoded) && isset($colorsDecoded['primary']) && is_string($colorsDecoded['primary'])) ? $colorsDecoded['primary'] : '#0D9488';
            $gateways[$cat][] = array_merge($gw, ['mode' => 'manual']);
        }
        foreach ($apiGateways as $gw) {
            $gwSlugVal = $gw['slug'] ?? '';
            $gwSlug = is_string($gwSlugVal) ? $gwSlugVal : '';
            $cat = $categoryMap[$gwSlug] ?? 'global';
            if (!isset($gateways[$cat])) {
                $cat = 'global';
            }
            $gw['logo'] = $gw['logo_path'] ?? '';
            $gw['color'] = isset($manifestMeta[$gwSlug]) ? $manifestMeta[$gwSlug]['color'] : '#0D9488';
            $gateways[$cat][] = array_merge($gw, ['mode' => 'api']);
        }

        // Load white-label brand themes and general checkout settings.
        $brand = $this->loadBrand($mid);
        $faqsVal = $this->settings->get('general', 'faqs', '[]');
        $faqsStr = is_string($faqsVal) ? $faqsVal : '[]';
        $faqs = json_decode($faqsStr, true);
        if (!is_array($faqs)) {
            $faqs = [];
        }

        // Extract associated invoice identifier from transaction metadata payload to retrieve invoice line items.
        $items = [];
        $invoiceIdVal = $meta['invoice_id'] ?? null;
        if ($invoiceIdVal !== null && (is_int($invoiceIdVal) || is_string($invoiceIdVal))) {
            $invoiceId = (int) $invoiceIdVal;
            $invoiceRepo = $this->c->get(\OwnPay\Repository\InvoiceRepository::class);
            if ($invoiceRepo instanceof \OwnPay\Repository\InvoiceRepository) {
                $items = $invoiceRepo->listItems($invoiceId);
            }
        }

        // Compute cryptographic HMAC checksum binding amount, currency, and reference token to prevent relay tampering.
        // Retrieve the system cryptographic key using fallback chains.
        // Ensure an operational HMAC key is configured in the host environment.
        $hmacKey = $this->resolveHmacKey();
        $checkoutHash = hash_hmac('sha256', $txnAmount . '|' . $txnCurrency . '|' . $ref, $hmacKey);

        // Build structured instructions and settings configuration maps for manual gateways.
        $manualDetails = [];
        foreach ($manualGateways as $gw) {
            $gwSlugVal = $gw['slug'] ?? $gw['name'] ?? '';
            $slug = is_string($gwSlugVal) ? $gwSlugVal : '';
            
            $inputFieldsRaw = $gw['input_fields'] ?? '[]';
            $inputFieldsStr = is_string($inputFieldsRaw) ? $inputFieldsRaw : '[]';
            $inputFieldsVal = json_decode($inputFieldsStr, true);
            $inputFields = is_array($inputFieldsVal) ? $inputFieldsVal : [];
            
            $instructionsRaw = $gw['instructions'] ?? '[]';
            $instructionsStr = is_string($instructionsRaw) ? $instructionsRaw : '[]';
            $instructionsObj = json_decode($instructionsStr, true);
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
                if (is_array($field)) {
                    if (($field['type'] ?? '') === 'payment_number' || ($field['name'] ?? '') === 'payment_number') {
                        $paymentNumberVal = $field['value'] ?? $field['default'] ?? '';
                        $paymentNumber = is_string($paymentNumberVal) ? $paymentNumberVal : '';
                        break;
                    }
                }
            }

            $gwColorsRaw = $gw['colors'] ?? '{}';
            $gwColorsStr = is_string($gwColorsRaw) ? $gwColorsRaw : '{}';
            $gwColors = json_decode($gwColorsStr, true);

            $manualDetails[$slug] = [
                'name'           => is_string($gw['name'] ?? null) ? $gw['name'] : '',
                'input_fields'   => $inputFields,
                'instructions'   => $instructions,
                'colors'         => is_array($gwColors) ? $gwColors : [],
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
            'manual_gateways' => json_encode($manualDetails, JSON_HEX_TAG | JSON_HEX_AMP),
        ];

        $dataFilter = $this->events->applyFilter('checkout.render', $data);
        $data = is_array($dataFilter) ? $dataFilter : $data;

        $tplFilter = $this->events->applyFilter('checkout.template', 'checkout/checkout.twig');
        $tplName = is_string($tplFilter) ? $tplFilter : 'checkout/checkout.twig';
        $twig = $this->c->get(\Twig\Environment::class);
        if (!$twig instanceof \Twig\Environment) {
            throw new \RuntimeException("Twig Environment not found");
        }
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
        if (!$twig instanceof \Twig\Environment) {
            throw new \RuntimeException("Twig Environment not found");
        }
        $txn = $this->txnRepo->findAnyByTrxId($ref);
        $mid = 0;
        if (is_array($txn) && isset($txn['merchant_id'])) {
            $midVal = $txn['merchant_id'];
            $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        }
        $brand = $mid > 0 ? $this->loadBrand($mid) : ['name' => 'OwnPay', 'logo' => '', 'color' => '#0D9488', 'support_email' => ''];

        // Retrieve dynamic currency symbols for status confirmation page.
        if (is_array($txn) && $this->c->has(\OwnPay\Service\Payment\CurrencyService::class)) {
            $currSvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
            if ($currSvc instanceof \OwnPay\Service\Payment\CurrencyService) {
                $txnCurrencyVal = $txn['currency'] ?? 'BDT';
                $txnCurrency = is_string($txnCurrencyVal) ? $txnCurrencyVal : 'BDT';
                $txn['currency_symbol'] = $currSvc->getSymbol($txnCurrency);
            }
        }

        $tplFilter = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
        $tplName = is_string($tplFilter) ? $tplFilter : 'checkout/checkout-status.twig';
        return Response::html($twig->render($tplName, [
            'txn'          => $txn ?? ['trx_id' => $ref],
            'status'       => $status ?: (is_array($txn) && is_string($txn['status'] ?? null) ? $txn['status'] : 'expired'),
            'status_label' => $this->statusLabel($status),
            'brand'        => $brand,
            'lang'         => [
                'success_msg' => (!empty($brand['checkout_success_msg']) && is_string($brand['checkout_success_msg'])) ? $brand['checkout_success_msg'] : (is_string($this->settings->get('checkout', 'checkout_success_msg', '')) ? $this->settings->get('checkout', 'checkout_success_msg', '') : (is_string($this->settings->get('general', 'checkout_success_msg', '')) ? $this->settings->get('general', 'checkout_success_msg', '') : '')),
                'pending_msg' => (!empty($brand['checkout_pending_msg']) && is_string($brand['checkout_pending_msg'])) ? $brand['checkout_pending_msg'] : (is_string($this->settings->get('checkout', 'checkout_pending_msg', '')) ? $this->settings->get('checkout', 'checkout_pending_msg', '') : (is_string($this->settings->get('general', 'checkout_pending_msg', '')) ? $this->settings->get('general', 'checkout_pending_msg', '') : '')),
                'failed_msg'  => (!empty($brand['checkout_failed_msg']) && is_string($brand['checkout_failed_msg'])) ? $brand['checkout_failed_msg'] : (is_string($this->settings->get('checkout', 'checkout_failed_msg', '')) ? $this->settings->get('checkout', 'checkout_failed_msg', '') : (is_string($this->settings->get('general', 'checkout_failed_msg', '')) ? $this->settings->get('general', 'checkout_failed_msg', '') : '')),
            ],
        ]));
    }

    /**
     * Resolves the HMAC signing key used to bind checkout payloads.
     *
     * @return string The configured HMAC key.
     * @throws \RuntimeException If neither HMAC_KEY nor APP_KEY is configured.
     */
    private function resolveHmacKey(): string
    {
        $hmacKey = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        if (!is_string($hmacKey) || $hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        return $hmacKey;
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
        $timerEnabledVal = $this->settings->get('checkout', 'timer_enabled', '1');
        $timerEnabled = is_string($timerEnabledVal) ? $timerEnabledVal : '1';
        $timerSecondsVal = $this->settings->get('checkout', 'timer_seconds', '600');
        $timerSeconds = (is_string($timerSecondsVal) && is_numeric($timerSecondsVal)) ? (int) $timerSecondsVal : 600;
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
        
        $remaining = $timerSeconds;
        $createdAtVal = $txn['created_at'] ?? '';
        $createdAtStr = is_string($createdAtVal) ? $createdAtVal : '';
        if ($createdAtStr !== '') {
            $createdAt = strtotime($createdAtStr);
            if ($createdAt !== false) {
                $elapsed = time() - $createdAt;
                $remaining = max(0, $timerSeconds - $elapsed);
            }
        }

        $txnTrxIdVal = $txn['trx_id'] ?? '';
        $txnRef = is_string($txnTrxIdVal) ? $txnTrxIdVal : '';

        return [
            'txnRef'           => $txnRef,
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

        if ($txn === null) {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Transaction expired or not found.'], 404);
            }
            return $this->renderStatus($token, 'expired');
        }

        $midVal = $txn['merchant_id'] ?? 0;
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;

        $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
            $brandCtx->setActiveBrandId($mid);
        }

        $txnTrxIdVal = $txn['trx_id'] ?? '';
        $txnTrxId = is_string($txnTrxIdVal) ? $txnTrxIdVal : '';

        $txnStatusVal = $txn['status'] ?? '';
        $txnStatus = is_string($txnStatusVal) ? $txnStatusVal : '';

        $txnAmountVal = $txn['amount'] ?? '0';
        $txnAmount = (is_string($txnAmountVal) || is_int($txnAmountVal) || is_float($txnAmountVal)) ? (string) $txnAmountVal : '0';

        $txnCurrencyVal = $txn['currency'] ?? 'BDT';
        $txnCurrency = is_string($txnCurrencyVal) ? $txnCurrencyVal : 'BDT';

        // Verify active brand status
        $merchant = $this->merchants->find($mid);
        if ($merchant === null || ($merchant['status'] ?? 'active') !== 'active') {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Merchant account is suspended.'], 403);
            }
            return $this->renderStatus($token, 'expired');
        }

        // Prevent double processing: allow only pending transactions to request capture.
        if ($txnStatus !== 'pending') {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
            }
            return $this->renderStatus($token, $txnStatus);
        }

        // Re-verify payment link availability: enforce status limits during final capture step.
        $metaRaw = $txn['metadata'] ?? '{}';
        $metaStr = is_string($metaRaw) ? $metaRaw : '{}';
        $meta = json_decode($metaStr, true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $linkIdVal = $meta['payment_link_id'] ?? null;
        if ($linkIdVal !== null && (is_int($linkIdVal) || is_string($linkIdVal))) {
            $linkId = (int) $linkIdVal;
            $linkRepo = $this->c->get(\OwnPay\Repository\PaymentLinkRepository::class);
            if ($linkRepo instanceof \OwnPay\Repository\PaymentLinkRepository) {
                $link = $linkRepo->forTenant($mid)->findScoped($linkId);
                if (is_array($link)) {
                    $linkStatusVal = $link['status'] ?? '';
                    $linkStatus = is_string($linkStatusVal) ? $linkStatusVal : '';
                    $linkExpiresAtVal = $link['expires_at'] ?? '';
                    $linkExpiresAt = is_string($linkExpiresAtVal) ? $linkExpiresAtVal : '';
                    if ($linkStatus !== 'active'
                        || ($linkExpiresAt !== '' && DateHelper::isPast($linkExpiresAt))) {
                        $this->txnRepo->cancelByTrxId($txnTrxId);
                        if ($req->isAjax()) {
                            return Response::json(['success' => false, 'error' => 'Payment link has expired.'], 410);
                        }
                        return $this->renderStatus($token, 'expired');
                    }
                } else {
                    $this->txnRepo->cancelByTrxId($txnTrxId);
                    if ($req->isAjax()) {
                        return Response::json(['success' => false, 'error' => 'Payment link has expired.'], 410);
                    }
                    return $this->renderStatus($token, 'expired');
                }
            }
        }

        // Enforce security handshake verification checking submitted HMAC against local signature.
        $submittedHashVal = $req->input('checkout_hash', '');
        $submittedHash = is_string($submittedHashVal) ? $submittedHashVal : '';
        $hmacKey = $this->resolveHmacKey();
        $expectedHash = hash_hmac('sha256', $txnAmount . '|' . $txnCurrency . '|' . $token, $hmacKey);
        if (!hash_equals($expectedHash, $submittedHash)) {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Session expired. Please refresh the page.'], 403);
            }
            return $this->renderStatus($token, 'expired');
        }

        $gatewayVal = $req->input('gateway', '');
        $gateway = is_string($gatewayVal) ? $gatewayVal : '';
        $gatewayModeVal = $req->input('gateway_mode', 'manual');
        $gatewayMode = is_string($gatewayModeVal) ? $gatewayModeVal : 'manual';

        $this->events->doAction('checkout.gateway.selected', $txn, $gateway);

        $txnIdVal = $txn['id'] ?? 0;
        $txnId = (is_int($txnIdVal) || is_string($txnIdVal)) ? (int) $txnIdVal : 0;

        if ($gatewayMode === 'manual') {
            $this->txnRepo->setGatewayAndStatus($txnId, $gateway, 'awaiting_verification', $mid);

            $details = $req->post('payment_details', []);
            if (is_array($details) && !empty($details)) {
                $this->txnRepo->updateMetadata($txnId, [
                    'payment_details' => $details,
                    'submitted_at'    => DateHelper::now(),
                ], $mid);
            }
            return Response::redirect("/checkout/{$token}/status");
        }

        // API Handshake: delegate connection requests targeting external gateways.
        if ($this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
            try {
                $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                if ($svc instanceof \OwnPay\Service\Payment\GatewayApiService) {
                    // Multi-brand white-labeling: build callback target endpoints utilizing the active custom domain.
                    $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
                    if ($urlService instanceof \OwnPay\Service\Domain\DomainUrlService) {
                        $callbackUrl = $urlService->buildLegacyCallbackUrl($mid, $token, $req);

                        $result = $svc->initiatePayment($mid, $gateway, [
                            'amount'       => $txnAmount,
                            'currency'     => $txnCurrency,
                            'trx_id'       => $txnTrxId,
                            'redirect_url' => $callbackUrl,
                            'cancel_url'   => $callbackUrl,
                            'existing_txn' => true,
                        ]);

                        if ($result['success'] && !empty($result['redirect_url'])) {
                            $this->txnRepo->setGatewayAndStatus(
                                $txnId, $gateway, 'processing', $mid
                            );

                            // Dispatch ajax redirects for asynchronous capture handlers.
                            if ($req->isAjax()) {
                                return Response::json([
                                    'success'      => true,
                                    'redirect_url' => (string) $result['redirect_url'],
                                ]);
                            }
                            return Response::redirect((string) $result['redirect_url']);
                        } elseif ($result['success'] && !empty($result['form_html'])) {
                            $this->txnRepo->setGatewayAndStatus(
                                $txnId, $gateway, 'processing', $mid
                            );

                            if ($req->isAjax()) {
                                return Response::json([
                                    'success'   => true,
                                    'form_html' => (string) $result['form_html'],
                                ]);
                            }
                            return Response::html((string) $result['form_html']);
                        }

                        $errorMsg = $result['error'] ?? 'Gateway returned no redirect URL';
                        $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                        if ($logger instanceof \OwnPay\Service\System\Logger) {
                            $logger->warning(
                                "Gateway {$gateway} failed for trx {$txnTrxId}: {$errorMsg}"
                            );
                        }

                        if ($req->isAjax()) {
                            return Response::json([
                                'success' => false,
                                'error'   => $errorMsg,
                            ], 422);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->error(
                        "Gateway {$gateway} initiation failed: " . $e->getMessage()
                    );
                }

                if ($req->isAjax()) {
                    return Response::json([
                        'success' => false,
                        'error'   => 'The payment gateway could not process your request. Please try again or choose another method.',
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

        if ($txn === null) {
            return $this->renderStatus($token, 'cancelled');
        }

        $midVal = $txn['merchant_id'] ?? 0;
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
            $brandCtx->setActiveBrandId($mid);
        }

        // Authenticate cancellation requests: verify HMAC token against registered keys.
        $submittedHashVal = $req->input('checkout_hash', '');
        $submittedHash = is_string($submittedHashVal) ? $submittedHashVal : '';
        if (empty($submittedHash)) {
            return $this->renderStatus($token, 'expired');
        }
        $hmacKey = $this->resolveHmacKey();
        $txnAmountVal = $txn['amount'] ?? '0';
        $txnAmount = (is_string($txnAmountVal) || is_int($txnAmountVal) || is_float($txnAmountVal)) ? (string) $txnAmountVal : '0';
        $txnCurrencyVal = $txn['currency'] ?? 'BDT';
        $txnCurrency = is_string($txnCurrencyVal) ? $txnCurrencyVal : 'BDT';

        $expectedHash = hash_hmac('sha256', $txnAmount . '|' . $txnCurrency . '|' . $token, $hmacKey);
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
        
        $status = 'expired';
        if (is_array($txn) && is_string($txn['status'] ?? null)) {
            $status = $txn['status'];
        }

        $mid = 0;
        if (is_array($txn) && isset($txn['merchant_id'])) {
            $midVal = $txn['merchant_id'];
            $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->setActiveBrandId($mid);
            }
        }

        // No payment event has occurred yet (txn still on the gateway-selection step): send the
        // customer back to the checkout page rather than a misleading "pending" status screen. The
        // callback-capture block below only runs for 'processing', so this never blocks a callback.
        if (is_array($txn) && in_array($status, ['pending', 'created'], true)) {
            return Response::redirect("/checkout/{$token}");
        }

        // Redirect callback loop: execute final capture steps when external providers redirect.
        $callbackPaymentIdVal = $req->query('paymentID') ?? $req->query('payment_id') ?? '';
        $callbackPaymentId = is_string($callbackPaymentIdVal) ? $callbackPaymentIdVal : '';
        $callbackStatusVal = $req->query('status') ?? '';
        $callbackStatus = is_string($callbackStatusVal) ? $callbackStatusVal : '';

        if ($callbackPaymentId !== '' && is_array($txn) && ($txn['status'] ?? '') === 'processing') {
            $txnIdVal = $txn['id'] ?? 0;
            $txnId = (is_int($txnIdVal) || is_string($txnIdVal)) ? (int) $txnIdVal : 0;
            
            $db = \OwnPay\Core\Database::getInstance();
            // Acquire database-level transaction status lock to prevent concurrent double-capture callback processing.
            $claimed = $db->update(
                "UPDATE op_transactions SET status = 'callback_processing' WHERE id = :id AND merchant_id = :mid AND status = 'processing'",
                ['id' => $txnId, 'mid' => $mid]
            );
            if ($claimed === 0) {
                // Already being processed or completed - skip duplicate callback
                return $this->renderStatus($token, $status);
            }

            $gatewayVal = $txn['gateway_slug'] ?? '';
            $gateway = is_string($gatewayVal) ? $gatewayVal : '';
            $leaseReleased = false;

            if ($gateway !== '' && $this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
                try {
                    $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                    if ($svc instanceof \OwnPay\Service\Payment\GatewayApiService) {
                        $queryData = $req->query();
                        $callbackData = array_merge(is_array($queryData) ? $queryData : [], [
                            'paymentID' => $callbackPaymentId,
                            'trx_id'    => is_string($txn['trx_id'] ?? null) ? $txn['trx_id'] : '',
                        ]);

                        $result = $svc->handleCallback($mid, $gateway, $callbackData);
                        if ($result['success']) {
                            $status = 'completed';
                            $leaseReleased = true;
                        } elseif (in_array($callbackStatus, ['cancel', 'failure', 'failed'], true)) {
                            $this->txnRepo->setGatewayAndStatus($txnId, $gateway, 'failed', $mid);
                            $status = 'failed';
                            $leaseReleased = true;
                        }
                    }
                } catch (\Throwable $e) {
                    if ($this->c->has(\OwnPay\Service\System\Logger::class)) {
                        $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                        if ($logger instanceof \OwnPay\Service\System\Logger) {
                            $logger->error(
                                "Gateway callback execution failed for {$token}: " . $e->getMessage()
                            );
                        }
                    }
                }
            }

            // Release the callback_processing lease whenever no terminal state was
            // reached, so a later callback retry or webhook can still complete the
            // payment. Guarded on the lease status so a completion that landed
            // inside handleCallback is never clobbered back to 'processing'.
            if (!$leaseReleased) {
                $db->update(
                    "UPDATE op_transactions SET status = 'processing' WHERE id = :id AND merchant_id = :mid AND status = 'callback_processing'",
                    ['id' => $txnId, 'mid' => $mid]
                );
                $status = 'processing';
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

        if ($txn === null) {
            return $this->renderStatus($token, 'expired');
        }

        $verifyData = [
            'sender_number'  => $req->input('sender_number', ''),
            'transaction_id' => $req->input('transaction_id', ''),
            'submitted_at'   => DateHelper::now(),
        ];

        $existingMetaRaw = $txn['metadata'] ?? '{}';
        $existingMetaStr = is_string($existingMetaRaw) ? $existingMetaRaw : '{}';
        $existingMeta = json_decode($existingMetaStr, true);
        if (!is_array($existingMeta)) {
            $existingMeta = [];
        }
        $existingMeta['verification'] = $verifyData;

        $txnIdVal = $txn['id'] ?? 0;
        $txnId = (is_int($txnIdVal) || is_string($txnIdVal)) ? (int) $txnIdVal : 0;
        $merchantIdVal = $txn['merchant_id'] ?? 0;
        $merchantId = (is_int($merchantIdVal) || is_string($merchantIdVal)) ? (int) $merchantIdVal : 0;

        $this->txnRepo->setStatusWithMeta($txnId, 'pending_review', $existingMeta, $merchantId);

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

        if ($txn === null) {
            return Response::json(['success' => false, 'error' => 'Transaction expired or not found.'], 404);
        }

        $txnStatusVal = $txn['status'] ?? '';
        $txnStatus = is_string($txnStatusVal) ? $txnStatusVal : '';

        $txnAmountVal = $txn['amount'] ?? '0';
        $txnAmount = (is_string($txnAmountVal) || is_int($txnAmountVal) || is_float($txnAmountVal)) ? (string) $txnAmountVal : '0';

        $txnCurrencyVal = $txn['currency'] ?? 'BDT';
        $txnCurrency = is_string($txnCurrencyVal) ? $txnCurrencyVal : 'BDT';

        $txnTrxIdVal = $txn['trx_id'] ?? '';
        $txnTrxId = is_string($txnTrxIdVal) ? $txnTrxIdVal : '';

        $txnIdVal = $txn['id'] ?? 0;
        $txnId = (is_int($txnIdVal) || is_string($txnIdVal)) ? (int) $txnIdVal : 0;

        if ($txnStatus !== 'pending') {
            return Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
        }

        // Re-verify payment link availability: enforce status limits during final capture step.
        $metaRaw = $txn['metadata'] ?? '{}';
        $metaStr = is_string($metaRaw) ? $metaRaw : '{}';
        $meta = json_decode($metaStr, true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $linkIdVal = $meta['payment_link_id'] ?? null;
        if ($linkIdVal !== null && (is_int($linkIdVal) || is_string($linkIdVal))) {
            $linkId = (int) $linkIdVal;
            $linkRepo = $this->c->get(\OwnPay\Repository\PaymentLinkRepository::class);
            if ($linkRepo instanceof \OwnPay\Repository\PaymentLinkRepository) {
                $txnMerchantId = $txn['merchant_id'] ?? 0;
                $midInt = (is_int($txnMerchantId) || is_string($txnMerchantId)) ? (int) $txnMerchantId : 0;
                $link = $linkRepo->forTenant($midInt)->findScoped($linkId);
                if (is_array($link)) {
                    $linkStatusVal = $link['status'] ?? '';
                    $linkStatus = is_string($linkStatusVal) ? $linkStatusVal : '';
                    $linkExpiresAtVal = $link['expires_at'] ?? '';
                    $linkExpiresAt = is_string($linkExpiresAtVal) ? $linkExpiresAtVal : '';
                    if ($linkStatus !== 'active'
                        || ($linkExpiresAt !== '' && DateHelper::isPast($linkExpiresAt))) {
                        $this->txnRepo->cancelByTrxId($txnTrxId);
                        return Response::json(['success' => false, 'error' => 'Payment link has expired.'], 410);
                    }
                } else {
                    $this->txnRepo->cancelByTrxId($txnTrxId);
                    return Response::json(['success' => false, 'error' => 'Payment link has expired.'], 410);
                }
            }
        }

        // Enforce security handshake verification checking submitted HMAC against local signature.
        $submittedHashVal = $req->input('checkout_hash', '');
        $submittedHash = is_string($submittedHashVal) ? $submittedHashVal : '';
        $hmacKey = $this->resolveHmacKey();
        $expectedHash = hash_hmac('sha256', $txnAmount . '|' . $txnCurrency . '|' . $token, $hmacKey);
        if (!hash_equals($expectedHash, $submittedHash)) {
            return Response::json(['success' => false, 'error' => 'Session expired. Please refresh the page.'], 403);
        }

        $providerVal = $req->input('provider', '');
        $provider = is_string($providerVal) ? $providerVal : '';
        $gatewaySlug = '';
        if (in_array($provider, ['apple-pay', 'Apple Pay'], true)) {
            $gatewaySlug = 'apple-pay';
        } elseif (in_array($provider, ['google-pay', 'Google Pay'], true)) {
            $gatewaySlug = 'google-pay';
        } else {
            return Response::json(['success' => false, 'error' => 'Invalid express provider.'], 400);
        }

        $midVal = $txn['merchant_id'] ?? 0;
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;

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
                if ($svc instanceof \OwnPay\Service\Payment\GatewayApiService) {
                    $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
                    if ($urlService instanceof \OwnPay\Service\Domain\DomainUrlService) {
                        $callbackUrl = $urlService->buildLegacyCallbackUrl($mid, $token, $req);

                        $result = $svc->initiatePayment($mid, $gatewaySlug, [
                            'amount'       => $txnAmount,
                            'currency'     => $txnCurrency,
                            'trx_id'       => $txnTrxId,
                            'redirect_url' => $callbackUrl,
                            'cancel_url'   => $callbackUrl,
                            'existing_txn' => true,
                        ]);

                        if ($result['success'] && !empty($result['redirect_url'])) {
                            $this->txnRepo->setGatewayAndStatus(
                                $txnId, $gatewaySlug, 'processing', $mid
                            );

                            return Response::json([
                                'success'      => true,
                                'redirect_url' => (string) $result['redirect_url'],
                            ]);
                        }

                        $errorMsg = $result['error'] ?? 'Gateway returned no redirect URL';
                        $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                        if ($logger instanceof \OwnPay\Service\System\Logger) {
                            $logger->warning(
                                "Express Gateway {$gatewaySlug} failed for trx {$txnTrxId}: {$errorMsg}"
                            );
                        }

                        return Response::json([
                            'success' => false,
                            'error'   => $errorMsg,
                        ], 422);
                    }
                }
            } catch (\Throwable $e) {
                $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->error(
                        "Express Gateway {$gatewaySlug} initiation failed: " . $e->getMessage()
                    );
                }

                return Response::json([
                    'success' => false,
                    'error'   => 'The payment gateway could not process your request. Please try again or choose another method.',
                ], 422);
            }
        }

        return Response::json([
            'success' => false,
            'error'   => 'Payment service is not configured.',
        ], 500);
    }
}
