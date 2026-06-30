<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Coinbase;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Coinbase Commerce Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class CoinbaseGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Coinbase Commerce',
            'slug' => 'coinbase-commerce',
            'version' => '1.0.0',
            'description' => 'Coinbase Commerce payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'coinbase-commerce'; }
    public function name(): string { return 'Coinbase Commerce'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Coinbase Commerce checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            [
                'name' => 'api_key',
                'label' => 'API Key',
                'type' => 'password',
                'required' => true,
            ],
            [
                'name' => 'shared_secret',
                'label' => 'Shared Webhook Secret',
                'type' => 'password',
                'required' => false,
            ],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $apiKey = $this->getString($credentials['api_key'] ?? null);
        $ch = curl_init('https://api.commerce.coinbase.com/charges');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'X-CC-Api-Key: ' . $apiKey,
                'X-CC-Version: 2018-03-22',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'name' => 'Payment ' . $params['trx_id'],
                'description' => 'Payment Reference ' . $params['trx_id'],
                'local_price' => [
                    'amount' => number_format((float)$params['amount'], 2, '.', ''),
                    'currency' => strtoupper($params['currency']),
                ],
                'pricing_type' => 'fixed_price',
                'redirect_url' => $params['redirect_url'],
                'cancel_url' => $params['cancel_url'],
                'metadata' => ['trx_id' => $params['trx_id']]
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Gateway API error: Invalid response');
        }

        $dataData = $this->getArray($data, 'data');
        return [
            'redirect_url' => $this->getString($dataData['hosted_url'] ?? null),
            'session_id'   => $this->getString($dataData['code'] ?? null),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $code = $this->getString($callbackData['code'] ?? null);
        if ($code === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'amount' => '', 'status' => 'failed'];
        }

        $apiKey = $this->getString($credentials['api_key'] ?? null);
        $ch = curl_init("https://api.commerce.coinbase.com/charges/{$code}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'X-CC-Api-Key: ' . $apiKey,
                'X-CC-Version: 2018-03-22',
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['success' => false, 'gateway_trx_id' => '', 'amount' => '', 'status' => 'failed'];
        }

        $dataData = $this->getArray($data, 'data');
        $timeline = $this->getArray($dataData, 'timeline');
        $success = false;
        foreach ($timeline as $step) {
            if (is_array($step) && $this->getString($step['status'] ?? null) === 'COMPLETED') {
                $success = true;
                break;
            }
        }

        $metadata = $this->getArray($dataData, 'metadata');
        return [
            'success'        => $success,
            'gateway_trx_id' => $code,
            'amount'         => '',
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $this->getString($metadata['trx_id'] ?? null),
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $sharedSecret = $this->getString($credentials['shared_secret'] ?? null);
        if ($sharedSecret === '') return true;
        $sigHeader = $this->getString($headers['X-Cc-Webhook-Signature'] ?? $headers['x-cc-webhook-signature'] ?? null);
        $computedSig = hash_hmac('sha256', $rawBody, $sharedSecret);
        return hash_equals($computedSig, $sigHeader);
    }
}