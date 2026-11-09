<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Oxapay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * OxaPay Crypto Gateway - PluginInterface + GatewayAdapterInterface.
 */
final class OxapayGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
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

    /**
     * Verifies the configured Merchant API Key authenticates against OxaPay's real API via
     * POST /merchants/balance - a free, read-only, account-scoped call that any valid key can make.
     *
     * @param array<string, mixed> $credentials Decrypted (or freshly-submitted, unsaved) credentials.
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $apiKeyRaw = $credentials['merchant_api_key'] ?? '';
        $apiKey = is_scalar($apiKeyRaw) ? (string) $apiKeyRaw : '';
        if ($apiKey === '') {
            return ['success' => false, 'message' => 'Enter the Merchant API Key before testing the connection.'];
        }

        $ch = curl_init('https://api.oxapay.com/merchants/balance');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode(['merchant' => $apiKey, 'currency' => 'USDT']),
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach OxaPay - check the server\'s network connectivity.'];
        }

        $data = json_decode((string) $response, true);
        $result = is_array($data) && is_scalar($data['result'] ?? null) ? (string) $data['result'] : '';
        if ($httpCode === 200 && $result === '100') {
            return ['success' => true, 'message' => 'Connected successfully to OxaPay.'];
        }
        $msg = is_array($data) && is_scalar($data['message'] ?? null) ? (string) $data['message'] : 'OxaPay rejected the provided Merchant API Key.';
        return ['success' => false, 'message' => $msg];
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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
        if (!is_array($result)) {
            throw new \RuntimeException('OxaPay initiation failed: Invalid JSON response');
        }

        $payLink = $result['payLink'] ?? '';
        $payLinkStr = is_scalar($payLink) ? (string) $payLink : '';

        if ($payLinkStr === '') {
            $msg = $result['message'] ?? 'Unknown error';
            $msgStr = is_scalar($msg) ? (string) $msg : 'Unknown error';
            throw new \RuntimeException('OxaPay initiation failed: ' . $msgStr);
        }

        $trackIdVal = $result['trackId'] ?? '';
        $trackIdStr = is_scalar($trackIdVal) ? (string) $trackIdVal : '';

        return [
            'redirect_url' => $payLinkStr,
            'session_id'   => $trackIdStr,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

        $status = $callbackData['status'] ?? '';
        $statusStr = is_scalar($status) ? (string) $status : '';

        $trackId = $callbackData['trackId'] ?? '';
        $trackIdStr = is_scalar($trackId) ? (string) $trackId : '';

        $orderId = $callbackData['orderId'] ?? '';
        $orderIdStr = is_scalar($orderId) ? (string) $orderId : '';

        $amount = $callbackData['amount'] ?? null;

        // If status is present, it's a webhook / IPN callback
        if ($statusStr !== '') {
            $isPaid = strtolower($statusStr) === 'paid';
            $res = [
                'success'        => $isPaid,
                'gateway_trx_id' => $trackIdStr,
                'status'         => $isPaid ? 'completed' : 'failed',
                'trx_id'         => $orderIdStr,
            ];
            if ($amount !== null) {
                $res['amount'] = is_scalar($amount) ? (string) $amount : '';
            }
            return $res;
        }

        // Return pending status if no webhook payload is present (e.g. initial redirect)
        $trxId = $callbackData['trx_id'] ?? $callbackData['paymentID'] ?? '';
        $trxIdStr = is_scalar($trxId) ? (string) $trxId : '';
        return [
            'success'        => false,
            'gateway_trx_id' => '',
            'status'         => 'pending',
            'trx_id'         => $trxIdStr,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $receivedSignatureStr = $headers['hmac'] ?? '';
        if ($receivedSignatureStr === '') {
            return false;
        }

        $apiKey = $credentials['merchant_api_key'] ?? '';
        $apiKeyStr = is_scalar($apiKey) ? (string) $apiKey : '';
        if ($apiKeyStr === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha512', $rawBody, $apiKeyStr);

        return hash_equals($receivedSignatureStr, $expectedSignature);
    }
}
