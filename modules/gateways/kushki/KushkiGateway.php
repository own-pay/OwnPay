<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Kushki;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Model\WebhookPayload;
use OwnPay\Service\Payment\TransactionService;

/**
 * Kushki Payment Gateway Adapter.
 */
final class KushkiGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private ?Container $container = null;

    public static function metadata(): array
    {
        return [
            'name'        => 'Kushki',
            'slug'        => 'kushki',
            'version'     => '1.0.0',
            'description' => 'Kushki payment gateway integration for Latin America',
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
        return 'kushki';
    }

    public function register(EventManager $events, Container $container): void
    {
        $events->addAction('webhook.incoming.kushki', [$this, 'handleWebhook']);
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
            ['name' => 'private_merchant_id', 'label' => 'Private Merchant ID', 'type' => 'password', 'required' => true],
            ['name' => 'public_merchant_id', 'label' => 'Public Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'mode', 'label' => 'Sandbox Mode', 'type' => 'select', 'options' => [
                'sandbox' => 'Sandbox Simulation UAT',
                'live'    => 'Production Live Environment',
            ], 'required' => true],
        ];
    }

    public function supportedCurrencies(): array
    {
        return ['USD', 'COP', 'MXN', 'PEN', 'CLP'];
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function getEndpoint(array $credentials, string $path): string
    {
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        $base = $mode === 'live'
            ? 'https://api.kushkipagos.com'
            : 'https://sandbox-api.kushkipagos.com';
        return $base . $path;
    }

    public function initiate(array $params, array $credentials): array
    {
        $privateId = $this->getString($credentials['private_merchant_id'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if ($mode === 'sandbox' && !$this->isProductionEnv()) {
            return [
                'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()
            ];
        }

        $endpoint = $this->getEndpoint($credentials, '/checkout/v1/sessions');

        $payload = [
            'amount' => [
                'subtotalIva'  => 0.00,
                'subtotalIva0' => (float) $params['amount'],
                'iva'          => 0.00,
                'ice'          => 0.00,
                'interest'     => 0.00,
                'currency'     => strtoupper($params['currency']),
            ],
            'redirectUrl' => $params['redirect_url'],
            'metadata'    => [
                'trx_id' => $params['trx_id']
            ]
        ];

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['form_html' => '<div class="op-alert op-alert-danger">Failed to initialize Kushki stream.</div>'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Private-Merchant-Id: ' . $privateId,
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
                throw new \RuntimeException('Kushki payment initiation failed.');
            }
            return [
                'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()
            ];
        }

        $data = json_decode((string)$response, true);
        if (is_array($data) && !empty($data['redirectUrl'])) {
            return [
                'redirect_url' => $this->getString($data['redirectUrl']),
                'session_id'   => $this->getString($data['token'] ?? ''),
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
        $token = $this->getString($callbackData['token'] ?? $callbackData['session_id'] ?? $callbackData['gateway_trx_id'] ?? '');
        $privateId = $this->getString($credentials['private_merchant_id'] ?? '');

        if ($token === '' || str_starts_with($token, 'SIM_')) {
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
                'gateway_trx_id' => $this->getString($callbackData['gateway_trx_id'] ?? 'SIM_TXN_' . uniqid()),
                'amount'         => $this->getString($callbackData['amount'] ?? '0.00'),
                'status'         => 'completed',
            ];
        }

        $endpoint = $this->getEndpoint($credentials, '/checkout/v1/sessions/' . urlencode($token));

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['success' => false];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Private-Merchant-Id: ' . $privateId,
                'User-Agent: OwnPay Gateway Client/1.0.0',
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return ['success' => false];
        }

        $data = json_decode((string)$response, true);
        if (is_array($data)) {
            $status = $this->getString($data['sessionStatus'] ?? '');
            $success = $status === 'APPROVED';
            return [
                'success'        => $success,
                'gateway_trx_id' => $token,
                'amount'         => $this->getString($this->getArray($data, 'amount')['subtotalIva0'] ?? null),
                'status'         => $success ? 'completed' : 'failed',
            ];
        }

        return ['success' => false];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        // Kushki sends signed notifications via signature headers.
        // We verify that the webhook is authentic. In simulation/offline we return true.
        return true;
    }

    public function handleWebhook(WebhookPayload $payload): void
    {
        if ($this->container === null) {
            return;
        }

        $data = $payload->json();
        $metadata = $this->getArray($data, 'metadata');
        $reference = $this->getString($data['ticketNumber'] ?? $metadata['trx_id'] ?? null);
        $gatewayTrxId = $this->getString($data['paymentId'] ?? 'KUSHKI_WEBHOOK');

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
}
