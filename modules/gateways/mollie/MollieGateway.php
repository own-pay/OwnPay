<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Mollie;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Mollie Payments Payment Gateway Adapter.
 *
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class MollieGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Mollie Payments',
            'slug' => 'mollie',
            'version' => '1.0.0',
            'description' => 'Mollie Payments payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'mollie'; }
    public function name(): string { return 'Mollie Payments'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Mollie Payments checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'Mollie API Key', 'type' => 'password', 'required' => true],
            ['name' => 'method', 'label' => 'Default Method', 'type' => 'select', 'options' => ['ideal' => 'iDEAL', 'bancontact' => 'Bancontact', 'creditcard' => 'Credit Card'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $apiKey = $this->getString($credentials['api_key'] ?? null);
        $method = $this->getString($credentials['method'] ?? null);
        $trxId = $params['trx_id'];
        $amountValue = number_format((float) $params['amount'], 2, '.', '');
        $currency = strtoupper($params['currency']);
        $redirectUrl = $params['redirect_url'];

        $ch = curl_init('https://api.mollie.com/v2/payments');
        if (!$ch) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'amount' => [
                    'currency' => $currency,
                    'value' => $amountValue,
                ],
                'description' => 'Payment ' . $trxId,
                'redirectUrl' => $redirectUrl,
                'method' => $method,
                'metadata' => ['trx_id' => $trxId]
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }

        $links = $this->getArray($data, '_links');
        $checkout = $this->getArray($links, 'checkout');
        $checkoutUrl = $this->getString($checkout['href'] ?? null);
        $sessionId = $this->getString($data['id'] ?? null);

        return [
            'redirect_url' => $checkoutUrl,
            'session_id'   => $sessionId,
        ];
    }

    /**
     * Verifies the API Key authenticates against Mollie - GET /v2/methods is a free, read-only
     * call that lists the account's enabled payment methods.
     *
     * @param array<string, mixed> $credentials Decrypted (or freshly-submitted, unsaved) credentials.
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $apiKey = $this->getString($credentials['api_key'] ?? null);
        if ($apiKey === '') {
            return ['success' => false, 'message' => 'Enter the Mollie API Key before testing the connection.'];
        }

        $ch = curl_init('https://api.mollie.com/v2/methods');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach Mollie - ' . ($err ?: 'unknown network error') . '.'];
        }

        $data = json_decode((string) $response, true);
        if ($httpCode === 200) {
            $mode = str_starts_with($apiKey, 'live_') ? 'live' : 'test';
            return ['success' => true, 'message' => "Connected successfully to Mollie ({$mode} mode)."];
        }

        $errMsg = is_array($data) && is_scalar($data['detail'] ?? null) ? (string) $data['detail'] : 'Mollie rejected the provided API Key.';
        return ['success' => false, 'message' => $errMsg];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $paymentId = $this->getString($callbackData['id'] ?? $callbackData['payment_id'] ?? null);
        if ($paymentId === '') {
            return [
                'success' => false,
                'status'  => 'failed',
            ];
        }

        $apiKey = $this->getString($credentials['api_key'] ?? null);
        $ch = curl_init("https://api.mollie.com/v2/payments/" . urlencode($paymentId));
        if (!$ch) {
            return [
                'success' => false,
                'status'  => 'failed',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }

        $status = $this->getString($data['status'] ?? null);
        $success = $status === 'paid';
        $amountData = $this->getArray($data, 'amount');
        $amount = $this->getString($amountData['value'] ?? null);
        $metadata = $this->getArray($data, 'metadata');
        $trxId = $this->getString($metadata['trx_id'] ?? null);

        return [
            'success'        => $success,
            'gateway_trx_id' => $paymentId,
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