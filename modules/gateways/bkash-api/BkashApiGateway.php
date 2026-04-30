<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\BkashApi;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;

/**
 * bKash API gateway — tokenized checkout flow.
 */
final class BkashApiGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://tokenized.sandbox.bka.sh/v1.2.0-beta';
    private const LIVE_URL    = 'https://tokenized.pay.bka.sh/v1.2.0-beta';

    public function slug(): string { return 'bkash-api'; }
    public function name(): string { return 'bKash API'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'bKash tokenized checkout API integration'; }

    public function boot(): void {}
    public function activate(): void {}
    public function deactivate(): void {}
    public function uninstall(): void {}

    public function capabilities(): array
    {
        return [\OwnPay\Plugin\Capability::GATEWAY];
    }

    public function fields(): array
    {
        return [
            ['name' => 'app_key', 'label' => 'App Key', 'type' => 'text', 'required' => true],
            ['name' => 'app_secret', 'label' => 'App Secret', 'type' => 'password', 'required' => true],
            ['name' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox', 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $baseUrl = ($credentials['mode'] ?? 'sandbox') === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $token = $this->getToken($baseUrl, $credentials);

        $ch = curl_init($baseUrl . '/tokenized/checkout/create');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . $token,
                'X-APP-Key: ' . ($credentials['app_key'] ?? ''),
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'mode'                => '0011',
                'payerReference'      => $params['trx_id'] ?? '',
                'callbackURL'         => $params['redirect_url'] ?? '',
                'amount'              => $params['amount'],
                'currency'            => 'BDT',
                'intent'              => 'sale',
                'merchantInvoiceNumber' => $params['trx_id'] ?? '',
            ]),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);

        if (empty($data['bkashURL'])) {
            throw new \RuntimeException('bKash error: ' . ($data['statusMessage'] ?? 'Unknown'));
        }

        return [
            'redirect_url' => $data['bkashURL'],
            'session_id'   => $data['paymentID'] ?? null,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $baseUrl = ($credentials['mode'] ?? 'sandbox') === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $token = $this->getToken($baseUrl, $credentials);
        $paymentId = $callbackData['paymentID'] ?? '';

        $ch = curl_init($baseUrl . '/tokenized/checkout/execute');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . $token,
                'X-APP-Key: ' . ($credentials['app_key'] ?? ''),
            ],
            CURLOPT_POSTFIELDS => json_encode(['paymentID' => $paymentId]),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);

        $success = ($data['statusCode'] ?? '') === '0000' && ($data['transactionStatus'] ?? '') === 'Completed';

        return [
            'success'        => $success,
            'gateway_trx_id' => $data['trxID'] ?? '',
            'amount'         => $data['amount'] ?? null,
            'status'         => $success ? 'completed' : 'failed',
        ];
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'verification' => true,
            default => false,
        };
    }

    private function getToken(string $baseUrl, array $credentials): string
    {
        $ch = curl_init($baseUrl . '/tokenized/checkout/token/grant');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'username: ' . ($credentials['username'] ?? ''),
                'password: ' . ($credentials['password'] ?? ''),
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'app_key'    => $credentials['app_key'] ?? '',
                'app_secret' => $credentials['app_secret'] ?? '',
            ]),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);

        return $data['id_token'] ?? '';
    }
}
