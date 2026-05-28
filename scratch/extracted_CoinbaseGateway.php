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
final class coinbase-commerceGateway implements PluginInterface, GatewayAdapterInterface
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

    public function register(EventManager , Container ): void {}
    public function boot(Container ): void {}
    public function deactivate(Container ): void {}
    public function uninst
<truncated 2087 bytes>
'] ?? ''),
        ];
    }

    public function verify(array , array ): array
    {
        $code = (string) ($callbackData['code'] ?? '');
        if ($code === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $apiKey = $credentials['api_key'];
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

        $timeline = $data['data']['timeline'] ?? [];
        $success = false;
        foreach ($timeline as $step) {
            if (($step['status'] ?? '') === 'COMPLETED') {
                $success = true;
                break;
            }
        }

        return [
            'success'        => $success,
            'gateway_trx_id' => $code,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => (string) ($data['data']['metadata']['trx_id'] ?? ''),
        ];
    }

    public function verifyWebhook(string , array , array ): bool
    {
        $sharedSecret = $credentials['shared_secret'] ?? '';
        if ($sharedSecret === '') return true;
        $sigHeader = $headers['X-Cc-Webhook-Signature'] ?? $headers['x-cc-webhook-signature'] ?? '';
        $computedSig = hash_hmac('sha256', $rawBody, $sharedSecret);
        return hash_equals($computedSig, $sigHeader);
    }
}