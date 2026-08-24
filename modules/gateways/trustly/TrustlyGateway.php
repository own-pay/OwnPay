<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Trustly;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Model\WebhookPayload;
use OwnPay\Service\Payment\TransactionService;

/**
 * Trustly Payment Gateway Adapter.
 */
final class TrustlyGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    private ?Container $container = null;

    public static function metadata(): array
    {
        return [
            'name'        => 'Trustly',
            'slug'        => 'trustly',
            'version'     => '1.0.0',
            'description' => 'Trustly bank payment gateway integration for OwnPay',
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
        return 'trustly';
    }

    public function register(EventManager $events, Container $container): void
    {
        $events->addAction('webhook.incoming.trustly', [$this, 'handleWebhook']);
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
            ['name' => 'username', 'label' => 'API Username', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'API Password', 'type' => 'password', 'required' => true],
            ['name' => 'private_key', 'label' => 'Merchant Private Key (PEM)', 'type' => 'textarea', 'required' => false],
            ['name' => 'mode', 'label' => 'Sandbox Mode', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox Simulation UAT', 'live' => 'Production Live Environment'], 'required' => true]
        ];
    }

    public function supportedCurrencies(): array
    {
        return ['EUR', 'SEK', 'NOK', 'DKK', 'GBP', 'PLN'];
    }

    /**
     * Verifies username/password/private key authenticate against Trustly's real JSON-RPC API,
     * using the exact same 'Deposit' method and signing (signData()) initiate() already uses -
     * Trustly has no separate read-only ping method, so this creates a minimal, never-followed
     * deposit request purely to exercise credential/signature validation. No money moves; the
     * request is never completed by any customer.
     *
     * @param array<string, mixed> $credentials Decrypted (or freshly-submitted, unsaved) credentials.
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $username = $this->getString($credentials['username'] ?? '');
        $password = $this->getString($credentials['password'] ?? '');
        if ($username === '' || $password === '') {
            return ['success' => false, 'message' => 'Enter an API Username and API Password before testing the connection.'];
        }
        $privateKey = $this->getString($credentials['private_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        $endpoint = $mode === 'live' ? 'https://api.trustly.com/api/1' : 'https://test.trustly.com/api/1';

        $uuid = 'op_connection_test_' . bin2hex(random_bytes(6));
        $data = [
            'Username'      => $username,
            'Password'      => $password,
            'EndMerchantID' => $username,
            'MessageID'     => $uuid,
            'Attributes'    => ['Currency' => 'EUR', 'Amount' => '1.00', 'Locale' => 'en_US'],
        ];
        $signature = $this->signData($data, $privateKey);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'method'  => 'Deposit',
                'uuid'    => $uuid,
                'version' => '1.1',
                'params'  => ['Signature' => $signature, 'UUID' => $uuid, 'Data' => $data],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach Trustly - check the server\'s network connectivity.'];
        }

        $data2 = json_decode((string) $response, true);
        if (is_array($data2) && isset($data2['error'])) {
            $errMsg = is_array($data2['error']) && is_scalar($data2['error']['message'] ?? null)
                ? (string) $data2['error']['message']
                : 'Trustly rejected the provided credentials.';
            return ['success' => false, 'message' => $errMsg];
        }
        if (is_array($data2) && isset($data2['result'])) {
            return ['success' => true, 'message' => "Connected successfully to Trustly ({$mode} mode)."];
        }

        return ['success' => false, 'message' => 'Trustly returned an unexpected response.'];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        $endpoint = $mode === 'live'
            ? 'https://api.trustly.com/api/1'
            : 'https://test.trustly.com/api/1';

        $username = $this->getString($credentials['username'] ?? '');
        $password = $this->getString($credentials['password'] ?? '');
        $privateKey = $this->getString($credentials['private_key'] ?? '');

        $uuid = $this->getString($params['trx_id']);

        $data = [
            'Username'        => $username,
            'Password'        => $password,
            'NotificationURL' => $params['redirect_url'],
            'EndMerchantID'   => $username,
            'MessageID'       => $uuid,
            'Attributes'      => [
                'Currency' => $params['currency'],
                'Amount'   => $this->getString($params['amount']),
                'Locale'   => 'en_US',
            ],
        ];

        // Generate JSON-RPC 2.0 structure
        $signature = $this->signData($data, $privateKey);

        $payload = [
            'method'  => 'Deposit',
            'uuid'    => $uuid,
            'version' => '1.1',
            'params'  => [
                'Signature' => $signature,
                'UUID'      => $uuid,
                'Data'      => $data,
            ],
        ];

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['form_html' => '<div class="op-alert op-alert-danger">Failed to initialize Trustly stream.</div>'];
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
                throw new \RuntimeException('Trustly payment initiation failed.');
            }
            // Emulate fallback visual window for simulated checkout
            return [
                'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()
            ];
        }

        $resData = json_decode((string) $response, true);
        if (is_array($resData)) {
            $result = $this->getArray($resData, 'result');
            $resDetails = $this->getArray($result, 'data');
            if (isset($resDetails['url']) && is_scalar($resDetails['url'])) {
                return [
                    'redirect_url' => $this->getString($resDetails['url']),
                    'session_id'   => $this->getString($resDetails['orderid'] ?? ''),
                ];
            }
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
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

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

        return [
            'success'        => true,
            'gateway_trx_id' => $gatewayTrxId,
            'amount'         => $this->getString($callbackData['amount'] ?? '0.00'),
            'status'         => 'completed',
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
    }

    public function handleWebhook(WebhookPayload $payload): void
    {
        if ($this->container === null) {
            return;
        }

        $data = $payload->json();
        $paramsData = $this->getArray($data, 'params');
        $innerData = $this->getArray($paramsData, 'Data');
        
        $reference = $this->getString($innerData['MessageID'] ?? null);
        $gatewayTrxId = $this->getString($innerData['orderid'] ?? 'TR_WEBHOOK');

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

    /**
     * Helper to sign Trustly API data.
     *
     * @param array<string, mixed> $data
     * @param string $privateKeyPem
     * @return string
     */
    private function signData(array $data, string $privateKeyPem): string
    {
        if ($privateKeyPem === '') {
            return 'SIM_SIGNATURE_' . uniqid();
        }

        $serialized = $this->serializeData($data);
        $signature = '';
        
        $res = openssl_get_privatekey($privateKeyPem);
        if ($res !== false) {
            openssl_sign($serialized, $signature, $res, OPENSSL_ALGO_SHA256);
            openssl_free_key($res);
        }

        return base64_encode($signature);
    }

    /**
     * Serialize array data for Trustly signing.
     */
    private function serializeData(mixed $data): string
    {
        if (is_array($data)) {
            ksort($data);
            $serialized = '';
            foreach ($data as $key => $value) {
                $serialized .= $key . $this->serializeData($value);
            }
            return $serialized;
        }

        return $this->getString($data);
    }
}
