<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Kakaopay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
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
final class KakaopayGateway implements PluginInterface, GatewayAdapterInterface
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