<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Giropay;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Model\WebhookPayload;
use OwnPay\Service\Payment\TransactionService;

/**
 * Giropay Payment Gateway Adapter.
 */
final class GiropayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private ?Container $container = null;

    public static function metadata(): array
    {
        return [
            'name'        => 'Giropay',
            'slug'        => 'giropay',
            'version'     => '1.0.0',
            'description' => 'Giropay payment gateway integration for OwnPay',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function capabilities(): array
    {
        return [
            Capability::GATEWAY,
            Capability::HTTP_OUTBOUND,
            Capability::HOOKS,
        ];
    }

    public function slug(): string
    {
        return 'giropay';
    }

    public function register(EventManager $events, Container $container): void
    {
        $events->addAction('webhook.incoming.giropay', [$this, 'handleWebhook']);
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
    }

    public function deactivate(Container $container): void
    {
    }

    public function uninstall(Container $container): void
    {
    }

    public function fields(): array
    {
        return [
            ['name' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'project_id', 'label' => 'Project ID', 'type' => 'text', 'required' => true],
            ['name' => 'project_password', 'label' => 'Project Password/Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Sandbox Mode', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox Simulation UAT', 'live' => 'Production Live Environment'], 'required' => true]
        ];
    }

    public function supportedCurrencies(): array
    {
        return ['EUR'];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        $endpoint = 'https://api.giropay.de/v1/transaction/start';

        $merchantId = $this->getString($credentials['merchant_id'] ?? '');
        $projectId = $this->getString($credentials['project_id'] ?? '');
        $projectPassword = $this->getString($credentials['project_password'] ?? '');

        // Amount in cents for Giropay
        $amountCents = $this->toMinorUnits($params['amount']);

        $payload = [
            'merchantId'  => $merchantId,
            'projectId'   => $projectId,
            'amount'      => $amountCents,
            'currency'    => 'EUR',
            'purpose'     => $params['trx_id'],
            'urlSuccess'  => $params['redirect_url'],
            'urlFailure'  => $params['cancel_url'],
        ];

        // Generate SHA256 signature for Giropay request validation
        $signatureString = $merchantId . $projectId . $amountCents . 'EUR' . $params['trx_id'] . $projectPassword;
        $hash = hash('sha256', $signatureString);
        $payload['hash'] = $hash;

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['form_html' => '<div class="op-alert op-alert-danger">Failed to initialize payment stream.</div>'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: OwnPay Gateway Client/1.0.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $mode = $this->getString($credentials['mode'] ?? 'sandbox');
            if ($mode === 'live') {
                throw new \RuntimeException('Giropay payment initiation failed.');
            }
            // Emulate fallback visual window for simulated checkout
            return [
                'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()
            ];
        }

        $data = json_decode((string) $response, true);
        if (is_array($data) && !empty($data['redirectUrl'])) {
            return [
                'redirect_url' => $this->getString($data['redirectUrl']),
                'session_id'   => $this->getString($data['reference'] ?? ''),
            ];
        }

        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        if ($mode === 'live') {
            throw new \RuntimeException('Payment initiation failed');
        }
        return [
            'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        $gatewayTrxId = $this->getString($callbackData['gateway_trx_id'] ?? null);

        if (!$gatewayTrxId) {
            return ['success' => false];
        }

        if (str_starts_with($gatewayTrxId, 'SIM_')) {
            $mode = $this->getString($credentials['mode'] ?? 'sandbox');
            if ($mode === 'live') {
                return [
                    'success'        => false,
                    'gateway_trx_id' => '',
                    'status'         => 'failed',
                ];
            }
            return [
                'success'        => true,
                'gateway_trx_id' => $gatewayTrxId,
                'amount'         => $this->getString($callbackData['amount'] ?? '0.00'),
                'status'         => 'completed',
            ];
        }

        $merchantId = $this->getString($credentials['merchant_id'] ?? '');
        $projectId = $this->getString($credentials['project_id'] ?? '');
        $projectPassword = $this->getString($credentials['project_password'] ?? '');

        $endpoint = 'https://api.giropay.de/v1/transaction/status';

        $payload = [
            'merchantId' => $merchantId,
            'projectId'  => $projectId,
            'reference'  => $gatewayTrxId,
        ];

        $signatureString = $merchantId . $projectId . $gatewayTrxId . $projectPassword;
        $payload['hash'] = hash('sha256', $signatureString);

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['success' => false];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: OwnPay Gateway Client/1.0.0',
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return ['success' => false];
        }

        $data = json_decode((string) $response, true);
        if (is_array($data) && ($data['status'] ?? '') === 'SUCCESS') {
            $amountCents = $this->getInt($data['amount'] ?? 0);
            $amountFloat = (float) bcdiv((string) $amountCents, '100', 2);
            return [
                'success'        => true,
                'gateway_trx_id' => $gatewayTrxId,
                'amount'         => (string) $amountFloat,
                'status'         => 'completed',
            ];
        }

        return ['success' => false];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return true;
    }

    public function handleWebhook(WebhookPayload $payload): void
    {
        if ($this->container === null) {
            return;
        }

        $data = $payload->json();
        $reference = $this->getString($data['purpose'] ?? null);
        $gatewayTrxId = $this->getString($data['reference'] ?? 'GP_WEBHOOK');

        if ($reference !== '') {
            /** @var \OwnPay\Repository\TransactionRepository $trxRepo */
            $trxRepo = $this->container->get(\OwnPay\Repository\TransactionRepository::class);
            $scopedRepo = $trxRepo->forTenant($payload->merchantId);
            $trx = $scopedRepo->findByTrxId($reference);

            if ($trx && ($trx['status'] ?? '') === 'pending') {
                $trxId = $this->getInt($trx['id'] ?? 0);
                if ($trxId > 0) {
                    $scopedRepo->updateScoped($trxId, ['gateway_trx_id' => $gatewayTrxId]);
                    /** @var \OwnPay\Service\Payment\TransactionService $trxService */
                    $trxService = $this->container->get(\OwnPay\Service\Payment\TransactionService::class);
                    $trxService->complete($trxId, $payload->merchantId);
                }
            }
        }
    }

    public function supports(string $feature): bool
    {
        return $feature === 'refund';
    }

    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        // Automated refunds are not implemented for this gateway; the simulated
        // success below is for local testing only. In production fail closed so a
        // refund is never marked complete (and the ledger credited) without the
        // money actually being returned at the provider.
        if ($this->isProductionEnv()) {
            return ['success' => false, 'error' => 'Automated refunds are unavailable for this gateway; process it in the provider dashboard.'];
        }

        return [
            'success'   => true,
            'refund_id' => 'REF_' . $this->slug() . '_' . uniqid(),
        ];
    }
}
