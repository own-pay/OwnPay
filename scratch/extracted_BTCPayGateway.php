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
final class btcpayGateway implements PluginInterface, GatewayAdapterInterface
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

    public function register(EventManager , Container ): void {}
    public function boot(Container ): void {}
    public function deactivate(Container ): void {}
    public function uninstall(Container ): void {}
    public function capabilities(): arr
<truncated 1773 bytes>
ponse = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);

        return [
            'redirect_url' => (string) ($data['checkoutLink'] ?? ''),
            'session_id'   => (string) ($data['id'] ?? ''),
        ];
    }

    public function verify(array , array ): array
    {
        $invoiceId = (string) ($callbackData['invoice_id'] ?? $callbackData['id'] ?? '');
        $serverUrl = rtrim($credentials['server_url'], '/');
        $url = "{$serverUrl}/api/v1/stores/" . urlencode($credentials['store_id']) . "/invoices/{$invoiceId}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: token ' . $credentials['api_key']],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);

        $status = (string) ($data['status'] ?? '');
        $success = in_array($status, ['Settled', 'Processing']);
        return [
            'success'        => $success,
            'gateway_trx_id' => $invoiceId,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => (string) ($data['metadata']['orderId'] ?? ''),
        ];
    }

    public function verifyWebhook(string , array , array ): bool
    {
        $webhookSecret = $credentials['webhook_secret'] ?? '';
        if ($webhookSecret === '') return true;
        $sigHeader = $headers['Btcpay-Sig'] ?? $headers['btcpay-sig'] ?? '';
        $computedSig = 'sha256=' . hash_hmac('sha256', $rawBody, $webhookSecret);
        return hash_equals($computedSig, $sigHeader);
    }
}