<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Oxapay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * OxaPay Crypto Gateway — PluginInterface + GatewayAdapterInterface.
 */
final class OxapayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name'        => 'OxaPay Crypto',
            'slug'        => 'oxapay',
            'version'     => '1.0.0',
            'description' => 'Accept OxaPay Crypto payments directly from customers.',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string { return 'oxapay'; }
    public function name(): string { return 'OxaPay Crypto'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Accept OxaPay Crypto payments directly from customers.'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array
    {
        return [Capability::GATEWAY];
    }

    public function fields(): array
    {
        return [
            [
                'name'     => 'merchant_api_key',
                'label'    => 'Merchant API Key',
                'type'     => 'text',
                'required' => true
            ],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $url = 'https://api.oxapay.com/merchants/request';
        $apiKey = $credentials['merchant_api_key'] ?? '';
        $trxId = $params['trx_id'];

        $redirectUrl = $params['redirect_url'];

        // Construct webhook/IPN URL dynamically from the redirect URL
        $parsed = parse_url($redirectUrl);
        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $webhookUrl = "{$scheme}://{$host}{$port}/webhook/oxapay";

        $postData = [
            'merchant'    => $apiKey,
            'amount'      => number_format((float) $params['amount'], 2, '.', ''),
            'currency'    => strtoupper($params['currency']),
            'orderId'     => $trxId,
            'returnUrl'   => $redirectUrl,
            'callbackUrl' => $webhookUrl,
            'lifeTime'    => 60,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($postData),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException('OxaPay API Error: HTTP ' . $httpCode);
        }

        $result = json_decode((string) $response, true);
        if (empty($result['payLink'])) {
            $msg = $result['message'] ?? 'Unknown error';
            throw new \RuntimeException('OxaPay initiation failed: ' . $msg);
        }

        return [
            'redirect_url' => $result['payLink'],
            'session_id'   => (string) ($result['trackId'] ?? ''),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $status = $callbackData['status'] ?? '';
        $trackId = $callbackData['trackId'] ?? '';
        $orderId = $callbackData['orderId'] ?? '';
        $amount = $callbackData['amount'] ?? null;

        // If status is present, it's a webhook / IPN callback
        if ($status !== '') {
            $isPaid = strtolower($status) === 'paid';
            $res = [
                'success'        => $isPaid,
                'gateway_trx_id' => (string) $trackId,
                'status'         => $isPaid ? 'completed' : 'failed',
                'order_id'       => $orderId,
            ];
            if ($amount !== null) {
                $res['amount'] = (string) $amount;
            }
            return $res;
        }

        // Return pending status if no webhook payload is present (e.g. initial redirect)
        $trxId = $callbackData['trx_id'] ?? $callbackData['paymentID'] ?? '';
        return [
            'success'        => false,
            'gateway_trx_id' => '',
            'status'         => 'pending',
            'trx_id'         => $trxId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $receivedSignature = $headers['hmac'] ?? '';
        if (empty($receivedSignature)) {
            return false;
        }

        $apiKey = $credentials['merchant_api_key'] ?? '';
        if (empty($apiKey)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha512', $rawBody, $apiKey);

        return hash_equals($receivedSignature, $expectedSignature);
    }
}
