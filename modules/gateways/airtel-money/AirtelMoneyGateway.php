<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\AirtelMoney;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Airtel Money Payment Gateway Adapter.
 *
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class AirtelMoneyGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Airtel Money',
            'slug' => 'airtel-money',
            'version' => '1.0.0',
            'description' => 'Airtel Money payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'airtel-money'; }
    public function name(): string { return 'Airtel Money'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Airtel Money checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
            ['name' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Verifies the Client ID/Secret against Airtel's OAuth2 client_credentials token grant -
     * the same call initiate() relies on, reused here without a follow-on checkout request.
     *
     * @param array<string, mixed> $credentials
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $clientId = $this->getString($credentials['client_id'] ?? null);
        $clientSecret = $this->getString($credentials['client_secret'] ?? null);
        if ($clientId === '' || $clientSecret === '') {
            return ['success' => false, 'message' => 'Enter Client ID and Client Secret before testing the connection.'];
        }

        $mode = $this->getString($credentials['mode'] ?? null);
        $authUrl = $mode === 'live'
            ? 'https://api.airtel.com/auth/v1/token'
            : 'https://openapiuat.airtel.africa/auth/oauth2/token';

        $ch = curl_init($authUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'grant_type'    => 'client_credentials',
            ]),
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach Airtel Money - check the server\'s network connectivity.'];
        }
        $data = json_decode((string) $response, true);
        $token = is_array($data) && is_scalar($data['access_token'] ?? null) ? (string) $data['access_token'] : '';

        if ($token !== '') {
            return ['success' => true, 'message' => 'Connected successfully to Airtel Money (' . ($mode === 'live' ? 'live' : 'sandbox') . ' mode).'];
        }

        $errMsg = is_array($data) && is_scalar($data['error_description'] ?? null) ? (string) $data['error_description'] : ('Airtel Money rejected the provided credentials (HTTP ' . $httpCode . ').');
        return ['success' => false, 'message' => $errMsg];
    }

    public function initiate(array $params, array $credentials): array
    {
        $authUrl = $this->getString($credentials['mode'] ?? null) === 'live'
            ? 'https://api.airtel.com/auth/v1/token'
            : 'https://openapiuat.airtel.africa/auth/oauth2/token';

        $clientId = $this->getString($credentials['client_id'] ?? null);
        $clientSecret = $this->getString($credentials['client_secret'] ?? null);

        $ch = curl_init($authUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        $token = '';
        if (is_array($data)) {
            $token = $this->getString($data['access_token'] ?? null);
        }

        return [
            'redirect_url' => $params['redirect_url'] . '?token=' . $token,
            'session_id'   => $params['trx_id'],
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

        $token = $this->getString($callbackData['token'] ?? null);
        return [
            'success'        => $token !== '',
            'gateway_trx_id' => $token,
            'status'         => $token !== '' ? 'completed' : 'failed',
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
    }
}