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

        $mid = (int) $txn['merchant_id'];
        $this->events->doAction('checkout.before', $txn);

        // Load gateways via repos
        $manualGateways = $this->manualGw->forTenant($mid)->listActive();
        $apiGateways = $this->apiGw->forTenant($mid)->listActive();

        // Categorize
        $gateways = ['mfs' => [], 'bank' => [], 'global' => []];
        foreach ($manualGateways as $gw) {
            $cat = $gw['category'] ?? 'mfs';
            $gateways[$cat][] = array_merge($gw, ['mode' => 'manual']);
        }
        foreach ($apiGateways as $gw) {
            $cat = $gw['type'] ?? 'global';
            $gateways[$cat][] = array_merge($gw, ['mode' => 'api']);
        }

        // Brand + settings
        $brand = $this->loadBrand($mid);
        $faqs = json_decode($this->settings->get('general', 'faqs', '[]'), true);

        // Invoice items
        $items = [];
        if (!empty($txn['invoice_id'])) {
            $invoiceRepo = $this->c->get(\OwnPay\Repository\InvoiceRepository::class);
            $items = $invoiceRepo->listItems($txn['invoice_id']);
        }

        $data = [
            'txn'      => $txn,
            'gateways' => $gateways,
            'brand'    => $brand,
            'items'    => $items,
            'faqs'     => $faqs,
            'config'   => $this->buildJsConfig($txn, $brand),
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
        $tplName = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
        return Response::html($twig->render($tplName, [
            'txn'    => $txn ?? ['trx_id' => $ref],
            'status' => $status ?: ($txn['status'] ?? 'expired'),
        ]));
    }

    private function loadBrand(int $mid): array
    {
        $merchant = $this->merchants->find($mid);
        $s = $this->settings->getGroup('general');
        return [
            'name'          => $merchant['name'] ?? $s['app_name'] ?? 'Own Pay',
            'logo'          => $merchant['logo'] ?? '',
            'color'         => $s['theme_primary'] ?? '#0D9488',
            'support_email' => $s['support_email'] ?? '',
        ];
    }

    private function buildJsConfig(array $txn, array $brand): array
    {
        return [
            'txnRef'         => $txn['trx_id'],
            'timeoutEnabled' => true,
            'timeoutSeconds' => 600,
            'gatewayMeta'    => [
                'bkash'  => ['color' => '#E2136E', 'type' => 'Send Money', 'logoText' => 'b'],
                'nagad'  => ['color' => '#F6921E', 'type' => 'Send Money', 'logoText' => 'N'],
                'rocket' => ['color' => '#8B2E86', 'type' => 'Send Money', 'logoText' => 'R'],
            ],
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
            return $this->renderStatus($token, 'expired');
        }

        $gateway = $req->post('gateway', '');
        $gatewayMode = $req->post('gateway_mode', 'manual');

        $this->events->doAction('checkout.gateway.selected', $txn, $gateway);

        if ($gatewayMode === 'manual') {
            $this->txnRepo->setGatewayAndStatus((int) $txn['id'], $gateway, 'awaiting_verification');

            $details = $req->post('payment_details', []);
            if (!empty($details)) {
                $this->txnRepo->updateMetadata((int) $txn['id'], [
                    'payment_details' => $details,
                    'submitted_at'    => DateHelper::now(),
                ]);
            }
            return Response::redirect("/checkout/{$token}/status");
        }

        // API gateway — delegate to GatewayApiService
        if ($this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
            try {
                $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                $result = $svc->initiatePayment((int) $txn['merchant_id'], $gateway, [
                    'amount'   => $txn['amount'],
                    'currency' => $txn['currency'],
                    'trx_id'   => $txn['trx_id'],
                ]);
                if ($result['success'] && !empty($result['redirect_url'])) {
                    return Response::redirect($result['redirect_url']);
                }
            } catch (\Throwable $e) {
                $this->c->get(\OwnPay\Service\System\Logger::class)->error('Gateway error: ' . $e->getMessage());
            }
        }

        return Response::redirect("/checkout/{$token}/status");
    }

    /**
     * POST /checkout/{token}/cancel
     */
    public function cancel(Request $req): Response
    {
        $token = (string) $req->param('token');
        $this->txnRepo->cancelByTrxId($token);
        $this->events->doAction('checkout.cancelled', $token);
        return $this->renderStatus($token, 'cancelled');
    }

    /**
     * GET /checkout/{token}/status
     */
    public function status(Request $req): Response
    {
        $token = (string) $req->param('token');
        $txn = $this->txnRepo->findAnyByTrxId($token);
        $status = $txn['status'] ?? 'expired';
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
            'sender_number'  => $req->post('sender_number', ''),
            'transaction_id' => $req->post('transaction_id', ''),
            'submitted_at'   => DateHelper::now(),
        ];

        $existingMeta = json_decode($txn['metadata'] ?? '{}', true);
        $existingMeta['verification'] = $verifyData;

        $this->txnRepo->setStatusWithMeta((int) $txn['id'], 'pending_review', $existingMeta);

        $this->events->doAction('checkout.manual_verify.submitted', $txn, $verifyData);

        return $this->renderStatus($token, 'pending_review');
    }
}
