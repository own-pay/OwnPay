<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\BTCPay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * BTCPay Server Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class BTCPayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'BTCPay Server',
            'slug' => 'btcpay',
            'version' => '1.0.0',
            'description' => 'BTCPay Server payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'btcpay'; }
    public function name(): string { return 'BTCPay Server'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'BTCPay Server checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            [
                'name' => 'server_url',
                'label' => 'BTCPay Server URL',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'api_key',
                'label' => 'API Key (Greenfield)',
                'type' => 'password',
                'required' => true,
            ],
            [
                'name' => 'store_id',
                'label' => 'Store ID',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'webhook_secret',
                'label' => 'Webhook Secret',
                'type' => 'password',
                'required' => false,
            ],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $storeId = $this->getString($credentials['store_id'] ?? null);
        $apiKey = $this->getString($credentials['api_key'] ?? null);
        $serverUrl = rtrim($this->getString($credentials['server_url'] ?? null), '/');
        $url = "{$serverUrl}/api/v1/stores/" . urlencode($storeId) . "/invoices";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: token ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'amount' => (float)$params['amount'],
                'currency' => strtoupper($params['currency']),
                'metadata' => ['orderId' => $params['trx_id']],
                'checkout' => ['redirectUrl' => $params['redirect_url']]
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Gateway API error: Invalid response');
        }

        return [
            'redirect_url' => $this->getString($data['checkoutLink'] ?? null),
            'session_id'   => $this->getString($data['id'] ?? null),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $storeId = $this->getString($credentials['store_id'] ?? null);
        $apiKey = $this->getString($credentials['api_key'] ?? null);
        $invoiceId = $this->getString($callbackData['invoice_id'] ?? $callbackData['id'] ?? null);
        $serverUrl = rtrim($this->getString($credentials['server_url'] ?? null), '/');
        $url = "{$serverUrl}/api/v1/stores/" . urlencode($storeId) . "/invoices/{$invoiceId}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: token ' . $apiKey],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['success' => false, 'gateway_trx_id' => '', 'amount' => '', 'status' => 'failed'];
        }

        $status = $this->getString($data['status'] ?? null);
        $success = in_array($status, ['Settled', 'Processing']);
        $metadata = $this->getArray($data, 'metadata');
        return [
            'success'        => $success,
            'gateway_trx_id' => $invoiceId,
            'amount'         => '',
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $this->getString($metadata['orderId'] ?? null),
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $webhookSecret = $this->getString($credentials['webhook_secret'] ?? null);
        if ($webhookSecret === '') return true;
        $sigHeader = $this->getString($headers['Btcpay-Sig'] ?? $headers['btcpay-sig'] ?? null);
        $computedSig = 'sha256=' . hash_hmac('sha256', $rawBody, $webhookSecret);
        return hash_equals($computedSig, $sigHeader);
    }
}