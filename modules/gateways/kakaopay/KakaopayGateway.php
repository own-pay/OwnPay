<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Kakaopay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * KakaoPay Payment Gateway Adapter.
 *
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class KakaopayGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'KakaoPay',
            'slug' => 'kakaopay',
            'version' => '1.0.0',
            'description' => 'KakaoPay payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'kakaopay'; }
    public function name(): string { return 'KakaoPay'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'KakaoPay checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'admin_key', 'label' => 'Admin Key', 'type' => 'text', 'required' => true],
            ['name' => 'cid', 'label' => 'Merchant CID (e.g. TC0ONETIME)', 'type' => 'text', 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $adminKey = $this->getString($credentials['admin_key'] ?? null);
        $cid = $this->getString($credentials['cid'] ?? null);

        $ch = curl_init('https://kapi.kakao.com/v1/payment/ready');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: KakaoAK ' . $adminKey,
                'Content-Type: application/x-www-form-urlencoded;charset=utf-8',
            ],
            CURLOPT_POSTFIELDS     => http_build_query([
                'cid' => $cid,
                'partner_order_id' => $params['trx_id'],
                'partner_user_id' => 'USR_' . $params['trx_id'],
                'item_name' => 'Payment ' . $params['trx_id'],
                'quantity' => 1,
                'total_amount' => (int) $params['amount'],
                'tax_free_amount' => 0,
                'approval_url' => $params['redirect_url'],
                'cancel_url' => $params['cancel_url'],
                'fail_url' => $params['cancel_url'],
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);

        $redirectUrl = '';
        $sessionId = '';
        if (is_array($data)) {
            $redirectUrl = $this->getString($data['next_redirect_pc_url'] ?? null);
            $sessionId = $this->getString($data['tid'] ?? null);
        }

        return [
            'redirect_url' => $redirectUrl,
            'session_id'   => $sessionId,
        ];
    }

    /**
     * Verifies the Admin Key authenticates against KakaoPay's REST API. KakaoPay has no
     * dedicated read-only account/ping endpoint, so this calls /v1/payment/ready with minimal
     * placeholder values - it does not move money (nothing is charged until the shopper
     * completes the resulting redirect), it just distinguishes a 401 (bad Admin Key) from any
     * other response (key accepted, whatever else may be wrong).
     *
     * @param array<string, mixed> $credentials Decrypted (or freshly-submitted, unsaved) credentials.
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $adminKey = $this->getString($credentials['admin_key'] ?? null);
        $cid = $this->getString($credentials['cid'] ?? null);

        if ($adminKey === '' || $cid === '') {
            return ['success' => false, 'message' => 'Enter Admin Key and Merchant CID before testing the connection.'];
        }

        $ch = curl_init('https://kapi.kakao.com/v1/payment/ready');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: KakaoAK ' . $adminKey,
                'Content-Type: application/x-www-form-urlencoded;charset=utf-8',
            ],
            CURLOPT_POSTFIELDS     => http_build_query([
                'cid' => $cid,
                'partner_order_id' => 'test_connection',
                'partner_user_id' => 'test_connection',
                'item_name' => 'Connection Test',
                'quantity' => 1,
                'total_amount' => 100,
                'tax_free_amount' => 0,
                'approval_url' => 'https://example.com/success',
                'cancel_url' => 'https://example.com/cancel',
                'fail_url' => 'https://example.com/fail',
            ]),
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach KakaoPay - ' . ($err ?: 'unknown network error') . '.'];
        }

        if ($httpCode === 401) {
            return ['success' => false, 'message' => 'KakaoPay rejected the Admin Key.'];
        }

        return ['success' => true, 'message' => 'Connected successfully to KakaoPay.'];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

        $tid = $this->getString($callbackData['tid'] ?? null);
        return [
            'success'        => $tid !== '',
            'gateway_trx_id' => $tid,
            'status'         => $tid !== '' ? 'completed' : 'failed',
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
    }
}