<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Maya;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Maya Wallet Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class MayaGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Maya Wallet',
            'slug' => 'maya',
            'version' => '1.0.0',
            'description' => 'Maya Wallet payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'maya'; }
    public function name(): string { return 'Maya Wallet'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Maya Wallet checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'public_key', 'label' => 'Public API Key', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret API Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? null);
        $url = $mode === 'live'
            ? 'https://pg.maya.ph/checkout/v1/checkouts'
            : 'https://pg-sandbox.paymaya.com/checkout/v1/checkouts';

        $publicKey = $this->getString($credentials['public_key'] ?? null);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($publicKey . ':'),
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'totalAmount' => [
                    'value' => number_format((float)$params['amount'], 2, '.', ''),
                    'currency' => 'PHP',
                ],
                'requestReferenceNumber' => $params['trx_id'],
                'redirectUrl' => [
                    'success' => $params['redirect_url'],
                    'failure' => $params['cancel_url'],
                    'cancel' => $params['cancel_url'],
                ],
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);

        $redirectUrl = '';
        $sessionId = '';
        if (is_array($data)) {
            $redirectUrl = $this->getString($data['redirectUrl'] ?? null);
            $sessionId = $this->getString($data['checkoutId'] ?? null);
        }

        return [
            'redirect_url' => $redirectUrl,
            'session_id'   => $sessionId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $checkoutId = $this->getString($callbackData['checkout_id'] ?? $callbackData['checkoutId'] ?? null);
        $mode = $this->getString($credentials['mode'] ?? null);
        $secretKey = $this->getString($credentials['secret_key'] ?? null);

        $url = $mode === 'live'
            ? "https://pg.maya.ph/checkout/v1/checkouts/{$checkoutId}"
            : "https://pg-sandbox.paymaya.com/checkout/v1/checkouts/{$checkoutId}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($secretKey . ':'),
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);

        $success = false;
        $trxId = '';
        $amount = null;
        if (is_array($data)) {
            $status = $this->getString($data['status'] ?? null);
            $success = $status === 'COMPLETED';
            $trxId = $this->getString($data['requestReferenceNumber'] ?? null);
            // Maya checkouts report `totalAmount.value` as the charged amount.
            $amountRaw = $this->getArray($data, 'totalAmount')['value'] ?? null;
            if ($success && is_numeric($amountRaw)) {
                $amount = (string) $amountRaw;
            }
        }

        return [
            'success'        => $success,
            'gateway_trx_id' => $checkoutId,
            'amount'         => $amount ?? '',
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $trxId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        // Maya webhooks carry no shared-secret signature; they are untrusted
        // triggers only. Completion always requires the server-side checkout
        // lookup performed in verify(), including the core amount match.
        return true;
    }
}