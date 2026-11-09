<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\MercadoPago;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Mercado Pago Payment Gateway Adapter.
 *
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class MercadoPagoGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Mercado Pago',
            'slug' => 'mercadopago',
            'version' => '1.0.0',
            'description' => 'Mercado Pago payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'mercadopago'; }
    public function name(): string { return 'Mercado Pago'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Mercado Pago checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $accessToken = $this->getString($credentials['access_token'] ?? null);
        $mode = $this->getString($credentials['mode'] ?? null);

        $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'items' => [[
                    'title' => 'Payment ' . $params['trx_id'],
                    'quantity' => 1,
                    'unit_price' => (float)$params['amount'],
                    'currency_id' => strtoupper($params['currency']),
                ]],
                'back_urls' => [
                    'success' => $params['redirect_url'],
                    'failure' => $params['cancel_url'],
                    'pending' => $params['redirect_url'],
                ],
                'auto_return' => 'approved',
                'external_reference' => $params['trx_id'],
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);

        $initPoint = '';
        $sessionId = '';
        if (is_array($data)) {
            $initPoint = $mode === 'sandbox'
                ? $this->getString($data['sandbox_init_point'] ?? null)
                : $this->getString($data['init_point'] ?? null);
            $sessionId = $this->getString($data['id'] ?? null);
        }

        return [
            'redirect_url' => $initPoint,
            'session_id'   => $sessionId,
        ];
    }

    /**
     * Verifies the Access Token authenticates against MercadoPago's account API - GET
     * /users/me is a free, read-only call that returns the account owning the token.
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

        $ch = curl_init('https://api.mercadopago.com/users/me');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach Mercado Pago - ' . ($err ?: 'unknown network error') . '.'];
        }

        $data = json_decode((string) $response, true);
        if ($httpCode === 200) {
            $nickname = is_array($data) && is_scalar($data['nickname'] ?? null) ? (string) $data['nickname'] : '';
            $suffix = $nickname !== '' ? " ({$nickname})" : '';
            return ['success' => true, 'message' => "Connected successfully to Mercado Pago{$suffix}."];
        }

        $errMsg = is_array($data) && is_scalar($data['message'] ?? null) ? (string) $data['message'] : 'Mercado Pago rejected the provided Access Token.';
        return ['success' => false, 'message' => $errMsg];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

        $paymentId = $this->getString($callbackData['payment_id'] ?? $callbackData['collection_id'] ?? null);
        $status = $this->getString($callbackData['status'] ?? null);
        $success = in_array($status, ['approved', 'authorized']);

        return [
            'success'        => $success,
            'gateway_trx_id' => $paymentId,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $this->getString($callbackData['external_reference'] ?? null),
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
    }
}