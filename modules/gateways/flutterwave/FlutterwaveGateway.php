<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Flutterwave;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Flutterwave Payment Gateway Adapter.
 *
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class FlutterwaveGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Flutterwave',
            'slug' => 'flutterwave',
            'version' => '1.0.0',
            'description' => 'Flutterwave payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'flutterwave'; }
    public function name(): string { return 'Flutterwave'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Flutterwave checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'public_key', 'label' => 'Public Key', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'secret_hash', 'label' => 'Webhook Secret Hash', 'type' => 'password', 'required' => false],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? null);
        $trxId = $params['trx_id'];
        $amountValue = number_format((float) $params['amount'], 2, '.', '');
        $currency = strtoupper($params['currency']);
        $redirectUrl = $params['redirect_url'];

        $ch = curl_init('https://api.flutterwave.com/v3/payments');
        if (!$ch) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'amount' => $amountValue,
                'currency' => $currency,
                'redirect_url' => $redirectUrl,
                'customer' => ['email' => 'customer@ownpay.test', 'name' => 'Customer'],
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }

        $resData = $this->getArray($data, 'data');
        $link = $this->getString($resData['link'] ?? null);

        return [
            'redirect_url' => $link,
            'session_id'   => $trxId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $transactionId = $this->getString($callbackData['transaction_id'] ?? $callbackData['id'] ?? null);
        if ($transactionId === '') {
            return [
                'success' => false,
                'status'  => 'failed',
            ];
        }

        $secretKey = $this->getString($credentials['secret_key'] ?? null);
        $ch = curl_init("https://api.flutterwave.com/v3/transactions/{$transactionId}/verify");
        if (!$ch) {
            return [
                'success' => false,
                'status'  => 'failed',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }

        $resData = $this->getArray($data, 'data');
        $status = $this->getString($resData['status'] ?? null);
        $success = $status === 'successful';
        $amount = $this->getString($resData['amount'] ?? null);
        $txRef = $this->getString($resData['tx_ref'] ?? null);

        return [
            'success'        => $success,
            'gateway_trx_id' => $transactionId,
            'amount'         => $amount,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $txRef,
        ];
    }

    /**
     * Verifies the Secret Key authenticates against Flutterwave's Balances API (read-only)
     * without creating any charge.
     *
     * @param array<string, mixed> $credentials
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $secretKey = is_scalar($credentials['secret_key'] ?? null) ? (string) $credentials['secret_key'] : '';
        if ($secretKey === '') {
            return ['success' => false, 'message' => 'Enter the Secret Key before testing the connection.'];
        }

        $ch = curl_init('https://api.flutterwave.com/v3/balances');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secretKey],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach Flutterwave - check the server\'s network connectivity.'];
        }

        $data = json_decode((string) $response, true);
        if ($httpCode === 200 && is_array($data) && ($data['status'] ?? '') === 'success') {
            $mode = str_starts_with($secretKey, 'FLWSECK_TEST') ? 'test' : 'live';
            return ['success' => true, 'message' => "Connected successfully to Flutterwave ({$mode} mode)."];
        }

        $errMsg = is_array($data) && is_scalar($data['message'] ?? null) ? (string) $data['message'] : 'Flutterwave rejected the provided Secret Key.';
        return ['success' => false, 'message' => $errMsg];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $expectedHash = $this->getString($credentials['secret_hash'] ?? null);
        if ($expectedHash === '') {
            return true;
        }
        $sigHeader = $this->getString($headers['Verif-Hash'] ?? $headers['verif-hash'] ?? null);
        return hash_equals($expectedHash, $sigHeader);
    }
}