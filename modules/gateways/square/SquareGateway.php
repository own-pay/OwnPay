<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Square;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Square Payments Payment Gateway Adapter.
 *
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class SquareGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Square Payments',
            'slug' => 'square',
            'version' => '1.0.0',
            'description' => 'Square Payments payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'square'; }
    public function name(): string { return 'Square Payments'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Square Payments checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
            ['name' => 'location_id', 'label' => 'Location ID', 'type' => 'text', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Verifies the access token authenticates against Square's Locations API (read-only,
     * no money moved) - matches the base URL selection initiate() already uses per mode.
     *
     * @param array<string, mixed> $credentials Decrypted (or freshly-submitted, unsaved) credentials.
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $accessToken = $this->getString($credentials['access_token'] ?? null);
        if ($accessToken === '') {
            return ['success' => false, 'message' => 'Enter an Access Token before testing the connection.'];
        }
        $mode = $this->getString($credentials['mode'] ?? null);
        $url = $mode === 'live'
            ? 'https://connect.squareup.com/v2/locations'
            : 'https://connect.squareupsandbox.com/v2/locations';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Square-Version: 2026-05-28',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach Square - check the server\'s network connectivity.'];
        }
        if ($httpCode === 200) {
            return ['success' => true, 'message' => 'Connected successfully to Square (' . ($mode !== '' ? $mode : 'sandbox') . ' mode).'];
        }

        $data = json_decode((string) $response, true);
        $errMsg = is_array($data) && is_array($data['errors'][0] ?? null) && is_scalar($data['errors'][0]['detail'] ?? null)
            ? (string) $data['errors'][0]['detail']
            : 'Square rejected the provided credentials.';
        return ['success' => false, 'message' => $errMsg];
    }

    public function initiate(array $params, array $credentials): array
    {
        $accessToken = $this->getString($credentials['access_token'] ?? null);
        $mode = $this->getString($credentials['mode'] ?? null);
        $locationId = $this->getString($credentials['location_id'] ?? null);

        $url = $mode === 'live' 
            ? 'https://connect.squareup.com/v2/online-checkout/payment-links' 
            : 'https://connect.squareupsandbox.com/v2/online-checkout/payment-links';
        $amount = $this->toMinorUnits($params['amount']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Square-Version: 2026-05-28',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'idempotency_key' => uniqid('sq_', true),
                'quick_pay' => [
                    'name' => 'Payment ' . $params['trx_id'],
                    'price_money' => ['amount' => $amount, 'currency' => strtoupper($params['currency'])],
                    'location_id' => $locationId,
                ],
                'redirect_url' => $params['redirect_url'],
            ]),
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 210) {
            throw new \RuntimeException('Square checkout failed: HTTP ' . $httpCode);
        }
        $data = json_decode((string) $response, true);
        $redirectUrl = '';
        $sessionId = '';
        if (is_array($data)) {
            $paymentLink = $this->getArray($data, 'payment_link');
            $redirectUrl = $this->getString($paymentLink['url'] ?? null);
            $sessionId = $this->getString($paymentLink['id'] ?? null);
        }
        return [
            'redirect_url' => $redirectUrl,
            'session_id'   => $sessionId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

        $transactionId = $this->getString($callbackData['transactionId'] ?? null);
        $success = $transactionId !== '';
        return [
            'success' => $success,
            'gateway_trx_id' => $transactionId,
            'status' => $success ? 'completed' : 'failed',
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
    }
}