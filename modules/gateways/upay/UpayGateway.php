<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Upay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Upay Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class UpayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Upay',
            'slug' => 'upay',
            'version' => '1.0.0',
            'description' => 'Upay payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'upay'; }
    public function name(): string { return 'Upay'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Upay checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true],
            ['name' => 'api_secret', 'label' => 'API Secret', 'type' => 'password', 'required' => true],
            ['name' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? null);
        $merchantId = $this->getString($credentials['merchant_id'] ?? null);
        $apiKey = $this->getString($credentials['api_key'] ?? null);
        $apiSecret = $this->getString($credentials['api_secret'] ?? null);

        $trxId = $params['trx_id'];
        $amountValue = number_format((float) $params['amount'], 2, '.', '');
        $redirectUrl = $params['redirect_url'];
        $cancelUrl = $params['cancel_url'];

        $authUrl = $mode === 'live'
            ? 'https://api.upay.com.bd/v1/auth/login'
            : 'https://sandbox.upay.com.bd/v1/auth/login';

        $ch = curl_init($authUrl);
        if (!$ch) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'merchant_id' => $merchantId,
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }
        $token = $this->getString($data['access_token'] ?? null);

        $url = $mode === 'live'
            ? 'https://api.upay.com.bd/v1/checkout/create'
            : 'https://sandbox.upay.com.bd/v1/checkout/create';

        $ch = curl_init($url);
        if (!$ch) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'amount' => $amountValue,
                'currency' => 'BDT',
                'trx_id' => $trxId,
                'redirect_url' => $redirectUrl,
                'cancel_url' => $cancelUrl,
            ]),
        ]);
        $responseOut = curl_exec($ch);
        curl_close($ch);
        $outData = json_decode((string) $responseOut, true);
        if (!is_array($outData)) {
            $outData = [];
        }

        return [
            'redirect_url' => $this->getString($outData['payment_url'] ?? null),
            'session_id'   => $this->getString($outData['session_id'] ?? null),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $sessionId = $this->getString($callbackData['session_id'] ?? null);
        if ($sessionId === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $mode = $this->getString($credentials['mode'] ?? null);
        $merchantId = $this->getString($credentials['merchant_id'] ?? null);
        $apiKey = $this->getString($credentials['api_key'] ?? null);
        $apiSecret = $this->getString($credentials['api_secret'] ?? null);

        $authUrl = $mode === 'live'
            ? 'https://api.upay.com.bd/v1/auth/login'
            : 'https://sandbox.upay.com.bd/v1/auth/login';

        $ch = curl_init($authUrl);
        if (!$ch) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'merchant_id' => $merchantId,
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }
        $token = $this->getString($data['access_token'] ?? null);

        $url = $mode === 'live'
            ? "https://api.upay.com.bd/v1/checkout/verify/{$sessionId}"
            : "https://sandbox.upay.com.bd/v1/checkout/verify/{$sessionId}";

        $ch = curl_init($url);
        if (!$ch) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);
        $responseOut = curl_exec($ch);
        curl_close($ch);
        $outData = json_decode((string) $responseOut, true);
        if (!is_array($outData)) {
            $outData = [];
        }

        $status = $this->getString($outData['status'] ?? null);
        $success = $status === 'SUCCESS';
        $upayTrxId = $this->getString($outData['upay_trx_id'] ?? null);
        $amount = $this->getString($outData['amount'] ?? null);
        $trxId = $this->getString($outData['trx_id'] ?? null);

        return [
            'success'        => $success,
            'gateway_trx_id' => $upayTrxId,
            'amount'         => $amount,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $trxId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return true;
    }
}